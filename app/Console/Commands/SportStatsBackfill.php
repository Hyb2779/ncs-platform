<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Stats\DailyStatWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SportStatsBackfill extends Command
{
    protected $signature = 'sport:stats-backfill {--from=} {--to=}';

    protected $description = 'Rebuild daily stats from the ledger';

    public function handle(DailyStatWriter $writer): int
    {
        User::query()->where('role', UserRole::Superadmin)->orderBy('id')->each(function (User $superadmin) use ($writer): void {
            $timezone = $superadmin->timezone ?: 'UTC';
            $today = now($timezone)->startOfDay();
            $from = $this->option('from')
                ? Carbon::parse((string) $this->option('from'), $timezone)->startOfDay()
                : $this->firstDay($superadmin, $timezone, $today);
            $to = $this->option('to')
                ? Carbon::parse((string) $this->option('to'), $timezone)->startOfDay()
                : $today;
            $yesterday = $today->copy()->subDay();

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                if ($day->lt($yesterday)) {
                    $writer->close($superadmin, $date);
                } else {
                    $writer->rewriteTree($superadmin, $date);
                }
            }
        });

        return self::SUCCESS;
    }

    private function firstDay(User $superadmin, string $timezone, Carbon $today): Carbon
    {
        $memberIds = User::withTrashed()
            ->where('superadmin_id', $superadmin->id)
            ->where('role', UserRole::Uye)
            ->pluck('id');

        $firstTransaction = $memberIds->isEmpty()
            ? null
            : WalletTransaction::query()->whereIn('user_id', $memberIds)->min('created_at');
        $firstMember = User::withTrashed()
            ->where('superadmin_id', $superadmin->id)
            ->where('role', UserRole::Uye)
            ->min('created_at');
        $earliest = collect([$firstTransaction, $firstMember])->filter()->sort()->first();

        if ($earliest === null) {
            return $today->copy();
        }

        return Carbon::parse($earliest)->timezone($timezone)->startOfDay();
    }
}
