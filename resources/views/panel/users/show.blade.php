@extends('layouts.panel')

@section('heading', $member->username)

@section('content')
    @php
        $tz = auth()->user()->timezone;
        $m = fn ($v) => \App\Support\Money::format((string) $v, $currency);
        $box = 'rounded-lg border border-[#E3E6EB] bg-white p-3';
        $btn = 'inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3';
    @endphp
    <div class="mb-4 {{ $box }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-lg font-semibold">
                    {{ $member->username }}
                    <span class="ms-2 rounded px-2 py-0.5 text-xs font-semibold {{ $online ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $online ? __('panel.member_online') : __('panel.member_offline') }}</span>
                </p>
                <p class="text-sm text-slate-500">
                    {{ __('panel.roles.'.$member->role->value) }}
                    @if ($member->parent) · {{ __('panel.create_parent') }}: {{ $member->parent->username }} @endif
                    · {{ __('panel.statuses.'.$member->status->value) }}
                </p>
                <p class="text-sm text-slate-500">{{ __('panel.member_last_login') }}: {{ $member->last_login_at?->timezone($tz)->format('d.m.Y H:i') ?? '—' }}</p>
            </div>
            <div class="text-end">
                <p class="text-xs text-slate-500">{{ __('wallet.balance') }}</p>
                <p class="font-numeric text-2xl font-semibold">{{ $m($balance) }}</p>
            </div>
        </div>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            <a class="{{ $btn }}" href="{{ route('panel.balance') }}">{{ __('panel.menu_balance') }}</a>
            <a class="{{ $btn }}" href="{{ route('panel.users.edit', $member) }}">{{ __('panel.edit') }}</a>
            <a class="{{ $btn }}" href="{{ route('panel.transactions', ['user' => $member->id]) }}">{{ __('panel.users_movements') }}</a>
            <a class="{{ $btn }}" href="{{ route('panel.coupons.index', ['user' => $member->username]) }}">{{ __('panel.member_all_coupons') }}</a>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['today' => 'member_range_today', '7' => 'member_range_7', '30' => 'member_range_30'] as $key => $label)
            <a class="{{ $btn }} {{ $range === (string) $key ? 'bg-[#161A22] text-white' : '' }}" href="{{ route('panel.users.show', ['user' => $member, 'range' => $key]) }}">{{ __('panel.'.$label) }}</a>
        @endforeach
    </div>

    @php
        $sections = [
            [__('panel.member_sport'), [
                [__('panel.member_turnover'), $m($sport['turnover']), __('panel.today_coupons', ['count' => $sport['bets']])],
                [__('panel.member_payout'), $m($sport['payout']), __('panel.today_coupons', ['count' => $sport['wins']])],
                [__('panel.today_sport_lost'), $m($sport['lost']), __('panel.today_coupons', ['count' => $sport['lost_count']])],
                [__('panel.today_sport_pending'), $m($sport['pending']), __('panel.today_coupons', ['count' => $sport['pending_count']])],
                ...(auth()->user()?->role === \App\Enums\UserRole::Owner ? [[__('panel.member_ggr'), $m($sport['ggr']), null]] : []),
            ]],
            [__('panel.member_casino'), [
                [__('panel.member_turnover'), $m($casino['turnover']), null],
                [__('panel.member_payout'), $m($casino['payout']), null],
                ...(auth()->user()?->role === \App\Enums\UserRole::Owner ? [[__('panel.member_ggr'), $m($casino['ggr']), null]] : []),
            ]],
        ];
    @endphp
    @foreach ($sections as [$title, $cards])
        <p class="mb-2 text-sm font-semibold">{{ $title }}</p>
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            @foreach ($cards as [$label, $value, $sub])
                <div class="{{ $box }}">
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                    <p class="mt-1 truncate font-numeric text-lg font-semibold">{{ $value }}</p>
                    @if ($sub)<p class="text-xs text-slate-500">{{ $sub }}</p>@endif
                </div>
            @endforeach
        </div>
    @endforeach

    @foreach ([['panel.member_open_coupons', $openCoupons, true], ['panel.member_recent_coupons', $recentCoupons, false]] as [$titleKey, $list, $isOpen])
        <p class="mb-2 text-sm font-semibold">{{ __($titleKey) }}</p>
        @if ($list->isEmpty())
            <div class="mb-4 {{ $box }} text-sm text-slate-500">{{ __('panel.member_no_coupons') }}</div>
        @else
            @php
                $rows = $list->map(fn ($c) => [
                    'no' => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('panel.coupons.tipo', $c)).'">'.e($c->bet_id).'</a>'),
                    'time' => $c->placed_at?->timezone($tz)->format('d.m.Y H:i'),
                    'stake' => $c->stake,
                    'status' => __('sport.coupon.statuses.'.$c->panelStatus()).' · '.$c->won_count.'/'.$c->selection_count,
                    'odds' => $c->total_odds,
                    'win' => $isOpen ? $c->potential_win : $c->payout,
                ])->all();
            @endphp
            <div class="mb-4">
                <x-panel.table
                    :columns="[
                        ['key' => 'no', 'label' => __('sport.panel.cols.no')],
                        ['key' => 'stake', 'label' => __('sport.panel.cols.stake')],
                        ['key' => 'win', 'label' => $isOpen ? __('sport.panel.cols.win') : __('panel.tipo_payout')],
                        ['key' => 'status', 'label' => __('sport.panel.cols.status')],
                        ['key' => 'time', 'label' => __('sport.panel.cols.time'), 'priority' => 'detail'],
                        ['key' => 'odds', 'label' => __('sport.panel.cols.total'), 'priority' => 'detail'],
                    ]"
                    :rows="$rows"
                />
            </div>
        @endif
    @endforeach
@endsection
