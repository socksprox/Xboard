<?php

namespace App\Services;

use App\Models\Server;
use App\Services\TorrentModeService;
use App\Models\User;
use App\Models\UserRestriction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class UserRestrictionService
{
    private const CACHE_TTL = 60;

    /**
     * User IDs restricted from the given server (for bulk user list filtering).
     *
     * @return array<int, true>
     */
    public static function restrictedUserIdsForServer(int $serverId, iterable $userIds): array
    {
        $userIds = collect($userIds)->map(fn ($id) => (int) $id)->unique()->filter()->values()->all();
        if ($userIds === []) {
            return [];
        }

        $now = time();
        $restrictions = UserRestriction::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->get()
            ->groupBy('user_id');

        $result = [];
        foreach ($userIds as $userId) {
            foreach ($restrictions->get($userId, collect()) as $restriction) {
                if (self::restrictionCoversServer($restriction, $serverId)) {
                    $result[$userId] = true;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Whether the user is restricted from using the given server.
     */
    public static function isRestricted(int $userId, int $serverId): bool
    {
        if (User::whereKey($userId)->where('banned', 1)->exists()) {
            return true;
        }

        return isset(self::restrictedUserIdsForServer($serverId, [$userId])[$userId]);
    }

    /**
     * @return Collection<int, UserRestriction>
     */
    public static function getActiveRestrictionsForUser(int $userId): Collection
    {
        $cacheKey = "user_restrictions:active:{$userId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            $now = time();

            return UserRestriction::query()
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->get();
        });
    }

    public static function clearUserCache(int $userId): void
    {
        Cache::forget("user_restrictions:active:{$userId}");
    }

    public static function restrictionCoversServer(UserRestriction $restriction, int $serverId): bool
    {
        return match ($restriction->scope) {
            UserRestriction::SCOPE_GLOBAL => true,
            UserRestriction::SCOPE_NODE => (int) $restriction->server_id === $serverId,
            UserRestriction::SCOPE_STRICT_POOL => self::isStrictPoolServer($serverId),
            default => false,
        };
    }

    public static function isStrictPoolServer(int $serverId): bool
    {
        $server = Server::find($serverId);
        if (!$server || $server->torrent_mode === null) {
            return false;
        }

        return TorrentModeService::isStrict($server);
    }

    /**
     * Server IDs the user should be removed from for this restriction.
     *
     * @return int[]
     */
    public static function affectedServerIds(UserRestriction $restriction, ?int $userGroupId): array
    {
        if ($userGroupId === null) {
            return [];
        }

        $group = (string) $userGroupId;

        return match ($restriction->scope) {
            UserRestriction::SCOPE_GLOBAL => Server::query()
                ->whereJsonContains('group_ids', $group)
                ->pluck('id')
                ->all(),
            UserRestriction::SCOPE_NODE => $restriction->server_id
                ? [(int) $restriction->server_id]
                : [],
            UserRestriction::SCOPE_STRICT_POOL => Server::query()
                ->whereJsonContains('group_ids', $group)
                ->whereIn('torrent_mode', [
                    TorrentModeService::MODE_BLOCK,
                    TorrentModeService::MODE_REPORT,
                ])
                ->pluck('id')
                ->all(),
            default => [],
        };
    }

    public static function revoke(UserRestriction $restriction): void
    {
        if ($restriction->revoked_at !== null) {
            return;
        }

        $restriction->revoked_at = time();
        $restriction->save();
        self::clearUserCache((int) $restriction->user_id);
    }
}
