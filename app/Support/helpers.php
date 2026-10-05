<?php

use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\User;
use App\Services\Sport\CouponLimitGuard;
use App\Services\Sport\SportNames;
use App\Support\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

function brand(): Brand
{
    return app(Brand::class);
}

function sport_name(?Model $entity): string
{
    return app(SportNames::class)->name($entity);
}

function sport_status(?string $code): string
{
    $key = 'sport.statuses.'.($code ?? '');

    return Lang::has($key) ? __($key) : (string) $code;
}

function sport_clock(SportFixture $fixture): string
{
    $status = (string) $fixture->status;

    if ($status !== 'HT' && $fixture->elapsed !== null && in_array($status, config('sport.live_statuses'), true)) {
        return sport_digits((string) $fixture->elapsed)."'";
    }

    return sport_status($status);
}

function account_label(?User $person, User $viewer): string
{
    if ($person === null) {
        return __('panel.empty_value');
    }

    $isAncestor = $person->id !== $viewer->id && str_starts_with((string) $viewer->path, (string) $person->path);

    if ($isAncestor || ! $person->isInSubtreeOf($viewer)) {
        return __('wallet.upper_account');
    }

    return $person->username;
}

function sport_digits(string $value): string
{
    $eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    return str_replace($eastern, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $value);
}

function display_timezone(): string
{
    return (string) config('app.display_timezone', 'Europe/Istanbul');
}

/**
 * Mutlak anı Europe/Istanbul duvar saatine çevirir. Offset'siz metin UTC kabul edilir.
 * Kaynak Carbon nesnesini değiştirmez.
 */
function display_instant(mixed $value): ?Carbon
{
    if ($value === null || $value === '') {
        return null;
    }

    $zone = display_timezone();

    if ($value instanceof \DateTimeInterface) {
        return Carbon::instance($value)->timezone($zone);
    }

    if (is_int($value) || (is_string($value) && preg_match('/^\d{10,}$/', $value) === 1)) {
        return Carbon::createFromTimestampUTC((int) $value)->timezone($zone);
    }

    $raw = trim((string) $value);
    $hasOffset = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $raw) === 1;
    $parsed = $hasOffset ? Carbon::parse($raw) : Carbon::parse($raw, 'UTC');

    return $parsed->timezone($zone);
}

function display_clock(mixed $value, string $format = 'H:i'): string
{
    $instant = display_instant($value);

    return $instant === null ? '' : sport_digits($instant->format($format));
}

/**
 * Gösterim dilimindeki gün aralığını UTC sınırlarına çevirir (gece yarısı maçları doğru güne düşsün).
 *
 * @return array{0: Carbon, 1: Carbon}
 */
function display_span_utc(int $fromDay = 0, int $toDay = 0): array
{
    $start = now()->timezone(display_timezone())->startOfDay();

    return [
        $start->copy()->addDays($fromDay)->utc(),
        $start->copy()->addDays($toDay)->endOfDay()->utc(),
    ];
}

function sport_placed_line(Coupon $coupon, CouponSelection $selection): string
{
    $zone = auth()->user()->timezone ?? 'UTC';
    $time = sport_digits($coupon->placed_at->timezone($zone)->format('d.m H:i'));
    $detail = $selection->placed_minute === null
        ? __('sport.live.pre_match')
        : sport_digits((string) $selection->placed_minute)."' ".sport_digits((int) $selection->placed_home.'-'.(int) $selection->placed_away);

    return __('sport.live.placed', ['time' => $time, 'detail' => $detail]);
}

/**
 * @return array{text: string, live: bool}
 */
function sport_live_state(?SportFixture $fixture, ?string $selectionStatus = null): array
{
    if ($fixture === null) {
        return ['text' => __('panel.empty_value'), 'live' => false];
    }

    $status = (string) $fixture->status;
    if (in_array($status, config('sport.settle_statuses'), true)) {
        if ($selectionStatus === 'pending') {
            return ['text' => __('sport.live.awaiting_result'), 'live' => false];
        }

        return ['text' => trim(__('sport.live.full_label').' '.sport_pair($fixture->ft_home, $fixture->ft_away)), 'live' => false];
    }

    if ($status === 'HT') {
        $home = $fixture->ht_home ?? $fixture->score_home;
        $away = $fixture->ht_away ?? $fixture->score_away;

        return ['text' => __('sport.live.half_label').' · '.sport_pair($home, $away), 'live' => false];
    }

    if (in_array($status, config('sport.live_statuses'), true)) {
        $score = sport_pair($fixture->score_home, $fixture->score_away);
        $text = $fixture->elapsed === null
            ? $score
            : sport_digits((string) $fixture->elapsed)."' · ".$score;

        return ['text' => $text, 'live' => true];
    }

    return ['text' => display_clock($fixture->starts_at), 'live' => false];
}

function sport_price_open(SportFixture $fixture, string $price): bool
{
    $user = auth()->user();
    if ($user === null) {
        return true;
    }
    $guard = request()->attributes->get('sport.limit_guard');
    if (! $guard instanceof CouponLimitGuard) {
        $guard = app(CouponLimitGuard::class);
        request()->attributes->set('sport.limit_guard', $guard);
    }

    return $guard->allowsPrice($user, $fixture, $price);
}

function sport_pair(mixed $home, mixed $away): string
{
    if ($home === null || $away === null || $home === '' || $away === '') {
        return '';
    }

    return sport_digits((int) $home.'-'.(int) $away);
}

function sport_date(Carbon $date, string $format): string
{
    $formatted = $date->copy()->locale(app()->getLocale())->translatedFormat($format);
    $eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    return str_replace($eastern, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $formatted);
}

function site_theme(): string
{
    $user = auth()->user();
    if ($user === null) {
        return \App\Enums\Theme::Classic->value;
    }
    if ($user->theme !== null) {
        return $user->theme->value;
    }
    $default = $user->superadmin_id
        ? \App\Models\User::query()->whereKey($user->superadmin_id)->toBase()->value('theme')
        : null;

    return in_array($default, \App\Enums\Theme::values(), true) ? $default : \App\Enums\Theme::Classic->value;
}

function wegas_sport_available(?\App\Models\User $user): bool
{
    if ((string) config('services.ncs_bridge.secret') === '') {
        return false;
    }
    if ($user === null) {
        return app()->getLocale() !== 'ar' && ! \App\Services\Casino\GameAvailability::productBlocked(null, 'wegas_sport');
    }

    // Oyun Yonetimi'nde genel / ust hesap / bayi seviyesinde kapatilabilir (product: wegas_sport).
    return $user->role === \App\Enums\UserRole::Uye
        && $user->currency->value === 'TRY'
        && $user->language->value !== 'ar'
        && ! \App\Services\Casino\GameAvailability::productBlocked($user, 'wegas_sport');
}
