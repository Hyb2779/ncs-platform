<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Currency;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustBalanceRequest;
use App\Http\Requests\MintCreditRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletException;
use App\Services\WalletService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function adjust(AdjustBalanceRequest $request, User $user, WalletService $wallets): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($user->parent_id === $actor->id, 404);

        $amount = $request->string('amount')->toString();

        if (bccomp($amount, '0', 2) !== 1) {
            return back()->withErrors(['amount' => __('wallet.validation.amount_invalid')]);
        }

        try {
            if ($request->string('direction')->toString() === 'add') {
                $wallets->transfer($actor, $user, $amount, $request->string('idempotency_key')->toString(), $actor, $request->input('note'), $request->ip());
            } else {
                $wallets->transfer($user, $actor, $amount, $request->string('idempotency_key')->toString(), $actor, $request->input('note'), $request->ip());
            }
        } catch (WalletException $exception) {
            return back()->withInput()->withErrors(['amount' => __($exception->translationKey === 'wallet.insufficient_balance' ? 'wallet.errors.insufficient_balance' : ($exception->translationKey === 'wallet.currency_mismatch' ? 'wallet.errors.currency_mismatch' : 'wallet.errors.invalid_amount'))]);
        }

        return back()->with('status', __('wallet.adjusted'));
    }

    public function mintForm(Request $request): View
    {
        abort_unless($request->user()->role === UserRole::Owner, 404);

        return view('panel.wallets.mint');
    }

    public function mint(MintCreditRequest $request, WalletService $wallets): RedirectResponse
    {
        try {
            $wallets->mint(
                $request->user(),
                Currency::from($request->string('currency')->toString()),
                $request->string('amount')->toString(),
                $request->string('idempotency_key')->toString(),
                $request->input('note'),
                $request->ip(),
            );
        } catch (WalletException $exception) {
            $key = match ($exception->translationKey) {
                'wallet.insufficient_balance' => 'wallet.errors.insufficient_balance',
                'wallet.currency_mismatch' => 'wallet.errors.currency_mismatch',
                'wallet.mint_owner_only' => 'wallet.errors.mint_owner_only',
                default => 'wallet.errors.invalid_amount',
            };

            return back()->withInput()->withErrors(['amount' => __($key)]);
        }

        return redirect()->route('panel.mint')->with('status', __('wallet.minted'));
    }

    public function transactions(Request $request): View
    {
        $actor = $request->user();
        $subject = $actor;

        if ($request->filled('user')) {
            $subject = User::query()->subtreeOf($actor)->whereKey((int) $request->query('user'))->first();
            abort_if($subject === null, 404);
        }

        $scopeIds = $request->filled('user')
            ? [$subject->id]
            : User::query()->subtreeOf($actor)->pluck('id');

        $query = WalletTransaction::query()
            ->with(['user', 'creator', 'wallet'])
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

        $rows = $query->get();
        $totals = [];

        foreach ($rows as $row) {
            $code = $row->wallet->currency->value;
            $totals[$code] ??= ['added' => '0.00', 'removed' => '0.00', 'currency' => $row->wallet->currency];
            $amount = bcadd((string) $row->amount, '0', 2);

            if (bccomp($amount, '0', 2) === 1) {
                $totals[$code]['added'] = bcadd($totals[$code]['added'], $amount, 2);
            } else {
                $totals[$code]['removed'] = bcadd($totals[$code]['removed'], bcsub('0', $amount, 2), 2);
            }
        }

        return view('panel.wallets.transactions', [
            'rows' => $rows->map(fn (WalletTransaction $row) => [
                'when' => $row->created_at->timezone($actor->timezone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
                'actor' => $this->visibleName($row->creator, $actor),
                'user' => $this->visibleName($row->user, $actor),
                'before' => Money::format((string) $row->balance_before, $row->wallet->currency),
                'amount' => Money::format((string) $row->amount, $row->wallet->currency),
                'after' => Money::format((string) $row->balance_after, $row->wallet->currency),
                'note' => $row->note ?: __('panel.empty_value'),
                'ip' => $row->ip ?: __('panel.empty_value'),
            ]),
            'subjects' => User::query()->subtreeOf($actor)->orderBy('username')->get(['id', 'username']),
            'totals' => collect($totals)->map(fn (array $total) => [
                'added' => Money::format($total['added'], $total['currency']),
                'removed' => Money::format($total['removed'], $total['currency']),
                'difference' => Money::format(bcsub($total['added'], $total['removed'], 2), $total['currency']),
            ])->values(),
            'selectedUser' => $request->query('user'),
        ]);
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
