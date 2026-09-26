<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Stats\DailyStatWriter;
use Illuminate\Console\Command;

class SportStatsClose extends Command
{
    protected $signature = 'sport:stats-close';

    protected $description = 'Close the day before yesterday in each superadmin timezone';

    public function handle(DailyStatWriter $writer): int
    {
        User::query()->where('role', UserRole::Superadmin)->orderBy('id')->each(function (User $superadmin) use ($writer): void {
            $date = now($superadmin->timezone ?: 'UTC')->subDays(2)->toDateString();
            $writer->close($superadmin, $date);
        });

        return self::SUCCESS;
    }
}
