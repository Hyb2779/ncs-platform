<?php

namespace App\Services\Stats;

/**
 * Daily-stat rules. DailyStatWriter, sport:stats-refresh, sport:stats-close,
 * and sport:stats-backfill share one rewrite path. Charts draw a negative
 * day instead of clipping it.
 *
 * Payout is wins plus the signed amount of adjustment rows whose product is
 * sport, slot, or live_casino. Those rows are settlement reversals and
 * provider rollbacks. A winning coupon later corrected to a loss must leave
 * that day's GGR as if the payout never happened.
 *
 * Rows whose product is transfer, adjustment, or bonus never enter turnover
 * or payout.
 *
 * A refund or cancellation is booked on the calendar day the movement
 * happened, in the superadmin's timezone. That day's turnover may be negative.
 *
 * The later job rewrites today and yesterday every 10 minutes in each
 * superadmin timezone, closes the day before yesterday at night, and
 * recomputes a closed day when a correction lands on it. Backfill uses the
 * same path. Every write is idempotent.
 */
final class StatRules
{
    /** Adjustment products that enter payout, with their sign. */
    public const PAYOUT_ADJUSTMENT_PRODUCTS = ['sport', 'slot', 'live_casino'];

    /** Products that never enter turnover or payout. */
    public const EXCLUDED_PRODUCTS = ['transfer', 'adjustment', 'bonus'];
}
