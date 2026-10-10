<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\TipoCoupon;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\LedgerDetail;
use App\Support\Money;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Üye bakiye yükleme/çekme ve oyuncu oyun hareketleri. Kapsam: izleyicinin ağacı. */
class MovementReportController extends Controller
{
    public function members(Request $request): View
    {
        $actor = $this->actor($request);
        $window = $this->window($request, $actor);
        $members = $this->membersOf($actor);
        $memberId = $this->selectedMember($request, $members);
        $direction = in_array($request->query('direction'), ['load', 'withdraw'], true) ? (string) $request->query('direction') : 'all';
        $ids = $memberId !== null ? [$memberId] : $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        $query = $this->memberQuery($ids, $window, $direction);
        $rows = $query->paginate(50)->withQueryString()->through(
            fn (WalletTransaction $row) => $this->presentMember($row, $actor, $window['zone']),
        );

        return view('panel.reports.member-movements', [
            'period' => $window['period'],
            'from' => $window['from'],
            'to' => $window['to'],
            'periods' => ReportPeriod::PERIODS,
            'members' => $members,
            'direction' => $direction,
            'totals' => $this->memberTotals($ids, $window, $direction, $actor),
            'rows' => $rows,
        ]);
    }

    public function players(Request $request): View
    {
        $actor = $this->actor($request);
        $window = $this->window($request, $actor);
        $members = $this->membersOf($actor);
        $memberId = $this->selectedMember($request, $members);
        $type = in_array($request->query('type'), ['bet', 'win', 'refund'], true) ? (string) $request->query('type') : 'all';
        $product = in_array($request->query('product'), ['sport', 'casino', 'slot', 'mini'], true) ? (string) $request->query('product') : 'all';
        $ids = $memberId !== null ? [$memberId] : $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        $query = $this->playerQuery($ids, $window, $type, $product);
        $page = $query->paginate(50)->withQueryString();
        $models = LedgerDetail::withGameNames($page->getCollection());
        $links = $this->detailLinks($models);
        $page->setCollection($models->map(fn (WalletTransaction $row) => $this->presentPlayer($row, $window['zone'], $links)));

        return view('panel.reports.player-movements', [
            'period' => $window['period'],
            'from' => $window['from'],
            'to' => $window['to'],
            'periods' => ReportPeriod::PERIODS,
            'members' => $members,
            'type' => $type,
            'product' => $product,
            'rows' => $page,
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, [UserRole::Owner, UserRole::Superadmin, UserRole::Bayi], true), 404);

        return $actor;
    }

    /** @return array{period: string, from: string, to: string, fromUtc: \Illuminate\Support\Carbon, toUtc: \Illuminate\Support\Carbon, zone: string} */
    private function window(Request $request, User $actor): array
    {
        $zone = $actor->timezone ?: 'Europe/Istanbul';
        [$period, $fromLocal, $toLocal] = ReportPeriod::resolve($request, $zone);

        return [
            'period' => $period,
            'from' => $fromLocal->toDateString(),
            'to' => $toLocal->toDateString(),
            'fromUtc' => $fromLocal->copy()->utc(),
            'toUtc' => $toLocal->copy()->addDay()->startOfDay()->utc(),
            'zone' => $zone,
        ];
    }

    /** @return Collection<int, User> */
    private function membersOf(User $actor): Collection
    {
        return User::query()->subtreeOf($actor)->where('role', UserRole::Uye)->orderBy('username')->get(['id', 'username']);
    }

    /** @param  Collection<int, User>  $members */
    private function selectedMember(Request $request, Collection $members): ?int
    {
        if (! $request->filled('member')) {
            return null;
        }
        $id = (int) $request->query('member');
        $member = User::query()->whereKey($id)->first();
        abort_if($member === null, 404);
        abort_unless($members->contains(fn (User $row) => (int) $row->id === $id), 404);

        return $id;
    }

