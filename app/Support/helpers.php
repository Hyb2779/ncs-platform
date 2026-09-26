<?php

use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\SportFixture;
use App\Models\User;
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

    $zone = auth()->user()->timezone ?? 'UTC';

    return ['text' => sport_digits($fixture->starts_at->timezone($zone)->format('H:i')), 'live' => false];
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
