<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Stats\DailyStatWriter;
use Illuminate\Console\Command;

class SportStatsRefresh extends Command
{
    protected $signature = 'sport:stats-refresh';

    protected $description = 'Rewrite today and yesterday for every superadmin timezone';

    public function handle(DailyStatWriter $writer): int
    {
        User::query()->where('role', UserRole::Superadmin)->orderBy('id')->each(function (User $superadmin) use ($writer): void {
            $writer->refreshOpen($superadmin);
        });

        return self::SUCCESS;
    }
}
