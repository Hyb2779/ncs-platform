<?php

namespace App\Services\Stats;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WalletTransactionType;
use App\Models\Coupon;
use App\Models\CouponSelection;
use App\Models\DailyStat;
use App\Models\SportSyncState;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Sport\FootballBudget;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class PanelDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function owner(User $owner, FootballBudget $budget): array
    {
        $currency = $this->currency(request('currency'));
        $range = $this->range($owner);
        $supers = User::query()
            ->where('role', UserRole::Superadmin)
            ->where('currency', $currency)
            ->orderBy('username')
            ->get();
        $ids = $supers->pluck('id')->all();
        $data = $this->presentation($owner, $ids, $currency, $range, true);
        $data['currencies'] = array_map(fn (Currency $item): string => $item->value, Currency::cases());
        $data['currency'] = $currency->value;
        $data['ops'] = $this->operations($owner, $budget);
        $data['columns'] = [
            ['key' => 'username', 'label' => __('panel.fields.username'), 'priority' => 'primary'],
            ['key' => 'balance', 'label' => __('wallet.balance'), 'priority' => 'primary'],
            ['key' => 'ggr', 'label' => __('panel.period_ggr'), 'priority' => 'primary'],
            ['key' => 'status', 'label' => __('panel.fields.status'), 'priority' => 'primary'],
            ['key' => 'bayi', 'label' => __('panel.bayi_count'), 'priority' => 'detail'],
            ['key' => 'members', 'label' => __('panel.member_count'), 'priority' => 'detail'],
        ];
        $data['rows'] = $supers->map(function (User $superadmin) use ($range): array {
            $ggr = $this->sum($this->rows([$superadmin->id], $range['from'], $range['to'], 'all'), 'ggr');

            return [
                'username' => new HtmlString('<a class="font-medium" href="'.e(route('panel.network.show', $superadmin)).'">'.e($superadmin->username).'</a>'),
                'balance' => $superadmin->wallet()->first()?->formattedBalance() ?? Money::format('0', $superadmin->currency),
                'ggr' => Money::format($ggr, $superadmin->currency),
                'status' => $this->badge($superadmin->status),
                'bayi' => (string) User::query()->where('parent_id', $superadmin->id)->where('role', UserRole::Bayi)->count(),
                'members' => (string) User::query()->where('superadmin_id', $superadmin->id)->where('role', UserRole::Uye)->count(),
            ];
        })->all();
        $data['heading'] = __('panel.overview');

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function superadmin(User $user): array
    {
        $range = $this->range($user);
        $data = $this->scoped($user, $range, true);
        $bayis = User::query()->where('parent_id', $user->id)->where('role', UserRole::Bayi)->orderBy('username')->get();
        $data['rank'] = $this->ranking($bayis->pluck('id')->all(), $range['from'], $range['to']);
        $data['columns'] = [
            ['key' => 'username', 'label' => __('panel.fields.username'), 'priority' => 'primary'],
            ['key' => 'balance', 'label' => __('wallet.balance'), 'priority' => 'primary'],
            ['key' => 'ggr', 'label' => __('panel.period_ggr'), 'priority' => 'primary'],
            ['key' => 'members', 'label' => __('panel.member_count'), 'priority' => 'detail'],
            ['key' => 'risk', 'label' => __('panel.open_risk'), 'priority' => 'detail'],
        ];
        $data['rows'] = $bayis->map(function (User $bayi) use ($range): array {
            $members = $this->membersOf([$bayi->id]);
            $open = $this->openCoupons([$bayi->id], $members);

            return [
                'username' => new HtmlString('<a class="font-medium" href="'.e(route('panel.network.show', $bayi)).'">'.e($bayi->username).'</a>'),
                'balance' => $bayi->wallet()->first()?->formattedBalance() ?? Money::format('0', $bayi->currency),
                'ggr' => Money::format($this->sum($this->rows([$bayi->id], $range['from'], $range['to'], 'all'), 'ggr'), $bayi->currency),
                'members' => (string) $members->count(),
                'risk' => Money::format($open['risk'], $bayi->currency),
            ];
        })->all();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function bayi(User $user): array
    {
        $data = $this->scoped($user, $this->range($user), false);
        $data['showPlayers'] = false;
        $data['columns'] = [];
        $data['rows'] = [];

        return $data;
    }

    /**
     * @param  array{from: string, to: string, previous_from: string, previous_to: string}  $range
     * @return array<string, mixed>
     */
    private function scoped(User $user, array $range, bool $includeNew): array
    {
        $data = $this->presentation($user, [$user->id], $user->currency, $range, false);
        $data['players'] = $this->players([$user->id], $range['from'], $range['to'], $includeNew);
        $data['cards'][] = $this->card(
            __('panel.subtree_balance'),
            Money::format($this->subtreeBalance($user), $user->currency),
            null,
        );
        $data['currency'] = $user->currency->value;
        $data['currencies'] = [];
        $data['ops'] = null;
        $data['heading'] = __('panel.overview');
        $data['showPlayers'] = true;

        return $data;
    }

    private function subtreeBalance(User $account): string
    {
        $ids = User::query()->subtreeOf($account)->where('id', '!=', $account->id)->pluck('id');
        $total = '0.00';
        if ($ids->isEmpty()) {
            return $total;
        }

        foreach (Wallet::query()->whereIn('user_id', $ids)->where('currency', $account->currency)->pluck('balance') as $balance) {
            $total = bcadd($total, (string) $balance, 2);
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function account(User $viewer, User $account): array
    {
        $range = $this->range($viewer);
        $data = $this->presentation($viewer, [$account->id], $account->currency, $range, false);
        $data['currency'] = $account->currency->value;
        $data['currencies'] = [];
        $data['ops'] = null;
        $data['columns'] = [];
        $data['rows'] = [];
        $data['heading'] = $account->username;
        $data['account'] = $account;

        return $data;
    }

    /**
     * @param  list<int>  $accountIds
     * @param  array{from: string, to: string, previous_from: string, previous_to: string}  $range
     * @return array<string, mixed>
     */
    private function presentation(User $viewer, array $accountIds, Currency $currency, array $range, bool $rankSuperadmins): array
    {
        $current = $this->rows($accountIds, $range['from'], $range['to'], 'all');
        $previous = $this->rows($accountIds, $range['previous_from'], $range['previous_to'], 'all');
        $turnover = $this->sum($current, 'turnover');
        $payout = $this->sum($current, 'payout');
        $ggr = $this->sum($current, 'ggr');
        $members = $this->membersOf($accountIds);
        $active = $this->activePlayers($members, $accountIds, $range['from'], $range['to']);
        $previousActive = $this->activePlayers($members, $accountIds, $range['previous_from'], $range['previous_to']);
        $open = $this->openCoupons($accountIds, $members);
        $overdraft = $this->overdraft($members);

        $cards = [
            $this->card(__('panel.period_turnover'), Money::format($turnover, $currency), $this->change($turnover, $this->sum($previous, 'turnover'))),
            $this->card(__('panel.period_ggr'), Money::format($ggr, $currency), $this->change($ggr, $this->sum($previous, 'ggr'))),
            $this->card(__('panel.period_payout'), Money::format($payout, $currency), $this->change($payout, $this->sum($previous, 'payout'))),
            $this->card(__('panel.active_players'), number_format($active, 0, '.', ','), $this->change((string) $active, (string) $previousActive)),
            $this->card(__('panel.open_risk'), $open['count'].' · '.Money::format($open['risk'], $currency), null),
            $this->card(__('panel.overdraft_total'), Money::format($overdraft, $currency), null),
        ];

        if ($rankSuperadmins) {
            array_unshift($cards, $this->card(
                __('wallet.distributed_credit'),
                $viewer->wallets()->where('currency', $currency)->first()?->formattedDistributedBalance() ?? Money::format('0', $currency),
                null,
            ));
        } else {
            $account = User::query()->find($accountIds[0] ?? 0);
            array_unshift($cards, $this->card(
                __('wallet.balance'),
                $account?->wallet()->first()?->formattedBalance() ?? Money::format('0', $currency),
                null,
            ));
        }

        return [
            'range' => $range,
            'cards' => $cards,
            'line' => $this->series($accountIds, $range['from'], $range['to']),
            'products' => $this->products($accountIds, $range['from'], $range['to']),
            'rank' => $rankSuperadmins ? $this->ranking($accountIds, $range['from'], $range['to']) : null,
            'players' => $this->players($accountIds, $range['from'], $range['to'], false),
        ];
    }

    /**
     * @param  list<int>  $accountIds
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<float>}>}
     */
    public function series(array $accountIds, string $from, string $to): array
    {
        $grouped = $this->rows($accountIds, $from, $to, 'all')->groupBy(fn (DailyStat $row): string => $row->stat_date->toDateString());
        $labels = [];
        $turnover = [];
        $ggr = [];
        foreach ($this->days($from, $to) as $day) {
            $labels[] = Carbon::parse($day)->format('d.m');
            $bucket = $grouped->get($day, collect());
            $turnover[] = (float) $this->sum($bucket, 'turnover');
            $ggr[] = (float) $this->sum($bucket, 'ggr');
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => __('panel.chart_turnover'), 'data' => $turnover],
                ['label' => __('panel.chart_ggr'), 'data' => $ggr],
            ],
        ];
    }

    /**
     * @param  list<int>  $accountIds
     * @return array{type: string, labels: list<string>, datasets: list<array{label: string, data: list<float>}>}
     */
    public function products(array $accountIds, string $from, string $to): array
    {
        $labels = [__('site.sport'), __('site.slots'), __('site.live_casino')];
        $data = [];
        foreach (DailyStatWriter::PRODUCTS as $product) {
            $data[] = (float) $this->sum($this->rows($accountIds, $from, $to, $product), 'turnover');
        }

        return [
            'type' => min($data) < 0 ? 'bar' : 'doughnut',
            'labels' => $labels,
            'datasets' => [
                ['label' => __('panel.period_turnover'), 'data' => $data],
            ],
        ];
    }

    /**
     * @param  list<int>  $accountIds
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<float>}>}
     */
    public function ranking(array $accountIds, string $from, string $to): array
    {
        $rows = $this->rows($accountIds, $from, $to, 'all');
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->user_id] = bcadd($totals[$row->user_id] ?? '0.00', (string) $row->ggr, 2);
        }
        arsort($totals, SORT_NUMERIC);
        $totals = array_slice($totals, 0, 10, true);
        $names = User::query()->whereIn('id', array_keys($totals))->pluck('username', 'id');
        $labels = [];
        $data = [];
        foreach ($totals as $id => $ggr) {
            $labels[] = (string) ($names[$id] ?? $id);
            $data[] = (float) $ggr;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => __('panel.period_ggr'), 'data' => $data],
            ],
        ];
    }

    /**
     * @param  list<int>  $accountIds
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<float>}>}
     */
    public function players(array $accountIds, string $from, string $to, bool $includeNew): array
    {
        $grouped = $this->rows($accountIds, $from, $to, 'all')->groupBy(fn (DailyStat $row): string => $row->stat_date->toDateString());
        $labels = [];
        $active = [];
        $new = [];
        foreach ($this->days($from, $to) as $day) {
            $labels[] = Carbon::parse($day)->format('d.m');
            $bucket = $grouped->get($day, collect());
            $active[] = (int) $bucket->sum('active_players');
            $new[] = (int) $bucket->sum('new_players');
        }
        $datasets = [
            ['label' => __('panel.active_players'), 'data' => $active],
        ];
        if ($includeNew) {
            $datasets[] = ['label' => __('panel.new_players'), 'data' => $new];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /**
     * @return array{from: string, to: string, previous_from: string, previous_to: string}
     */
    public function range(User $viewer): array
    {
        $zone = $viewer->timezone ?: 'UTC';
        $today = now($zone)->toDateString();
        $from = $this->date(request('from')) ?? now($zone)->subDays(29)->toDateString();
        $to = $this->date(request('to')) ?? $today;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $previousTo = Carbon::parse($from)->subDay()->toDateString();
        $previousFrom = Carbon::parse($previousTo)->subDays($days - 1)->toDateString();

        return [
            'from' => $from,
            'to' => $to,
            'previous_from' => $previousFrom,
            'previous_to' => $previousTo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operations(User $viewer, FootballBudget $budget): array
    {
        $zone = $viewer->timezone ?: 'UTC';
        $format = function (?SportSyncState $state) use ($zone): string {
            if ($state?->last_synced_at === null) {
                return __('panel.empty_value');
            }

            return $state->last_synced_at->copy()->timezone($zone)->format('d.m.Y H:i');
        };
        $voidStatuses = [...config('sport.void_statuses'), ...config('sport.wait_statuses')];
        $approachFrom = now()->subHours((int) config('sport.void_after_hours'))->addHours(6);

        return [
            'used' => $budget->used(),
            'remaining' => $budget->remaining(),
            'settle' => $format(SportSyncState::query()->where('code', 'settle-check')->first()),
            'live' => $format(SportSyncState::query()->where('code', 'live-sync')->first()),
            'manual' => CouponSelection::query()
                ->where('status', 'pending')
                ->where('kickoff_at', '<=', now()->subMinutes((int) config('sport.settle_after_minutes')))
                ->count(),
            'approaching' => CouponSelection::query()
                ->where('status', 'pending')
                ->where('kickoff_at', '<=', $approachFrom)
                ->whereHas('fixture', fn ($query) => $query->whereIn('status', $voidStatuses))
                ->count(),
            'risky' => Coupon::query()->where('status', 'pending')->count(),
        ];
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function rows(array $accountIds, string $from, string $to, string $product): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        return DailyStat::query()
            ->whereIn('user_id', $accountIds)
            ->where('product', $product)
            ->whereDate('stat_date', '>=', $from)
            ->whereDate('stat_date', '<=', $to)
            ->get();
    }

    private function sum(Collection $rows, string $field): string
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->{$field}, 2);
        }

        return $total;
    }

    /**
     * @param  list<int>  $accountIds
     * @return Collection<int, User>
     */
    private function membersOf(array $accountIds): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $accounts = User::query()->whereIn('id', $accountIds)->get();
        $superIds = [];
        $bayiIds = [];
        foreach ($accounts as $account) {
            if ($account->role === UserRole::Superadmin) {
                $superIds[] = $account->id;
            } elseif ($account->role === UserRole::Bayi) {
                $bayiIds[] = $account->id;
            }
        }

        return User::query()
            ->where('role', UserRole::Uye)
            ->where(function ($query) use ($superIds, $bayiIds): void {
                $query->whereIn('superadmin_id', $superIds === [] ? [0] : $superIds)
                    ->orWhereIn('parent_id', $bayiIds === [] ? [0] : $bayiIds);
            })
            ->get();
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  list<int>  $accountIds
     */
    private function activePlayers(Collection $members, array $accountIds, string $from, string $to): int
    {
        if ($members->isEmpty()) {
            return 0;
        }

        $accounts = User::query()->whereIn('id', $accountIds)->get()->keyBy('id');
        $count = [];
        foreach ($members as $member) {
            $account = $accounts->get($member->parent_id) ?? $accounts->get($member->superadmin_id);
            if ($account === null && $accounts->count() === 1) {
                $account = $accounts->first();
            }
            $zone = $account?->timezone ?: 'UTC';
            $start = Carbon::parse($from, $zone)->startOfDay()->utc();
            $end = Carbon::parse($to, $zone)->addDay()->startOfDay()->utc();
            $played = WalletTransaction::query()
                ->where('user_id', $member->id)
                ->where('type', WalletTransactionType::Bet)
                ->whereIn('product', DailyStatWriter::PRODUCTS)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->exists();
            if ($played) {
                $count[$member->id] = true;
            }
        }

        return count($count);
    }

    /**
     * @param  list<int>  $accountIds
     * @param  Collection<int, User>  $members
     * @return array{count: int, risk: string}
     */
    private function openCoupons(array $accountIds, Collection $members): array
    {
        $superIds = User::query()->whereIn('id', $accountIds)->where('role', UserRole::Superadmin)->pluck('id');
        $query = Coupon::query()->where('status', 'pending');
        if ($superIds->isNotEmpty()) {
            $query->whereIn('superadmin_id', $superIds);
        } else {
            $query->whereIn('user_id', $members->pluck('id')->all() ?: [0]);
        }
        $rows = $query->get(['potential_win']);
        $risk = '0.00';
        foreach ($rows as $row) {
            $risk = bcadd($risk, (string) $row->potential_win, 2);
        }

        return ['count' => $rows->count(), 'risk' => $risk];
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function overdraft(Collection $members): string
    {
        if ($members->isEmpty()) {
            return '0.00';
        }

        $total = '0.00';
        $amounts = Wallet::query()->whereIn('user_id', $members->pluck('id'))->pluck('settlement_overdraft_amount');
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }

    /**
     * @return array{label: string, value: string, change: float|null}
     */
    private function card(string $label, string $value, ?float $change): array
    {
        return ['label' => $label, 'value' => $value, 'change' => $change];
    }

    private function change(string $current, string $previous): ?float
    {
        if (bccomp($previous, '0', 2) === 0) {
            return null;
        }

        return ((float) $current - (float) $previous) / (float) $previous * 100;
    }

    private function currency(mixed $value): Currency
    {
        $currency = Currency::tryFrom((string) $value);

        return $currency ?? Currency::Try;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function days(string $from, string $to): array
    {
        $days = [];
        for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    private function badge(UserStatus $status): HtmlString
    {
        $tone = match ($status) {
            UserStatus::Active => 'success',
            UserStatus::Banned => 'danger',
            default => 'warning',
        };
        $html = Blade::render('<x-panel.badge :tone="$tone">{{ $label }}</x-panel.badge>', [
            'tone' => $tone,
            'label' => __($this->statusKey($status)),
        ]);

        return new HtmlString($html);
    }

    private function statusKey(UserStatus $status): string
    {
        return 'panel.statuses.'.$status->value;
    }
}
