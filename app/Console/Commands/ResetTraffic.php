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
    protected $description = '流量清空';

    public function __construct()
    {
        parent::__construct();
        $this->builder = User::where('expired_at', '!=', NULL)
            ->where('expired_at', '>', time());
    }

    public function handle()
    {
        // Sync config with admin settings
        config(['v2board.reset_traffic_method' => admin_setting('reset_traffic_method', 0)]);

        ini_set('memory_limit', -1);

        $resetMethods = Plan::select(
            DB::raw("GROUP_CONCAT(`id`) as plan_ids"),
            DB::raw("reset_traffic_method as method")
        )->groupBy('reset_traffic_method')->get()->toArray();

        foreach ($resetMethods as $resetMethod) {
            $planIds = explode(',', $resetMethod['plan_ids']);
            switch (true) {
                case ($resetMethod['method'] === NULL):
                    $this->handleDefaultMethod($planIds);
                    break;

                default:
                    $this->handleSpecificMethod($planIds, $resetMethod['method']);
                    break;
            }
        }
    }

    private function handleDefaultMethod(array $planIds)
    {
        $method = config('v2board.reset_traffic_method', 0);
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

    private function handleSpecificMethod(array $planIds, int $method)
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

    // Keep existing reset methods unchanged
    private function resetByExpireYear($builder): void
    {
        $users = $builder->with('plan')->get();
        $usersToUpdate = [];
        foreach ($users as $user) {
            $expireDay = date('m-d', $user->expired_at);
            $today = date('m-d');
            if ($expireDay === $today) {
                $usersToUpdate[] = [
                    'id' => $user->id,
                    'transfer_enable' => $user->plan->transfer_enable
                ];
            }
        }

        foreach ($usersToUpdate as $userData) {
            User::where('id', $userData['id'])->update([
                'transfer_enable' => (intval($userData['transfer_enable']) * 1073741824),
                'u' => 0,
                'd' => 0
            ]);
        }
    }

    private function resetByYearFirstDay($builder): void
    {
        $users = $builder->with('plan')->get();
        $usersToUpdate = [];
        foreach ($users as $user) {
            if ((string) date('md') === '0101') {
                $usersToUpdate[] = [
                    'id' => $user->id,
                    'transfer_enable' => $user->plan->transfer_enable
                ];
            }
        }

        foreach ($usersToUpdate as $userData) {
            User::where('id', $userData['id'])->update([
                'transfer_enable' => (intval($userData['transfer_enable']) * 1073741824),
                'u' => 0,
                'd' => 0
            ]);
        }
    }

    private function resetByMonthFirstDay($builder): void
    {
        $users = $builder->with('plan')->get();
        $usersToUpdate = [];
        foreach ($users as $user) {
            if ((string) date('d') === '01') {
                $usersToUpdate[] = [
                    'id' => $user->id,
                    'transfer_enable' => $user->plan->transfer_enable
                ];
            }
        }

        foreach ($usersToUpdate as $userData) {
            User::where('id', $userData['id'])->update([
                'transfer_enable' => (intval($userData['transfer_enable']) * 1073741824),
                'u' => 0,
                'd' => 0
            ]);
        }
    }

    private function resetByExpireDay($builder): void
    {
        $lastDay = date('d', strtotime('last day of +0 months'));
        $today = date('d');
        $users = $builder->with('plan')->get();
        $usersToUpdate = [];

        foreach ($users as $user) {
            $expireDay = date('d', $user->expired_at);
            if ($expireDay === $today || ($today === $lastDay && $expireDay >= $today)) {
                $usersToUpdate[] = [
                    'id' => $user->id,
                    'transfer_enable' => $user->plan->transfer_enable
                ];
            }
        }

        foreach ($usersToUpdate as $userData) {
            User::where('id', $userData['id'])->update([
                'transfer_enable' => (intval($userData['transfer_enable']) * 1073741824),
                'u' => 0,
                'd' => 0
            ]);
        }
    }
}
