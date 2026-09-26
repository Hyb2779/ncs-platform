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
        $network = [
            self::item(__('panel.users'), 'panel.users.index', ['panel.users.*']),
            self::item(__('wallet.menu'), 'panel.transactions', ['panel.transactions']),
            self::item(__('site.panel_rounds'), 'panel.casino.rounds', ['panel.casino.rounds']),
            self::item(__('site.panel_sessions'), 'panel.casino.sessions', ['panel.casino.sessions']),
            self::item(__('sport.panel.coupons'), 'panel.coupons.index', ['panel.coupons.index', 'panel.coupons.show']),
            self::item(__('sport.panel.lookup'), 'panel.coupons.lookup', ['panel.coupons.lookup']),
            self::item(__('sport.panel.overdraft'), 'panel.sport.overdrafts', ['panel.sport.overdrafts']),
            self::item(__('sport.panel.risky'), 'panel.coupons.risky', ['panel.coupons.risky']),
        ];

        if (in_array($user->role, [UserRole::Owner, UserRole::Superadmin, UserRole::Bayi], true)) {
            $network[] = self::item(__('sport.panel.limits'), 'panel.sport.limits', ['panel.sport.limits']);
        }

        if ($user->role === UserRole::Owner) {
            $network[] = self::item(__('sport.panel.status'), 'panel.sport.status', ['panel.sport.status']);
            $network[] = self::item(__('sport.panel.leagues'), 'panel.sport.leagues', ['panel.sport.leagues']);
            $network[] = self::item(__('sport.panel.translations'), 'panel.sport.translations', ['panel.sport.translations']);
            $network[] = self::item(__('sport.panel.margins'), 'panel.sport.margins', ['panel.sport.margins']);
            $network[] = self::item(__('site.panel_providers'), 'panel.casino.providers', ['panel.casino.providers']);
            $network[] = self::item(__('site.panel_games'), 'panel.casino.games', ['panel.casino.games']);
        }

        return [
            [
                'label' => __('panel.menu_general'),
                'items' => [
                    self::item(__('panel.overview'), 'panel.dashboard', ['panel.dashboard']),
                ],
            ],
            [
                'label' => __('panel.menu_network'),
                'items' => $network,
            ],
        ];
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
            : self::item(__('wallet.menu'), 'panel.transactions', ['panel.transactions']);

        return [
            self::item(__('panel.overview'), 'panel.dashboard', ['panel.dashboard']),
            self::item(__('panel.users'), 'panel.users.index', ['panel.users.*']),
            self::item(__('sport.panel.coupons'), 'panel.coupons.index', ['panel.coupons.index', 'panel.coupons.show']),
            $fourth,
        ];
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
}
