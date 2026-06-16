<?php

namespace App\Console\Commands;

use App\Services\OffenseService;
use Illuminate\Console\Command;

class ExpireUserRestrictions extends Command
{
    protected $signature = 'expire:user-restrictions';

    protected $description = 'Revoke expired user node restrictions and re-sync eligible users';

    public function handle(OffenseService $offenseService): int
    {
        $count = $offenseService->expireDueRestrictions();
        $this->info("Expired {$count} restriction(s).");

        return self::SUCCESS;
    }
}
