<?php

use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\SportOdd;
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

/** Takım araması: büyük/küçük harf ve Türkçe karakter farkını kaldırır. */
function sport_search_key(string $value): string
{
    $value = strtr($value, [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ş' => 's', 'ş' => 's',
        'Ğ' => 'g', 'ğ' => 'g',
        'Ü' => 'u', 'ü' => 'u',
        'Ö' => 'o', 'ö' => 'o',
        'Ç' => 'c', 'ç' => 'c',
    ]);

    return mb_strtolower($value, 'UTF-8');
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

/** İlk yarı 40, ikinci yarı 85'ten sonra futbol canlı oranı ve bozdurma kapanır. */
function sport_live_clock_closed(SportFixture $fixture): bool
{
    if (($fixture->sport ?: 'football') !== 'football') {
        return false;
    }

    $minute = $fixture->elapsed;
    if ($minute === null) {
        return false;
    }

    $status = (string) $fixture->status;
    $period = strtoupper((string) (is_array($fixture->live_meta) ? ($fixture->live_meta['period'] ?? '') : ''));
    if ($status === 'LIVE' && in_array($period, ['1H', '2H'], true)) {
        $status = $period;
    }

    return ($status === '1H' && $minute > 40) || ($status === '2H' && $minute > 85);
}

/** Üst çizgisi bir gole kalınca kapanır: 1.5 üst 1 golde, 2.5 üst 2 golde. */
function sport_over_goal_closed(SportFixture $fixture, SportOdd $odd): bool
{
    if (! in_array($fixture->status, [...config('sport.live_statuses'), 'HT'], true)) {
        return false;
    }

    $odd->loadMissing('market');
    $code = (string) ($odd->market->code ?? '');
    $pick = $code === 'BOOK' ? (string) $odd->selection_name : (string) $odd->outcome;
    if (! in_array($pick, ['over', 'Üst'], true)) {
        return false;
    }

    $line = match ($code) {
        'OU15' => '1.5',
        'OU25' => '2.5',
        'OU35' => '3.5',
        'BOOK' => (string) $odd->handicap,
        default => null,
    };
    if ($line === null || ! is_numeric($line) || bccomp($line, '0.5', 2) !== 1) {
        return false;
    }

    $group = (string) $odd->group_name;
    if ($code === 'BOOK' && ! in_array($group, [
        'Toplam Alt/Üst', 'İlk Yarı Alt/Üst', 'İlk Yarı Toplam Alt/Üst',
        'İkinci Yarı Alt/Üst', 'İkinci Yarı Toplam Alt/Üst',
        'Ev Sahibi Toplam Alt/Üst', 'Deplasman Toplam Alt/Üst',
        'İlk Yarı Ev Sahibi Toplam Alt/Üst', 'İlk Yarı Deplasman Toplam Alt/Üst',
        'İkinci Yarı Ev Sahibi Toplam Alt/Üst', 'İkinci Yarı Deplasman Toplam Alt/Üst',
    ], true)) {
        return false;
    }

    $goals = sport_over_goals($fixture, $group);
    if ($goals === null) {
        return false;
    }

    return bccomp((string) $goals, bcsub($line, '0.5', 2), 2) !== -1;
}

function sport_over_goals(SportFixture $fixture, string $group): ?int
{
    $halfOver = in_array($fixture->status, ['HT', '2H', 'ET', 'P', 'BT', 'FT', 'AET', 'PEN'], true);
    $live = sport_score_pair($fixture->score_home, $fixture->score_away);
    if ($live === null) {
        return null;
    }

    if (str_contains($group, 'İkinci Yarı')) {
        $ht = sport_score_pair($fixture->ht_home, $fixture->ht_away);
        if ($ht === null) {
            return null;
        }
        $live = [$live[0] - $ht[0], $live[1] - $ht[1]];
    } elseif (str_starts_with($group, 'İlk Yarı') && $halfOver) {
        $live = sport_score_pair($fixture->ht_home, $fixture->ht_away);
        if ($live === null) {
            return null;
        }
    }

    if (str_contains($group, 'Ev Sahibi')) {
        return $live[0];
    }
    if (str_contains($group, 'Deplasman')) {
        return $live[1];
    }

    return $live[0] + $live[1];
}

/** @return array{0: int, 1: int}|null */
function sport_score_pair(mixed $home, mixed $away): ?array
{
    if ($home === null || $away === null || ! is_numeric($home) || ! is_numeric($away)) {
        return null;
    }

    return [(int) $home, (int) $away];
}

function sport_offer_closed(SportFixture $fixture, SportOdd $odd): bool
{
    return sport_live_clock_closed($fixture) || sport_over_goal_closed($fixture, $odd);
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

function sport_slug(string $value): string
{
    $value = strtr($value, [
        'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ü' => 'u', 'ü' => 'u',
    ]);
    $value = mb_strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? '';

    return trim($value, '_');
}

function sport_group_label(?string $group, ?string $fallbackKey = null): string
{
    $group = trim((string) $group);
    if ($group !== '') {
        $key = 'sport_markets.'.sport_slug($group);

        return Lang::has($key) ? __($key) : $group;
    }

    return $fallbackKey ? __($fallbackKey) : '';
}

function sport_pick_label(SportOdd $odd): string
{
    $name = trim((string) ($odd->selection_name ?? ''));
    if ($name === '') {
        $key = 'sport.outcomes.'.$odd->outcome;

        return Lang::has($key) ? __($key) : (string) $odd->outcome;
    }

    $key = 'sport_picks.'.sport_slug($name);
    $label = Lang::has($key) ? __($key) : sport_digits($name);
    $line = rtrim(rtrim(number_format((float) ($odd->handicap ?? 0), 2, '.', ''), '0'), '.');
    if ($line !== '' && $line !== '0' && ! str_contains($name, $line)) {
        $label .= ' '.sport_digits($line);
    }

    return $label;
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
        return ! \App\Services\Casino\GameAvailability::productBlocked(null, 'wegas_sport');
    }

    // Oyun Yonetimi'nde genel / ust hesap / bayi seviyesinde kapatilabilir (product: wegas_sport).
    return $user->role === \App\Enums\UserRole::Uye
        && $user->currency->value === 'TRY'
        && ! \App\Services\Casino\GameAvailability::productBlocked($user, 'wegas_sport');
}

/** Fenix bülteni ve kendi kuponu. Arapça sitede de açıktır; sayfa İngilizce gelir. */
/** @return list<string> */
function sport_closed(?\App\Models\User $user): array
{
    return \App\Services\Casino\GameAvailability::closedSports($user);
}

function own_sport_available(?\App\Models\User $user): bool
{
    if (! config('sport.own_book_enabled')) {
        return false;
    }
    if ($user === null) {
        return ! \App\Services\Casino\GameAvailability::productBlocked(null, 'wegas_sport');
    }

    return $user->role === \App\Enums\UserRole::Uye
        && ! \App\Services\Casino\GameAvailability::productBlocked($user, 'wegas_sport');
}

/**
 * Menü ve ana sayfa sporu. Kendi bülten açıkken Tipo iframe'i gösterilmez.
 *
 * @return array{route: string, match: list<string>, label: string, path: string}|null
 */
function site_sport_link(?\App\Models\User $user): ?array
{
    $own = own_sport_available($user);
    if (! $own && (config('sport.own_book_enabled') || ! wegas_sport_available($user))) {
        return null;
    }

    return [
        'route' => $own ? 'site.sport' : 'site.wegas_sport',
        'match' => $own ? ['site.sport', 'site.sport.show', 'site.sport.live'] : ['site.wegas_sport'],
        'label' => brand()->name().' '.__('site.sport'),
        'path' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7l4 3-1.5 5h-5L8 10z',
    ];
}
