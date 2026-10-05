<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Rapor dönem aralığı. Saatler hesabın kendi saat dilimindedir. */
class ReportPeriod
{
    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'];

    /** Rapor Detay haftası Salı başlar. Diğer ekranlar Carbon varsayılanını (Pazartesi) kullanır. */
    public const WEEK_STARTS_ON = Carbon::TUESDAY;

    private const MAX_DAYS = 92;

    /**
     * Yalnızca Rapor Detay. Parametresiz ve this_week: içinde bulunulan Salı–Pazartesi.
     * Eski period değerleri from/to geçerliyse o aralığı, değilse bu haftayı kullanır.
     *
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    public static function resolveDetail(Request $request, string $zone): array
    {
        $period = (string) $request->query('period', '');
        if ($period === '' || $period === 'this_week') {
            return self::detailWeek($zone);
        }

        $range = self::boundedRange($request, $zone);

        return $range === null ? self::detailWeek($zone) : ['custom', $range[0], $range[1]];
    }

    /** @return array{0: string, 1: Carbon, 2: Carbon} */
    private static function detailWeek(string $zone): array
    {
        $today = Carbon::now($zone)->startOfDay();
        $from = $today->copy()->startOfWeek(self::WEEK_STARTS_ON);

        return ['this_week', $from, $from->copy()->addDays(6)->startOfDay()];
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private static function boundedRange(Request $request, string $zone): ?array
    {
        if (! $request->filled('from') || ! $request->filled('to')) {
            return null;
        }

        try {
            $from = Carbon::parse((string) $request->query('from'), $zone)->startOfDay();
            $to = Carbon::parse((string) $request->query('to'), $zone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

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
