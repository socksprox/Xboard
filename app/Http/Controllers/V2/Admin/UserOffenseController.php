<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserOffense;
use App\Models\UserRestriction;
use App\Services\NodeSyncService;
use App\Services\OffenseService;
use App\Services\UserRestrictionService;
use Illuminate\Http\Request;

class UserOffenseController extends Controller
{
    public function fetchOffenses(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'server_id' => 'nullable|integer|min:1',
            'type' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:100',
        ]);

        $pageSize = (int) $request->input('page_size', 20);

        $builder = UserOffense::query()
            ->with(['user:id,email', 'server:id,name', 'restriction'])
            ->orderByDesc('id');

        if ($request->filled('user_id')) {
            $builder->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('server_id')) {
            $builder->where('server_id', (int) $request->input('server_id'));
        }
        if ($request->filled('type')) {
            $builder->where('type', $request->input('type'));
        }

        return $this->success($builder->paginate($pageSize));
    }

    public function fetchRestrictions(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|min:1',
            'active_only' => 'nullable|boolean',
        ]);

        $builder = UserRestriction::query()
            ->with(['server:id,name', 'offense'])
            ->where('user_id', (int) $request->input('user_id'))
            ->orderByDesc('id');

        if ($request->boolean('active_only', false)) {
            $now = time();
            $builder->whereNull('revoked_at')
                ->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                });
        }

        return $this->success($builder->get());
    }

    public function revokeRestriction(Request $request, OffenseService $offenseService)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
        ]);

        $restriction = UserRestriction::find($request->input('id'));
        if (!$restriction) {
            return $this->fail([404, 'Restriction not found']);
        }

        UserRestrictionService::revoke($restriction);

        $user = $restriction->user;
        if ($user) {
            NodeSyncService::notifyUserChanged($user);
        }

        return $this->success(true);
    }

    public function getTorrentPolicy()
    {
        $raw = admin_setting('torrent_offense_policy');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->success(['torrent_offense_policy' => OffenseService::normalizePolicy($decoded)]);
            }
        }

        return $this->success([
            'torrent_offense_policy' => is_array($raw)
                ? OffenseService::normalizePolicy($raw)
                : OffenseService::defaultTorrentPolicy(),
        ]);
    }

    public function saveTorrentPolicy(Request $request)
    {
        $request->validate([
            'torrent_offense_policy' => 'required|array',
            'torrent_offense_policy.enabled' => 'nullable|boolean',
            'torrent_offense_policy.action' => 'required|string|in:' . implode(',', OffenseService::ACTIONS),
            'torrent_offense_policy.duration_hours' => 'required|integer|min:1',
        ]);

        $policy = OffenseService::normalizePolicy($request->input('torrent_offense_policy'));

        admin_setting([
            'torrent_offense_policy' => json_encode($policy, JSON_UNESCAPED_UNICODE),
        ]);

        return $this->success(true);
    }
}
