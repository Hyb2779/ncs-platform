<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustBalanceRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletException;
use App\Services\WalletService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function adjust(AdjustBalanceRequest $request, User $user, WalletService $wallets): RedirectResponse
    {
        $actor = $request->user();
        // Owner (kök + alt) ağacındaki herkese; süperadmin kendi bayilerine + ağacındaki üyelere; bayi kendi üyelerine.
        abort_unless(
            $user->id !== $actor->id && (
                ($actor->role === \App\Enums\UserRole::Owner && $user->isInSubtreeOf($actor))
                || $user->parent_id === $actor->id
                || ($actor->role === \App\Enums\UserRole::Superadmin && $user->role === \App\Enums\UserRole::Uye && $user->isInSubtreeOf($actor))
            ),
            403,
        );

        $amount = $request->string('amount')->toString();
        $currency = $request->filled('currency') ? \App\Enums\Currency::tryFrom($request->string('currency')->toString()) : null;
        if ($request->filled('currency') && $currency === null) {
            return back()->withInput()->withErrors(['amount' => __('wallet.errors.currency_mismatch')]);
        }

        if (bccomp($amount, '0', 2) !== 1) {
            return back()->withInput()->withErrors(['amount' => __('wallet.validation.amount_invalid')]);
        }

        try {
            if ($request->string('direction')->toString() === 'add') {
                $wallets->transfer($actor, $user, $amount, $request->string('idempotency_key')->toString(), $actor, $request->input('note'), $request->ip(), $currency);
            } else {
                $wallets->transfer($user, $actor, $amount, $request->string('idempotency_key')->toString(), $actor, $request->input('note'), $request->ip(), $currency);
            }
        } catch (WalletException $exception) {
            $key = match ($exception->translationKey) {
                'wallet.insufficient_balance' => 'wallet.errors.insufficient_balance',
                'wallet.currency_mismatch' => 'wallet.errors.currency_mismatch',
                default => 'wallet.errors.invalid_amount',
            };

            return back()->withInput()->withErrors(['amount' => __($key)]);
        }

        return back()->with('status', __('wallet.adjusted'));
    }

    /** Bakiye Ekle/Çıkar: önce tip, sonra hesap; işlem mevcut adjust rotasına gider. */
    public function page(Request $request): View
    {
        $actor = $request->user();
        $types = match ($actor->role) {
            \App\Enums\UserRole::Owner => ['superadmin', 'bayi', 'uye'],
            \App\Enums\UserRole::Superadmin => ['bayi', 'uye'],
            \App\Enums\UserRole::Bayi => ['uye'],
            default => [],
        };
        abort_if($types === [], 404);
        if ($actor->isRootOwner() && User::query()->where('parent_id', $actor->id)->where('role', 'owner')->exists()) {
            $types[] = 'owner';
        }

        $symbols = collect(Currency::cases())->mapWithKeys(fn (Currency $currency) => [$currency->value => $currency->symbol()])->all();
        $users = User::query()->subtreeOf($actor)->whereKeyNot($actor->id)->whereIn('role', $types)
            ->with('wallets')->orderBy('username')->get();
        $parents = User::query()->whereIn('id', $users->pluck('parent_id')->filter()->unique())->pluck('username', 'id');

        $accounts = array_fill_keys($types, []);
        foreach ($users as $user) {
            $wallet = $user->wallets->firstWhere('currency', $user->currency);
            $parentName = (string) ($parents[$user->parent_id] ?? '');
            $sub = $parentName;
            if ($user->status->value !== 'active') {
                $sub = trim($sub.' · '.__('panel.statuses.'.$user->status->value), ' ·');
            }
            $accounts[$user->role->value][] = [
                'id' => $user->id,
                'name' => $user->username,
                'sub' => $sub,
                'label' => mb_strtolower($user->username.' '.$parentName),
                'balance' => (string) ($wallet?->balance ?? '0'),
                'symbol' => $symbols[$user->currency->value] ?? '',
                'currency' => $user->currency->value,
                'balances' => $user->isMultiCurrency() ? $user->wallets->mapWithKeys(fn ($w) => [$w->currency->value => (string) $w->balance])->all() : null,
            ];
        }

        $me = [
            'own' => (string) ($actor->wallets()->where('currency', $actor->currency)->value('balance') ?? '0'),
            'ownBy' => $actor->wallets()->get()->mapWithKeys(fn ($w) => [$w->currency->value => (string) $w->balance])->all(),
            'symbols' => $symbols,
            'unlimited' => $actor->isRootOwner(),
        ];

        return view('panel.wallets.balance', compact('types', 'accounts', 'me'));
    }

    public function transactions(Request $request): View
    {
        $actor = $request->user();
        $ownAccount = $request->query('user') === 'self';
        $subject = $actor;

        if ($request->filled('user') && ! $ownAccount) {
            $subject = User::query()->whereKey((int) $request->query('user'))->first();
            abort_if($subject === null, 404);
            abort_unless($subject->isInSubtreeOf($actor), 404);
            abort_if($actor->role === UserRole::Superadmin && $subject->role !== UserRole::Bayi, 404);
        }

        $focusedId = $ownAccount ? $actor->id : ($request->filled('user') ? $subject->id : null);

        // Süperadmin "Bayi hareketleri": varsayılan liste yalnızca bayi cüzdanlarının kredi hareketi.
        $dealerScope = $actor->role === UserRole::Superadmin && ! $ownAccount && $focusedId === null;
        $scopeIds = $focusedId !== null
            ? [$focusedId]
            : ($dealerScope
                ? User::query()->subtreeOf($actor)->where('role', UserRole::Bayi)->pluck('id')
                : User::query()->subtreeOf($actor)->pluck('id'));

        $balanceTypes = [
            WalletTransactionType::Mint->value,
            WalletTransactionType::TransferIn->value,
            WalletTransactionType::TransferOut->value,
            WalletTransactionType::Bonus->value,
            WalletTransactionType::Adjustment->value,
        ];

        $query = WalletTransaction::query()
            ->with(['user', 'creator', 'wallet', 'counterparty'])
            ->whereIn('user_id', $scopeIds)
            ->whereIn('type', $balanceTypes)
            ->whereNotIn('product', [WalletProduct::Sport->value, WalletProduct::Slot->value, WalletProduct::LiveCasino->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('type') && in_array($request->query('type'), $balanceTypes, true)) {
            $query->where('type', $request->query('type'));
        }

        $this->applyCreatedRange($query, $request, $actor);

        // Tek hesap veya yalnızca bayi cüzdanı: her transferin bir bacağı kapsamda, SQL sayfalama güvenli.
        $singleLeg = $focusedId !== null || $dealerScope;
        $preferIds = $actor->role === UserRole::Superadmin && ! $ownAccount
            ? collect($scopeIds)->map(fn ($id) => (int) $id)->all()
            : [];

        if ($singleLeg) {
            $ledger = $query->paginate(50)->withQueryString();
            $display = $this->ledgerEntries($this->withTransferPartners($ledger->getCollection()), $actor, $focusedId, $preferIds);
        } else {
            $all = $this->ledgerEntries($this->withTransferPartners($query->get()), $actor, $focusedId, $preferIds);
            $ledger = $this->paginateEntries($all, $request);
            $display = collect($ledger->items());
        }

        $subjects = User::query()->subtreeOf($actor)->whereKeyNot($actor->id)->orderBy('username');
        if ($actor->role === UserRole::Superadmin) {
            $subjects->where('role', UserRole::Bayi);
        }

        return view('panel.wallets.transactions', [
            'rows' => $display,
            'ledger' => $ledger,
            'subjects' => $subjects->get(['id', 'username']),
            'dealerLedger' => $actor->role === UserRole::Superadmin,
            'totals' => $actor->role === UserRole::Superadmin && ! $ownAccount
                ? $this->scopedTransferTotals($scopeIds, $request, $actor)
                : $this->viewerTotals($actor, $request, $ownAccount),
            'ownAccount' => $ownAccount,
            'selectedUser' => $request->query('user'),
            'dealerScope' => $dealerScope,
        ]);
    }

    /**
     * @param  Collection<int, WalletTransaction>  $rows
     * @return Collection<int, WalletTransaction>
     */
    private function withTransferPartners($rows)
    {
        $known = $rows->pluck('id');
        $missing = $rows->pluck('reference')->filter()->reject(fn ($id) => $known->contains($id))->values();

        if ($missing->isEmpty()) {
            return $rows;
        }

        return $rows->concat(
            WalletTransaction::query()->with(['user', 'creator', 'wallet', 'counterparty'])->whereIn('id', $missing)->get(),
        );
    }

    /**
     * @param  Collection<int, WalletTransaction>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function ledgerEntries($rows, User $actor, ?int $focusedId, array $preferIds = [])
    {
        $byId = $rows->keyBy('id');
        $seen = [];
        $entries = [];

        foreach ($rows->sortByDesc(fn (WalletTransaction $row) => $row->created_at->getTimestamp().$row->id) as $row) {
            if (isset($seen[$row->id])) {
                continue;
            }

            $partner = $row->reference ? $byId->get($row->reference) : null;
            $isPair = $partner !== null && in_array($row->type, [WalletTransactionType::TransferIn, WalletTransactionType::TransferOut], true);

            if ($isPair) {
                $seen[$row->id] = true;
                $seen[$partner->id] = true;
                $out = $row->type === WalletTransactionType::TransferOut ? $row : $partner;
                $in = $row->type === WalletTransactionType::TransferIn ? $row : $partner;
                $entries[] = $this->presentTransfer($out, $in, $actor, $focusedId, $preferIds);

                continue;
            }

            if ($focusedId !== null && $row->user_id !== $focusedId) {
                continue;
            }

            $seen[$row->id] = true;
            $entries[] = $this->presentSingle($row, $actor);
        }

        return collect($entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTransfer(WalletTransaction $out, WalletTransaction $in, User $actor, ?int $focusedId, array $preferIds = []): array
    {
        $subject = $this->subjectLeg($out, $in, $actor, $focusedId, $preferIds);
        $signed = bcadd((string) $subject->amount, '0', 2);
        $before = bcadd((string) $subject->balance_before, '0', 2);
        $after = bcadd((string) $subject->balance_after, '0', 2);
        $positive = bccomp($signed, '0', 2) === 1;

        return [
            'when' => $subject->created_at->timezone($actor->timezone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
            'account' => $this->visibleName($subject->user, $actor),
            'parties' => $this->visibleName($out->user, $actor).' → '.$this->visibleName($in->user, $actor),
            'before' => Money::format($before, $subject->wallet->currency),
            'amount' => Money::formatSigned($signed, $subject->wallet->currency),
            'after' => Money::format($after, $subject->wallet->currency),
            'raw_before' => $before,
            'raw_amount' => $signed,
            'raw_after' => $after,
            'type' => __('wallet.types.'.$subject->type->value),
            'tone' => $positive ? 'text-emerald-800' : 'text-red-700',
            'movement' => $actor->role === UserRole::Owner && ($out->user_id === $actor->id || $in->user_id === $actor->id)
                ? ($positive ? __('wallet.given') : __('wallet.taken_back'))
                : null,
            'note' => ($out->note ?: $in->note) ?: __('panel.empty_value'),
            'ip' => ($out->ip ?: $in->ip) ?: __('panel.empty_value'),
            'detail' => $subject->type === WalletTransactionType::TransferIn ? __('wallet.detail_load') : __('wallet.detail_unload'),
            'by' => $this->visibleName($out->creator ?? $in->creator, $actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSingle(WalletTransaction $row, User $actor): array
    {
        $signed = bcadd((string) $row->amount, '0', 2);
        $before = bcadd((string) $row->balance_before, '0', 2);
        $after = bcadd((string) $row->balance_after, '0', 2);
        $positive = bccomp($signed, '0', 2) !== -1;

        return [
            'when' => $row->created_at->timezone($actor->timezone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
            'account' => $this->visibleName($row->user, $actor),
            'parties' => $this->visibleName($row->creator, $actor).' → '.$this->visibleName($row->user, $actor),
            'before' => Money::format($before, $row->wallet->currency),
            'amount' => Money::formatSigned($signed, $row->wallet->currency),
            'after' => Money::format($after, $row->wallet->currency),
            'raw_before' => $before,
            'raw_amount' => $signed,
            'raw_after' => $after,
            'type' => __('wallet.types.'.$row->type->value),
            'tone' => $positive ? 'text-emerald-800' : 'text-red-700',
            'movement' => null,
            'note' => $row->note ?: __('panel.empty_value'),
            'ip' => $row->ip ?: __('panel.empty_value'),
            'detail' => \App\Support\LedgerDetail::for($row) ?: __('panel.empty_value'),
            'by' => $this->visibleName($row->creator, $actor),
        ];
    }

    private function subjectLeg(WalletTransaction $out, WalletTransaction $in, User $actor, ?int $focusedId, array $preferIds = []): WalletTransaction
    {
        if ($preferIds !== []) {
            $prefer = array_map('intval', $preferIds);
            if (in_array((int) $out->user_id, $prefer, true)) {
                return $out;
            }
            if (in_array((int) $in->user_id, $prefer, true)) {
                return $in;
            }
        }

        $fromIsAncestor = $this->isAncestor($actor, $out->user);
        $toIsAncestor = $this->isAncestor($actor, $in->user);

        if ($fromIsAncestor && ! $toIsAncestor) {
            return $in;
        }

        if ($toIsAncestor && ! $fromIsAncestor) {
            return $out;
        }

        if ($focusedId === $actor->id) {
            if ($out->user_id === $actor->id) {
                return $out;
            }

            if ($in->user_id === $actor->id) {
                return $in;
            }
        }

        if ($focusedId !== null) {
            if ($out->user_id === $focusedId) {
                return $out;
            }

            if ($in->user_id === $focusedId) {
                return $in;
            }
        }

        return $out->user->depth >= $in->user->depth ? $out : $in;
    }

    private function viewerTotals(User $actor, Request $request, bool $ownAccount)
    {
        $query = WalletTransaction::query()
            ->with(['wallet', 'counterparty'])
            ->where('user_id', $actor->id)
            ->whereIn('type', [WalletTransactionType::TransferOut, WalletTransactionType::TransferIn]);

        $this->applyCreatedRange($query, $request, $actor);

        $totals = [];

        foreach ($query->get() as $row) {
            $other = $row->counterparty;

            if ($other === null) {
                continue;
            }

            $withAncestor = $this->isAncestor($actor, $other);
            $withDescendant = ! $withAncestor && $other->id !== $actor->id && $other->isInSubtreeOf($actor);

            if ($ownAccount && ! $withAncestor) {
                continue;
            }

            if (! $ownAccount && ! $withDescendant) {
                continue;
            }

            $code = $row->wallet->currency->value;
            $totals[$code] ??= ['added' => '0.00', 'removed' => '0.00', 'currency' => $row->wallet->currency];
            $amount = bcadd((string) $row->amount, '0', 2);
            $absolute = bccomp($amount, '0', 2) < 0 ? bcsub('0', $amount, 2) : $amount;

            $countsAsAdded = $ownAccount
                ? $row->type === WalletTransactionType::TransferIn
                : $row->type === WalletTransactionType::TransferOut;

            if ($countsAsAdded) {
                $totals[$code]['added'] = bcadd($totals[$code]['added'], $absolute, 2);
            } else {
                $totals[$code]['removed'] = bcadd($totals[$code]['removed'], $absolute, 2);
            }
        }

        if ($totals === []) {
            $totals[$actor->currency->value] = ['added' => '0.00', 'removed' => '0.00', 'currency' => $actor->currency];
        }

        $own = $actor->currency->value;
        uksort($totals, function (string $left, string $right) use ($own): int {
            if ($left === $own) {
                return -1;
            }

            if ($right === $own) {
                return 1;
            }

            return strcmp($left, $right);
        });

        return collect($totals)->map(fn (array $total) => [
            'added' => Money::format($total['added'], $total['currency']),
            'removed' => Money::format($total['removed'], $total['currency']),
            'difference' => Money::format(bcsub($total['added'], $total['removed'], 2), $total['currency']),
        ])->values();
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

    private function applyCreatedRange(object $query, Request $request, User $actor, string $column = 'created_at'): void
    {
        if ($request->filled('from')) {
            $query->where($column, '>=', Carbon::parse($request->query('from'), $actor->timezone)->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where($column, '<=', Carbon::parse($request->query('to'), $actor->timezone)->endOfDay()->utc());
        }
    }

    /**
     * Bayi cüzdanındaki yükleme/çekme: para birimi ayrı, eklenen − çıkarılan = fark.
     *
     * @param  Collection<int, int>|list<int>  $userIds
     */
    private function scopedTransferTotals(Collection|array $userIds, Request $request, User $actor): Collection
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        $rows = collect();

        if ($ids->isNotEmpty()) {
            $query = DB::table('wallet_transactions as wt')
                ->join('wallets as w', 'w.id', '=', 'wt.wallet_id')
                ->whereIn('wt.user_id', $ids->all())
                ->whereIn('wt.type', [WalletTransactionType::TransferIn->value, WalletTransactionType::TransferOut->value])
                ->groupBy('w.currency')
                ->selectRaw('w.currency as code, SUM(CASE WHEN wt.amount > 0 THEN wt.amount ELSE 0 END) as added, SUM(CASE WHEN wt.amount < 0 THEN -wt.amount ELSE 0 END) as removed');
            $this->applyCreatedRange($query, $request, $actor, 'wt.created_at');
            $rows = $query->get();
        }

        if ($rows->isEmpty()) {
            $rows = collect([(object) ['code' => $actor->currency->value, 'added' => '0', 'removed' => '0']]);
        }

        $own = $actor->currency->value;
        $sorted = $rows->sortBy(function ($row) use ($own): string {
            return ($row->code === $own ? '0' : '1').$row->code;
        })->values();

        return $sorted->map(function ($row) {
            $currency = \App\Enums\Currency::from((string) $row->code);
            $added = bcadd((string) $row->added, '0', 2);
            $removed = bcadd((string) $row->removed, '0', 2);

            return [
                'added' => Money::format($added, $currency),
                'removed' => Money::format($removed, $currency),
                'difference' => Money::format(bcsub($added, $removed, 2), $currency),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    private function paginateEntries(Collection $entries, Request $request): LengthAwarePaginator
    {
        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $entries->slice(($page - 1) * $perPage, $perPage)->values(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
