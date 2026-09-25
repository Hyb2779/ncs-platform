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
use Illuminate\View\View;

class WalletController extends Controller
{
    public function adjust(AdjustBalanceRequest $request, User $user, WalletService $wallets): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($user->parent_id === $actor->id, 404);

        $amount = $request->string('amount')->toString();

        if (bccomp($amount, '0', 2) !== 1) {
            return back()->withInput()->withErrors(['amount' => __('wallet.validation.amount_invalid')]);
        }

        try {
            if ($request->string('direction')->toString() === 'add') {
                $wallets->transfer($actor, $user, $amount, $request->string('idempotency_key')->toString(), $actor, $request->input('note'), $request->ip());
            } else {
                $wallets->transfer($user, $actor, $amount, $request->string('idempotency_key')->toString(), $actor, $request->input('note'), $request->ip());
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
        $hideSign = $actor->role === UserRole::Owner;
        $format = $hideSign
            ? fn (string $amount, $currency) => Money::formatAbsolute($amount, $currency)
            : fn (string $amount, $currency) => Money::format($amount, $currency);

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

        $mappedTotals = collect($totals)->map(fn (array $total) => [
            'added' => $format($total['added'], $total['currency']),
            'removed' => $format($total['removed'], $total['currency']),
            'difference' => $format(bcsub($total['added'], $total['removed'], 2), $total['currency']),
        ])->values();

        if ($mappedTotals->isEmpty()) {
            $mappedTotals = collect([[
                'added' => $format('0.00', $actor->currency),
                'removed' => $format('0.00', $actor->currency),
                'difference' => $format('0.00', $actor->currency),
            ]]);
        }

        return view('panel.wallets.transactions', [
            'rows' => $rows->map(function (WalletTransaction $row) use ($actor, $format, $hideSign) {
                $amount = bcadd((string) $row->amount, '0', 2);
                $movement = null;

                if ($hideSign && $row->user_id === $actor->id) {
                    $movement = bccomp($amount, '0', 2) === 1
                        ? __('wallet.taken_back')
                        : __('wallet.given');
                }

                return [
                    'when' => $row->created_at->timezone($actor->timezone)->locale(app()->getLocale())->translatedFormat('d.m.Y H:i'),
                    'actor' => $this->visibleName($row->creator, $actor),
                    'user' => $this->visibleName($row->user, $actor),
                    'before' => $format((string) $row->balance_before, $row->wallet->currency),
                    'amount' => $format($amount, $row->wallet->currency),
                    'after' => $format((string) $row->balance_after, $row->wallet->currency),
                    'movement' => $movement,
                    'note' => $row->note ?: __('panel.empty_value'),
                    'ip' => $row->ip ?: __('panel.empty_value'),
                ];
            }),
            'subjects' => User::query()->subtreeOf($actor)->orderBy('username')->get(['id', 'username']),
            'totals' => $mappedTotals,
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
