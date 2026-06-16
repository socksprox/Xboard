<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use App\Models\UserOffense;
use App\Models\UserRestriction;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OffenseService
{
    public const ACTION_NODE_SUSPEND = 'node_suspend';
    public const ACTION_STRICT_POOL_SUSPEND = 'strict_pool_suspend';
    public const ACTION_ALL_SERVERS_SUSPEND = 'all_servers_suspend';

    public const ACTIONS = [
        self::ACTION_NODE_SUSPEND,
        self::ACTION_STRICT_POOL_SUSPEND,
        self::ACTION_ALL_SERVERS_SUSPEND,
    ];

    public static function defaultTorrentPolicy(): array
    {
        return [
            'enabled' => true,
            'action' => self::ACTION_NODE_SUSPEND,
            'duration_hours' => 24,
        ];
    }

    public static function normalizePolicy(array $policy): array
    {
        $default = self::defaultTorrentPolicy();

        // Legacy escalation format (rules[]) → single action + duration.
        if (!isset($policy['action']) && !empty($policy['rules']) && is_array($policy['rules'])) {
            $rule = $policy['rules'][0] ?? [];
            $policy['action'] = $rule['action'] ?? $default['action'];
            $policy['duration_hours'] = $rule['duration_hours'] ?? $default['duration_hours'];
        }

        $action = (string) ($policy['action'] ?? $default['action']);
        if ($action === 'global_ban') {
            $action = self::ACTION_ALL_SERVERS_SUSPEND;
        }
        if (!in_array($action, self::ACTIONS, true)) {
            $action = $default['action'];
        }

        return [
            'enabled' => (bool) ($policy['enabled'] ?? $default['enabled']),
            'action' => $action,
            'duration_hours' => max(1, (int) ($policy['duration_hours'] ?? $default['duration_hours'])),
        ];
    }

    /**
     * @return array{offense_id: int, action_applied: string|null, restriction: array|null}
     */
    public function report(Server $server, int $userId, string $type, array $detail): array
    {
        if (!User::whereKey($userId)->exists()) {
            throw new \InvalidArgumentException('User does not exist');
        }

        $policy = $this->resolvePolicy($server);
        if (!$policy['enabled']) {
            throw new \InvalidArgumentException('Torrent offense policy is disabled');
        }

        return DB::transaction(function () use ($server, $userId, $type, $detail, $policy) {
            $offense = UserOffense::create([
                'user_id' => $userId,
                'server_id' => $server->id,
                'type' => $type,
                'detail' => $detail,
            ]);

            $action = $policy['action'];
            $restriction = $this->applyAction($userId, $server, $offense, $action, $policy['duration_hours']);

            $restrictionPayload = null;
            if ($restriction !== null) {
                $offense->action_applied = $action;
                $offense->restriction_id = $restriction->id;
                $offense->save();

                $restrictionPayload = [
                    'id' => $restriction->id,
                    'scope' => $restriction->scope,
                    'server_id' => $restriction->server_id,
                    'expires_at' => $restriction->expires_at,
                ];

                $this->syncRestrictionToNodes($userId, $restriction);
            }

            return [
                'offense_id' => $offense->id,
                'action_applied' => $offense->action_applied,
                'restriction' => $restrictionPayload,
            ];
        });
    }

    public function resolvePolicy(Server $server): array
    {
        $override = data_get($server->protocol_settings, 'torrent_offense_policy_override');
        if (is_array($override) && !empty($override)) {
            return self::normalizePolicy($override);
        }

        $raw = admin_setting('torrent_offense_policy');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::normalizePolicy($decoded);
            }
        }
        if (is_array($raw)) {
            return self::normalizePolicy($raw);
        }

        return self::defaultTorrentPolicy();
    }

    private function applyAction(
        int $userId,
        Server $server,
        UserOffense $offense,
        string $action,
        int $durationHours
    ): ?UserRestriction {
        return match ($action) {
            self::ACTION_NODE_SUSPEND => $this->createRestriction(
                $userId,
                UserRestriction::SCOPE_NODE,
                $server->id,
                "Torrent offense on server {$server->name}",
                $offense->id,
                $durationHours
            ),
            self::ACTION_STRICT_POOL_SUSPEND => $this->createRestriction(
                $userId,
                UserRestriction::SCOPE_STRICT_POOL,
                null,
                'Torrent offense (strict nodes)',
                $offense->id,
                $durationHours
            ),
            self::ACTION_ALL_SERVERS_SUSPEND => $this->createRestriction(
                $userId,
                UserRestriction::SCOPE_GLOBAL,
                null,
                'Torrent offense (all servers)',
                $offense->id,
                $durationHours
            ),
            default => null,
        };
    }

    private function createRestriction(
        int $userId,
        string $scope,
        ?int $serverId,
        string $reason,
        int $offenseId,
        int $durationHours
    ): UserRestriction {
        $restriction = UserRestriction::create([
            'user_id' => $userId,
            'scope' => $scope,
            'server_id' => $serverId,
            'reason' => $reason,
            'offense_id' => $offenseId,
            'expires_at' => time() + ($durationHours * 3600),
        ]);

        UserRestrictionService::clearUserCache($userId);

        return $restriction;
    }

    public function syncRestrictionToNodes(int $userId, UserRestriction $restriction): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }

        $serverIds = UserRestrictionService::affectedServerIds($restriction, $user->group_id);
        NodeSyncService::notifyUserRemovedFromServers($userId, $serverIds);
    }

    public function expireDueRestrictions(): int
    {
        $now = time();
        $expired = UserRestriction::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        $count = 0;
        foreach ($expired as $restriction) {
            $restriction->revoked_at = $now;
            $restriction->save();
            UserRestrictionService::clearUserCache((int) $restriction->user_id);

            $user = User::find($restriction->user_id);
            if ($user) {
                NodeSyncService::notifyUserChanged($user);
            }
            $count++;
        }

        if ($count > 0) {
            Log::info("[Offense] Expired {$count} user restriction(s)");
        }

        return $count;
    }
}
