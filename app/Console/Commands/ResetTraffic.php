<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetTraffic extends Command
{
    protected $signature = 'reset:traffic';
    protected $description = 'Reset user traffic';

    protected $trafficRatio = 1073741824; // 1GB in bytes

    public function handle()
    {
        // 1. Force sync with admin settings
        config(['v2board.reset_traffic_method' => admin_setting('reset_traffic_method', 0)]);

        // 2. Clear cached plans
        Plan::flushCache();

        // 3. Unified reset handler
        $this->processPlans();
    }

    private function processPlans()
    {
        Plan::groupBy('reset_traffic_method')
            ->selectRaw('GROUP_CONCAT(id) as plan_ids, reset_traffic_method')
            ->cursor()
            ->each(function ($planGroup) {
                $this->handlePlanGroup(
                    explode(',', $planGroup->plan_ids),
                    $planGroup->reset_traffic_method ?? admin_setting('reset_traffic_method', 0)
                );
            });
    }

    private function handlePlanGroup(array $planIds, ?int $method)
    {
        $users = User::whereIn('plan_id', $planIds)
            ->where('expired_at', '>', time())
            ->with('plan')
            ->lazy();

        match ($method) {
            0 => $this->monthlyReset($users),
            1 => $this->expiryDayReset($users),
            3 => $this->yearlyReset($users),
            4 => $this->yearlyExpiryReset($users),
            default => null
        };
    }

    // Reset Methods
    private function monthlyReset($users)
    {
        if (date('d') !== '01') return;

        $users->each(function ($user) {
            $this->updateUserTraffic($user);
        });
    }

    private function expiryDayReset($users)
    {
        $today = date('d');
        $lastDay = date('t');

        $users->each(function ($user) use ($today, $lastDay) {
            $expiryDay = date('d', $user->expired_at);
            if ($expiryDay === $today || ($today === $lastDay && $expiryDay >= $lastDay)) {
                $this->updateUserTraffic($user);
            }
        });
    }

    private function yearlyReset($users)
    {
        if (date('md') !== '0101') return;

        $users->each(function ($user) {
            $this->updateUserTraffic($user);
        });
    }

    private function yearlyExpiryReset($users)
    {
        if (date('md') !== date('md', $users->first()->expired_at)) return;

        $users->each(function ($user) {
            $this->updateUserTraffic($user);
        });
    }

    private function updateUserTraffic(User $user)
    {
        $user->update([
            'u' => 0,
            'd' => 0,
            'transfer_enable' => $user->plan->transfer_enable * $this->trafficRatio
        ]);
    }
}
