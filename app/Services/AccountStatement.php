<?php

namespace App\Services;

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\CasinoGame;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\LedgerDetail;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Üyenin kendi hesap dökümü. user_id yalnızca oturumdaki User kaydından gelir.
 */
class AccountStatement
{
    public const PER_PAGE = 20;

    public const MAX_DAYS = 92;

    public function __construct(private readonly GameImages $images) {}

    /**
     * @return array{
     *     tab: string,
     *     period: string,
     *     direction: string,
     *     from: string,
     *     to: string,
     *     page: int,
     *     zone: string,
     *     fromUtc: Carbon,
     *     toUtc: Carbon
     * }
     */
    public function filters(Request $request, User $user): array
    {
        $zone = $this->zone($user);
        $now = Carbon::now($zone);
        $today = $now->copy()->startOfDay();

        $period = (string) $request->query('period', '7d');

        if (! in_array($period, ['today', 'yesterday', '7d', 'month', 'custom'], true)) {
            $period = '7d';
        }

        if ($period === 'custom') {
            $from = $this->day($request->query('from'), $zone) ?? $today->copy()->subDays(6);
            $to = $this->day($request->query('to'), $zone) ?? $today->copy();
        } else {
            [$from, $to] = match ($period) {
                'today' => [$today->copy(), $today->copy()],
                'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
                'month' => [$today->copy()->startOfMonth(), $today->copy()],
                default => [$today->copy()->subDays(6), $today->copy()],
            };
        }

        if ($to->lt($from)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS);
        }

        $direction = $request->query('direction');
        $direction = in_array($direction, ['in', 'out'], true) ? $direction : 'all';

        $page = (int) $request->query('page', 1);

