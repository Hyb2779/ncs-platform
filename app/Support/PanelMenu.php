<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

class PanelMenu
{
    /**
     * Drawer sections. The bottom bar uses the same item shape.
     *
     * @return list<array{label: string, items: list<array{label: string, route: string, active: list<string>}>}>
     */
    public static function sections(User $user): array
    {
        $staff = in_array($user->role, [UserRole::Owner, UserRole::Superadmin], true);
        $owner = $user->role === UserRole::Owner;

        $users = [
            self::item(__('panel.menu_user_create'), 'panel.users.create', ['panel.users.create']),
            self::item(__('panel.menu_users_all'), 'panel.users.index', ['panel.users.index', 'panel.users.edit', 'panel.users.show']),
            self::item(__('panel.menu_balance'), 'panel.balance', ['panel.balance']),
        ];
        if ($staff) {
            $users[] = self::item(__('panel.online_title'), 'panel.online.index', ['panel.online.index']);
        }

        $reports = [
            self::item(__('sport.panel.coupons'), 'panel.coupons.index', ['panel.coupons.index', 'panel.coupons.show', 'panel.coupons.tipo']),
            self::item(__('sport.panel.lookup'), 'panel.coupons.lookup', ['panel.coupons.lookup']),
            self::item(__('panel.density_title'), 'panel.coupons.density', ['panel.coupons.density']),
        ];
        $reports[] = self::item(__('panel.reports_title'), 'panel.reports.index', ['panel.reports.index']);
        if ($user->isRootOwner()) {
            $reports[] = self::item(__('panel.volkan_credit'), 'panel.volkan-credit.index', ['panel.volkan-credit.index']);
        }
        $reports[] = self::item(self::ledgerLabel(), 'panel.transactions', ['panel.transactions']);
        $reports[] = self::item(__('panel.member_movements'), 'panel.member-movements', ['panel.member-movements']);
        $reports[] = self::item(__('panel.player_movements'), 'panel.player-movements', ['panel.player-movements']);
        $reports[] = self::item(__('site.panel_rounds'), 'panel.casino.rounds', ['panel.casino.rounds']);
        $reports[] = self::item(__('site.panel_sessions'), 'panel.casino.sessions', ['panel.casino.sessions']);
        if ($staff) {
            $reports[] = self::item(__('panel.logs_actions'), 'panel.logs.index', ['panel.logs.index']);
            $reports[] = self::item(__('panel.logs_logins'), 'panel.logs.logins', ['panel.logs.logins']);
        }

        $betting = [];
        if ($staff) {
            $betting[] = self::item(__('sport.panel.risky'), 'panel.coupons.risky', ['panel.coupons.risky']);
            $betting[] = self::item(__('sport.panel.overdraft'), 'panel.sport.overdrafts', ['panel.sport.overdrafts']);
            $betting[] = self::item(__('panel.games_title'), 'panel.games.index', ['panel.games.index', 'panel.casino.games']);
        }
        if ($owner) {
            $betting[] = self::item(__('panel.home_slides_title'), 'panel.home-slides.index', ['panel.home-slides.index']);
        }
        if ($owner) {
            $betting[] = self::item(__('sport.panel.status'), 'panel.sport.status', ['panel.sport.status']);
            $betting[] = self::item(__('sport.panel.leagues'), 'panel.sport.leagues', ['panel.sport.leagues']);
            $betting[] = self::item(__('sport.panel.translations'), 'panel.sport.translations', ['panel.sport.translations']);
            $betting[] = self::item(__('sport.panel.margins'), 'panel.sport.margins', ['panel.sport.margins']);
        }

        $settings = [];
        if ($staff) {
            $settings[] = self::item(__('sport.panel.limits'), 'panel.sport.limits', ['panel.sport.limits']);
        }
        if ($user->role === UserRole::Superadmin) {
            $settings[] = self::item(__('panel.theme_title'), 'panel.theme', ['panel.theme']);
        }
        if ($owner) {
            $settings[] = self::item(__('site.panel_providers'), 'panel.casino.providers', ['panel.casino.providers']);
        }

        // Ayarlar > Dil secenegi: tum panel rolleri (04.10, Blackeagle).
        $settings[] = self::item(__('panel.menu_language'), 'panel.preferences.language.edit', ['panel.preferences.language.edit']);

        $sections = [
            self::section(__('panel.menu_general'), [
                self::item(__('panel.overview'), 'panel.dashboard', ['panel.dashboard']),
                self::item(__('panel.password_title'), 'panel.password.edit', ['panel.password.edit']),
            ]),
            self::section(__('panel.menu_users'), $users),
            self::section(__('panel.menu_reports'), $reports),
            self::section(__('panel.menu_betting'), $betting),
            self::section(__('panel.menu_settings'), $settings),
        ];

        return array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== []));
    }

    /**
     * Four primary destinations. Owner's fourth item is sport data; everyone else gets the ledger.
     *
     * @return list<array{label: string, route: string, active: list<string>}>
     */
    public static function bottom(User $user): array
    {
        $fourth = $user->role === UserRole::Owner
            ? self::item(__('sport.panel.status'), 'panel.sport.status', ['panel.sport.status'])
            : self::item(self::ledgerLabel(), 'panel.transactions', ['panel.transactions']);

        return [
            self::item(__('panel.overview'), 'panel.dashboard', ['panel.dashboard']),
            self::item(__('panel.users'), 'panel.users.index', ['panel.users.*']),
            self::item(__('sport.panel.coupons'), 'panel.coupons.index', ['panel.coupons.index', 'panel.coupons.show', 'panel.coupons.tipo']),
            $fourth,
        ];
    }

    /**
     * @param  list<array{label: string, route: string, active: list<string>}>  $items
     * @return array{label: string, items: list<array{label: string, route: string, active: list<string>}>}
     */
    private static function section(string $label, array $items): array
    {
        return ['label' => $label, 'items' => $items];
    }

    /**
     * @param  list<string>  $active
     * @return array{label: string, route: string, active: list<string>}
     */
    private static function item(string $label, string $route, array $active): array
    {
        return [
            'label' => $label,
            'route' => $route,
            'active' => $active,
        ];
    }

    /** Hesap hareketleri menü/başlık etiketi, rolün altındaki seviyeye göre. */
    public static function ledgerLabel(): string
    {
        $key = 'wallet.menu_'.(auth()->user()?->role->value ?? '');

        return trans()->has($key) ? __($key) : __('wallet.menu');
    }
}