    /**
     * @param  list<int>  $ids
     * @param  array{fromUtc: \Illuminate\Support\Carbon, toUtc: \Illuminate\Support\Carbon}  $window
     * @return Builder<WalletTransaction>
     */
    private function memberQuery(array $ids, array $window, string $direction): Builder
    {
        $query = WalletTransaction::query()
            ->with([
                'user' => fn ($q) => $q->withTrashed(),
                'creator' => fn ($q) => $q->withTrashed(),
                'wallet',
            ])
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', $window['fromUtc'])
            ->where('created_at', '<', $window['toUtc'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($direction === 'load') {
            $query->where('type', WalletTransactionType::TransferIn);
        } elseif ($direction === 'withdraw') {
            $query->where('type', WalletTransactionType::TransferOut);
        } else {
            $query->whereIn('type', [WalletTransactionType::TransferIn, WalletTransactionType::TransferOut]);
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids
     * @param  array{fromUtc: \Illuminate\Support\Carbon, toUtc: \Illuminate\Support\Carbon}  $window
     * @return Builder<WalletTransaction>
     */
    private function playerQuery(array $ids, array $window, string $type, string $product): Builder
    {
        $query = WalletTransaction::query()
            ->with([
                'user' => fn ($q) => $q->withTrashed(),
                'wallet',
            ])
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', $window['fromUtc'])
            ->where('created_at', '<', $window['toUtc'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($type === 'all') {
            $query->whereIn('type', [WalletTransactionType::Bet, WalletTransactionType::Win, WalletTransactionType::Refund, WalletTransactionType::Cashout]);
        } else {
            $query->where('type', $type);
        }

        $this->applyProduct($query, $product);

        return $query;
    }

    /** @param  Builder<WalletTransaction>  $query */
    private function applyProduct(Builder $query, string $product): void
    {
        if ($product === 'sport') {
            $query->where('product', WalletProduct::Sport);

            return;
        }
        if ($product === 'casino') {
            $query->where('product', WalletProduct::LiveCasino);

            return;
        }
        if ($product === 'slot') {
            $query->where('product', WalletProduct::Slot)->whereNotExists(fn ($sub) => $this->miniRound($sub));

            return;
        }
        if ($product === 'mini') {
            $query->where('product', WalletProduct::Slot)->whereExists(fn ($sub) => $this->miniRound($sub));
        }
    }

    /** Mini oyunlar cüzdanda slot ürünü olarak durur; ayrım casino_games.category üzerindendir. */
    private function miniRound(object $sub): void
    {
        $sub->selectRaw('1')
            ->from('game_rounds as gr')
            ->join('casino_games as cg', 'cg.id', '=', 'gr.game_id')
            ->where('cg.category', 'mini')
            ->whereRaw("gr.provider = SUBSTR(wallet_transactions.idempotency_key, 1, INSTR(wallet_transactions.idempotency_key, ':') - 1)")
            ->whereRaw("gr.provider_transaction_id = SUBSTR(wallet_transactions.idempotency_key, INSTR(wallet_transactions.idempotency_key, ':') + 1)");
    }

    /**
     * @param  list<int>  $ids
     * @param  array{fromUtc: \Illuminate\Support\Carbon, toUtc: \Illuminate\Support\Carbon}  $window
     * @return list<array{code: string, loaded: string, withdrawn: string, net: string, net_tone: string}>
     */
    private function memberTotals(array $ids, array $window, string $direction, User $actor): array
    {
        $sums = collect();
        $codes = collect();

        if ($ids !== []) {
            $query = DB::table('wallet_transactions as wt')
                ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
                ->whereIn('wt.user_id', $ids)
                ->whereIn('wt.type', [WalletTransactionType::TransferIn->value, WalletTransactionType::TransferOut->value])
                ->where('wt.created_at', '>=', $window['fromUtc'])
                ->where('wt.created_at', '<', $window['toUtc']);
            if ($direction === 'load') {
                $query->where('wt.type', WalletTransactionType::TransferIn->value);
            } elseif ($direction === 'withdraw') {
                $query->where('wt.type', WalletTransactionType::TransferOut->value);
            }
            $sums = $query->groupBy('w.currency')
                ->selectRaw("w.currency as code, SUM(CASE WHEN wt.type = 'transfer_in' THEN wt.amount ELSE 0 END) as loaded, SUM(CASE WHEN wt.type = 'transfer_out' THEN -wt.amount ELSE 0 END) as withdrawn")
                ->get()
                ->keyBy('code');
            $codes = DB::table('wallets')->whereIn('user_id', $ids)->distinct()->pluck('currency');
        }

        foreach ($sums as $code => $row) {
            if (! $codes->contains($code)) {
                $codes->push($code);
            }
        }
        if ($codes->isEmpty()) {
            $codes = collect([$actor->currency->value]);
        }

        $own = $actor->currency->value;

        return $codes->unique()->sortBy(fn ($code) => ($code === $own ? '0' : '1').$code)->values()->map(function ($code) use ($sums) {
            $row = $sums->get($code);
            $loaded = bcadd((string) ($row->loaded ?? '0'), '0', 2);
            $withdrawn = bcadd((string) ($row->withdrawn ?? '0'), '0', 2);
            $net = bcsub($loaded, $withdrawn, 2);
            $currency = Currency::from((string) $code);

            return [
                'code' => (string) $code,
                'loaded' => Money::format($loaded, $currency),
                'withdrawn' => Money::format($withdrawn, $currency),
                'net' => Money::format($net, $currency),
                'net_tone' => bccomp($net, '0', 2) === -1 ? 'text-red-700' : (bccomp($net, '0', 2) === 1 ? 'text-emerald-700' : ''),
            ];
        })->all();
    }

    /** @return array{user: string, amount: string, tone: string, type: string, after: string, when: string, by: string} */
    private function presentMember(WalletTransaction $row, User $actor, string $zone): array
    {
        $load = $row->type === WalletTransactionType::TransferIn;
        $currency = $row->wallet->currency;
        $amount = bcadd((string) $row->amount, '0', 2);

        return [
            'user' => ($row->user?->username ?? __('panel.empty_value')).' · '.$row->user_id,
            'amount' => Money::formatSigned($amount, $currency),
            'tone' => $load ? 'text-emerald-700' : 'text-red-700',
            'type' => $load ? __('panel.member_movements_load') : __('panel.member_movements_withdraw'),
            'after' => Money::format(bcadd((string) $row->balance_after, '0', 2), $currency),
            'when' => $row->created_at->timezone($zone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
            'by' => $this->visibleName($row->creator, $actor),
        ];
    }

    /**
     * @param  array<string, string>  $links
     * @return array{when: string, user: string, type: string, description: string, amount: string, tone: string, detail: ?string}
     */
    private function presentPlayer(WalletTransaction $row, string $zone, array $links): array
    {
        $amount = bcadd((string) $row->amount, '0', 2);
        $description = LedgerDetail::for($row);
        if ($description === '') {
            $description = (string) ($row->note ?: __('panel.empty_value'));
        }

        return [
            'when' => $row->created_at->timezone($zone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
            'user' => ($row->user?->username ?? __('panel.empty_value')).' · '.$row->user_id,
            'type' => __('wallet.types.'.$row->type->value),
            'description' => $description,
            'amount' => Money::formatSigned($amount, $row->wallet->currency),
            'tone' => bccomp($amount, '0', 2) === -1 ? 'text-red-700' : 'text-emerald-700',
            'detail' => $links[$row->id] ?? null,
        ];
    }

    /**
     * @param  Collection<int, WalletTransaction>  $rows
     * @return array<string, string>
     */
    private function detailLinks(Collection $rows): array
    {
        $couponNos = [];
        $betIds = [];
        foreach ($rows as $row) {
            if ($this->productOf($row) !== 'sport') {
                continue;
            }
            $ref = (string) $row->reference;
            if (str_starts_with($ref, 'tipo:')) {
                $betIds[] = (int) substr($ref, 5);
            } elseif ($ref !== '') {
                $couponNos[] = $ref;
            }
        }

        $coupons = $couponNos === [] ? collect() : Coupon::query()->whereIn('coupon_no', array_values(array_unique($couponNos)))->pluck('id', 'coupon_no');
        $tipos = $betIds === [] ? collect() : TipoCoupon::query()->whereIn('bet_id', array_values(array_unique($betIds)))->pluck('id', 'bet_id');
        $links = [];

        foreach ($rows as $row) {
            $product = $this->productOf($row);
            $ref = (string) $row->reference;
            if ($product === 'sport' && str_starts_with($ref, 'tipo:')) {
                $id = $tipos->get((int) substr($ref, 5));
                if ($id !== null) {
                    $links[$row->id] = route('panel.coupons.tipo', $id);
                }

                continue;
            }
            if ($product === 'sport' && $ref !== '' && $coupons->has($ref)) {
                $links[$row->id] = route('panel.coupons.show', $coupons->get($ref));

                continue;
            }
            if (in_array($product, ['slot', 'live_casino'], true) && $row->user !== null) {
                $links[$row->id] = route('panel.casino.rounds', ['username' => $row->user->username]);
            }
        }

        return $links;
    }

    private function productOf(WalletTransaction $row): string
    {
        return $row->product instanceof \BackedEnum ? $row->product->value : (string) $row->product;
    }

    private function visibleName(?User $person, User $viewer): string
    {
        if ($person === null || $this->isAncestor($viewer, $person) || ! $person->isInSubtreeOf($viewer)) {
            return __('wallet.upper_account');
        }

        return $person->username;
    }

    private function isAncestor(User $viewer, User $person): bool
    {
        return $person->id !== $viewer->id && str_starts_with($viewer->path, $person->path);
    }
}
