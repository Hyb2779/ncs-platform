<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Rapor Detay ile aynı dönem aralığı. Saatler hesabın kendi saat dilimindedir. */
class ReportPeriod
{
    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'];

    private const MAX_DAYS = 92;

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon} dönem, yerel gün başlangıcı, yerel son gün (dahil)
     */
    public static function resolve(Request $request, string $zone, string $default = 'this_week'): array
    {
        $now = Carbon::now($zone);
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : $default;

        if ($period === 'custom') {
            try {
                $from = Carbon::parse((string) $request->query('from'), $zone)->startOfDay();
                $to = Carbon::parse((string) $request->query('to'), $zone)->startOfDay();
            } catch (\Throwable) {
                $from = $now->copy()->startOfDay();
                $to = $from->copy();
            }
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }
            if ($from->diffInDays($to) >= self::MAX_DAYS) {
                $from = $to->copy()->subDays(self::MAX_DAYS - 1);
            }

            return [$period, $from, $to];
        }

        $today = $now->copy()->startOfDay();

        return match ($period) {
            'yesterday' => [$period, $today->copy()->subDay(), $today->copy()->subDay()],
            'this_week' => [$period, $today->copy()->startOfWeek(), $today],
            'last_week' => [$period, $today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek()->startOfDay()],
            'this_month' => [$period, $today->copy()->startOfMonth(), $today],
            'last_month' => [$period, $today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$period, $today, $today],
        };
    }
}
