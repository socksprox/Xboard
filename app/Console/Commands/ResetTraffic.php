<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetTraffic extends Command
{
    protected $builder;

    protected $signature = 'reset:traffic';
    protected $description = 'Reset user traffic';

    public function __construct()
    {
        parent::__construct();
        $this->builder = User::where('expired_at', '!=', null)
            ->where('expired_at', '>', time());
    }

    public function handle()
    {
        // Sync admin setting to config for theme compatibility
        config(['v2board.reset_traffic_method' => admin_setting('reset_traffic_method', 0)]);

        ini_set('memory_limit', -1);

        $resetMethods = Plan::select(
            DB::raw("GROUP_CONCAT(id) as plan_ids"),
            DB::raw("reset_traffic_method as method")
        )->groupBy('reset_traffic_method')->get()->toArray();

        foreach ($resetMethods as $resetMethod) {
            $planIds = explode(',', $resetMethod['plan_ids']);

            switch (true) {
                case ($resetMethod['method'] === null):
                    $this->handlePlanReset(
                        $planIds,
                        admin_setting('reset_traffic_method', 0)
                    );
                    break;

                default:
                    $this->handlePlanReset($planIds, $resetMethod['method']);
                    break;
            }
        }
    }

    private function handlePlanReset(array $planIds, ?int $method): void
    {
        $builder = clone $this->builder;
        $builder->whereIn('plan_id', $planIds);

        switch ($method) {
            case 0:
                $this->resetByMonthFirstDay($builder);
                break;
            case 1:
                $this->resetByExpireDay($builder);
                break;
            case 3:
                $this->resetByYearFirstDay($builder);
                break;
            case 4:
                $this->resetByExpireYear($builder);
                break;
        }
    }

    // Unified user data fetcher
    private function getQualifiedUsers($builder): array
    {
        return $builder->with('plan')->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'transfer_enable' => $user->plan->transfer_enable * 1073741824
            ])->toArray();
    }

    private function resetByExpireYear($builder): void
    {
        if (date('m-d') !== date('m-d', $builder->first()->expired_at)) return;

        foreach ($this->getQualifiedUsers($builder) as $user) {
            User::where('id', $user['id'])->update([
                'transfer_enable' => $user['transfer_enable'],
                'u' => 0,
                'd' => 0
            ]);
        }
    }

    private function resetByYearFirstDay($builder): void
    {
        if (date('md') !== '0101') return;

        foreach ($this->getQualifiedUsers($builder) as $user) {
            User::where('id', $user['id'])->update([
                'transfer_enable' => $user['transfer_enable'],
                'u' => 0,
                'd' => 0
            ]);
        }
    }

    private function resetByMonthFirstDay($builder): void
    {
        if (date('d') !== '01') return;

        foreach ($this->getQualifiedUsers($builder) as $user) {
            User::where('id', $user['id'])->update([
                'transfer_enable' => $user['transfer_enable'],
                'u' => 0,
                'd' => 0
            ]);
        }
    }

    private function resetByExpireDay($builder): void
    {
        $lastDay = date('t');
        $today = date('d');

        $users = $this->getQualifiedUsers($builder)
            ->filter(function ($user) use ($today, $lastDay) {
                $expireDay = date('d', $user->expired_at);
                return $expireDay === $today || ($today === $lastDay && $expireDay >= $lastDay);
            });

        foreach ($users as $user) {
            User::where('id', $user['id'])->update([
                'transfer_enable' => $user['transfer_enable'],
                'u' => 0,
                'd' => 0
            ]);
        }
    }
}
