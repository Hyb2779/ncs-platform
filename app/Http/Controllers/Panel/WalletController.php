<?php

namespace App\Http\Controllers\Panel;

use App\Enums\UserRole;
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
use Illuminate\Support\Collection;
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
            404,
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

        $symbols = ['USD' => '$', 'EUR' => "\u{20AC}", 'TRY' => "\u{20BA}"];
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
            $subject = User::query()->subtreeOf($actor)->whereKey((int) $request->query('user'))->first();
            abort_if($subject === null, 404);
        }

        $focusedId = $ownAccount ? $actor->id : ($request->filled('user') ? $subject->id : null);

        $scopeIds = $focusedId !== null
            ? [$focusedId]
            : User::query()->subtreeOf($actor)->pluck('id');

        $query = WalletTransaction::query()
            ->with(['user', 'creator', 'wallet', 'counterparty'])
            ->whereIn('user_id', $scopeIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('type') && in_array($request->query('type'), array_column(WalletTransactionType::cases(), 'value'), true)) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->query('from'), $actor->timezone)->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->query('to'), $actor->timezone)->endOfDay()->utc());
        }

        $rows = $this->withGameNames($this->withTransferPartners($query->get()));
        $entries = $this->ledgerEntries($rows, $actor, $focusedId);

        return view('panel.wallets.transactions', [
            'rows' => $entries,
            'subjects' => User::query()->subtreeOf($actor)->whereKeyNot($actor->id)->orderBy('username')->get(['id', 'username']),
            'totals' => $this->viewerTotals($actor, $request, $ownAccount),
            'ownAccount' => $ownAccount,
            'selectedUser' => $request->query('user'),
        ]);
    }

    /**
     * 03.10 oncesi casino satirlarinda note bos: oyun adini game_rounds (provider + provider_transaction_id
     * = idempotency anahtari) uzerinden sadece EKRAN icin doldurur, deftere yazmaz.
     *
     * @param  Collection<int, WalletTransaction>  $rows
     * @return Collection<int, WalletTransaction>
     */
    private function withGameNames($rows)
    {
        $keys = $rows->filter(fn (WalletTransaction $row) => ($row->note === null || $row->note === '') && str_contains((string) $row->idempotency_key, ':')
            && ! in_array($row->type, [WalletTransactionType::TransferIn, WalletTransactionType::TransferOut], true))
            ->pluck('idempotency_key');
        if ($keys->isEmpty()) {
            return $rows;
        }
        $txIds = $keys->map(fn ($key) => substr($key, strpos($key, ':') + 1))->unique()->values()->all();
        $names = \Illuminate\Support\Facades\DB::table('game_rounds')
            ->join('casino_games', 'casino_games.id', '=', 'game_rounds.game_id')
            ->whereIn('game_rounds.provider_transaction_id', $txIds)
            ->get(['game_rounds.provider', 'game_rounds.provider_transaction_id', 'casino_games.name'])
            ->mapWithKeys(fn ($r) => [$r->provider.':'.$r->provider_transaction_id => $r->name]);
        foreach ($rows as $row) {
            if (($row->note === null || $row->note === '') && isset($names[$row->idempotency_key])) {
                $row->setAttribute('note', $names[$row->idempotency_key]);
            }
        }

        return $rows;
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
    private function ledgerEntries($rows, User $actor, ?int $focusedId)
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
                $entries[] = $this->presentTransfer($out, $in, $actor, $focusedId);

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
    private function presentTransfer(WalletTransaction $out, WalletTransaction $in, User $actor, ?int $focusedId): array
    {
        $subject = $this->subjectLeg($out, $in, $actor, $focusedId);
        $signed = bcadd((string) $subject->amount, '0', 2);
        $before = bcadd((string) $subject->balance_before, '0', 2);
        $after = bcadd((string) $subject->balance_after, '0', 2);
        $positive = bccomp($signed, '0', 2) === 1;

        return [
            'when' => $subject->created_at->timezone($actor->timezone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
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

    private function subjectLeg(WalletTransaction $out, WalletTransaction $in, User $actor, ?int $focusedId): WalletTransaction
    {
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

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->query('from'), $actor->timezone)->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->query('to'), $actor->timezone)->endOfDay()->utc());
        }

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
}