        return [
            'tab' => $request->query('tab') === 'games' ? 'games' : 'balance',
            'period' => $period,
            'direction' => $direction,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => max(1, min($page, 200)),
            'zone' => $zone,
            'fromUtc' => $from->copy()->utc(),
            'toUtc' => $to->copy()->addDay()->utc(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, string>>, next: ?string, summary: null}
     */
    public function balance(User $user, array $filters): array
    {
        $query = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $filters['fromUtc'])
            ->where('created_at', '<', $filters['toUtc']);

        if ($filters['direction'] === 'in') {
            $query->where('type', WalletTransactionType::TransferIn);
        } elseif ($filters['direction'] === 'out') {
            $query->where('type', WalletTransactionType::TransferOut);
        }

        $found = LedgerDetail::withGameNames($query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset(($filters['page'] - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get(['id', 'created_at', 'type', 'product', 'amount', 'balance_after', 'note', 'idempotency_key']));

        $hasMore = $found->count() > self::PER_PAGE;
        $rows = [];

        foreach ($found->take(self::PER_PAGE) as $row) {
            $amount = (string) $row->amount;
            $rows[] = [
                'when' => $row->created_at->timezone($filters['zone'])->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
                'label' => $this->label($row),
                'game' => $this->gameName($row),
                'amount' => Money::formatSigned($amount, $user->currency),
                'after' => Money::format((string) $row->balance_after, $user->currency),
                'tone' => $this->tone($amount),
            ];
        }

        return [
            'rows' => $rows,
            'next' => $this->nextUrl($filters, $hasMore),
            'summary' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, string>>, next: ?string, summary: array{bet: string, win: string, net: string, tone: string}}
     */
    public function games(User $user, array $filters): array
    {
        $base = DB::table('game_rounds as r')
            ->where('r.user_id', $user->id)
            ->where('r.created_at', '>=', $filters['fromUtc'])
            ->where('r.created_at', '<', $filters['toUtc']);

        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(r.bet), 0) as bet, COALESCE(SUM(r.win), 0) as win')
            ->first();

        $bet = $this->money($totals->bet ?? '0');
        $win = $this->money($totals->win ?? '0');
        $net = bcsub($win, $bet, 2);

        $found = (clone $base)
            ->leftJoin('casino_games as g', 'g.id', '=', 'r.game_id')
            ->groupBy('r.game_id')
            ->havingRaw('SUM(r.bet) <> 0 OR SUM(r.win) <> 0')
            ->selectRaw('r.game_id as game_id, MAX(g.name) as name, MAX(g.image_url) as image_url, MAX(g.category) as category, MAX(g.is_live) as is_live, SUM(r.bet) as bet, SUM(r.win) as win')
            ->orderByRaw('SUM(r.bet) DESC')
            ->orderBy('r.game_id')
            ->offset(($filters['page'] - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $hasMore = $found->count() > self::PER_PAGE;
        $rows = [];

        foreach ($found->take(self::PER_PAGE) as $row) {
            $rowBet = $this->money($row->bet);
            $rowWin = $this->money($row->win);
            $rowNet = bcsub($rowWin, $rowBet, 2);
            $rows[] = [
                'name' => $row->name !== null && $row->name !== '' ? (string) $row->name : __('account.unknown_game'),
                'image' => $this->image($row->game_id, $row->image_url),
                'category' => $this->category($row->category, $row->is_live),
                'bet' => Money::format($rowBet, $user->currency),
                'win' => Money::format($rowWin, $user->currency),
                'net' => Money::formatSigned($rowNet, $user->currency),
                'tone' => $this->tone($rowNet),
            ];
        }

        return [
            'rows' => $rows,
            'next' => $this->nextUrl($filters, $hasMore),
            'summary' => [
                'bet' => Money::format($bet, $user->currency),
                'win' => Money::format($win, $user->currency),
                'net' => Money::formatSigned($net, $user->currency),
                'tone' => $this->tone($net),
            ],
        ];
    }

    private function label(WalletTransaction $row): string
    {
        if ($row->product === WalletProduct::Sport) {
            return brand()->name().' '.__('site.sport');
        }

        if ($row->type === WalletTransactionType::TransferIn) {
            return __('account.loaded');
        }

        if ($row->type === WalletTransactionType::TransferOut) {
            return __('account.withdrawn');
        }

        $category = match ($row->product) {
            WalletProduct::Slot => __('account.cat_slot'),
            WalletProduct::LiveCasino => __('account.cat_live'),
            default => null,
        };
        $type = __('wallet.types.'.$row->type->value);

        return $category === null ? $type : $category.' · '.$type;
    }

    /** Slot ve canlı casino satırında oyun adı. Transfer notu ve spor referansı buraya yazılmaz. */
    private function gameName(WalletTransaction $row): string
    {
        if (! in_array($row->product, [WalletProduct::Slot, WalletProduct::LiveCasino], true)) {
            return '';
        }

        return trim((string) $row->note);
    }

    private function category(mixed $category, mixed $isLive): string
    {
        if ($isLive === true || $isLive === 1 || $isLive === '1') {
            return __('account.cat_live');
        }

        if ($category === 'mini') {
            return __('account.cat_mini');
        }

        if ($category === 'virtual') {
            return __('site.virtual');
        }

        return __('account.cat_slot');
    }

    private function image(mixed $gameId, mixed $imageUrl): string
    {
        if ($gameId === null || $imageUrl === null || $imageUrl === '') {
            return '';
        }

        $game = new CasinoGame;
        $game->id = (int) $gameId;
        $game->image_url = (string) $imageUrl;

        return (string) ($this->images->url($game) ?? '');
    }

    private function tone(string $amount): string
    {
        $cmp = bccomp($this->money($amount), '0', 2);

        return $cmp > 0 ? 'plus' : ($cmp < 0 ? 'minus' : 'zero');
    }

    private function money(mixed $amount): string
    {
        return bcadd((string) ($amount ?? '0'), '0', 2);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function nextUrl(array $filters, bool $hasMore): ?string
    {
        if (! $hasMore) {
            return null;
        }

        return route('site.account.movements', [
            'tab' => $filters['tab'],
            'period' => $filters['period'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'direction' => $filters['direction'],
            'page' => $filters['page'] + 1,
        ]);
    }

    private function zone(User $user): string
    {
        try {
            new \DateTimeZone((string) $user->timezone);

            return (string) $user->timezone;
        } catch (\Throwable) {
            return display_timezone();
        }
    }

    private function day(mixed $value, string $zone): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::parse($value, $zone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
