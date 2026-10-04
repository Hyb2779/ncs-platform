@extends('layouts.panel')

@section('heading', __('panel.games_title'))

@section('content')
<div class="space-y-6">
    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif

    @if ($targets->isNotEmpty())
        <form method="GET" action="{{ route('panel.games.index') }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3">
            <label class="grid gap-1 text-sm">
                <span class="text-slate-500">{{ __('panel.games_target_label') }}</span>
                <select name="target" class="h-11 min-w-64 rounded-lg border border-slate-300 px-3 text-sm" onchange="this.form.submit()">
                    <option value="">{{ $actorIsRoot ? __('panel.games_target_global') : __('panel.games_target_self') }}</option>
                    @foreach ($targets as $t)
                        <option value="{{ $t->id }}" @selected((int) $target->id === (int) $t->id)>{{ str_repeat('· ', max(0, substr_count((string) $t->path, '/') - 3)) }}{{ $t->username }} ({{ __('panel.roles.'.$t->role->value) }})</option>
                    @endforeach
                </select>
            </label>
        </form>
    @endif
    @if ((int) $target->id !== $actorId)
        <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('panel.games_target_hint', ['name' => $target->username]) }}</p>
    @else
        <p class="text-sm text-slate-500">{{ $isOwner ? __('panel.games_hint_owner') : __('panel.games_hint_superadmin') }}</p>
    @endif

    <section>
        <h2 class="mb-2 text-sm font-bold text-slate-700">{{ __('panel.games_products') }}</h2>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @include('panel.games._toggle', ['scope' => 'product', 'value' => 'wegas_sport', 'label' => brand()->name().' '.__('site.sport'), 'state' => $state['product']['wegas_sport'] ?? null])
        </div>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-bold text-slate-700">{{ __('panel.games_providers') }}</h2>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($providers as $provider)
                @include('panel.games._toggle', ['scope' => 'provider', 'value' => $provider->code, 'label' => $provider->name, 'count' => $provider->games_count, 'state' => $state['provider'][$provider->code] ?? null])
            @endforeach
        </div>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-bold text-slate-700">{{ __('panel.games_categories') }}</h2>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            @foreach ($categories as $category)
                @include('panel.games._toggle', ['scope' => 'category', 'value' => $category['value'], 'label' => __('panel.games_cat_'.$category['value']), 'count' => $category['count'], 'state' => $state['category'][$category['value']] ?? null])
            @endforeach
        </div>
    </section>

    <details class="rounded-lg border border-slate-200 bg-white" @if (($state['vendor'] ?? []) !== []) open @endif>
        <summary class="cursor-pointer px-3 py-3 text-sm font-bold text-slate-700">
            {{ __('panel.games_vendors') }} ({{ $vendors->count() }})
        </summary>
        <div class="grid grid-cols-1 gap-2 p-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($vendors as $vendor)
                @include('panel.games._toggle', ['scope' => 'vendor', 'value' => $vendor['value'], 'label' => $vendor['name'], 'count' => $vendor['count'], 'state' => $state['vendor'][$vendor['value']] ?? null])
            @endforeach
        </div>
    </details>

    <section>
        <h2 class="mb-2 text-sm font-bold text-slate-700">{{ __('panel.games_list') }} ({{ $games->total() }})</h2>

        <form method="GET" action="{{ route('panel.games.index') }}" class="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-6">
            <input type="hidden" name="target" value="{{ $targetParam }}">
            <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('panel.games_search') }}" class="h-11 rounded-lg border border-slate-300 px-3 text-sm sm:col-span-2">
            <select name="category" class="h-11 rounded-lg border border-slate-300 px-2 text-sm">
                <option value="">{{ __('panel.games_categories') }}: {{ __('panel.games_all') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category['value'] }}" @selected(($filters['category'] ?? '') === $category['value'])>{{ __('panel.games_cat_'.$category['value']) }}</option>
                @endforeach
            </select>
            <select name="vendor" class="h-11 rounded-lg border border-slate-300 px-2 text-sm">
                <option value="">{{ __('panel.games_vendors') }}: {{ __('panel.games_all') }}</option>
                @foreach ($vendors as $vendor)
                    <option value="{{ $vendor['value'] }}" @selected(($filters['vendor'] ?? '') === $vendor['value'])>{{ $vendor['name'] }}</option>
                @endforeach
            </select>
            <select name="state" class="h-11 rounded-lg border border-slate-300 px-2 text-sm">
                <option value="">{{ __('panel.games_all') }}</option>
                <option value="open" @selected(($filters['state'] ?? '') === 'open')>{{ __('panel.games_state_open') }}</option>
                <option value="closed" @selected(($filters['state'] ?? '') === 'closed')>{{ __('panel.games_state_closed') }}</option>
            </select>
            <button type="submit" class="h-11 rounded-lg bg-slate-800 px-4 text-sm font-semibold text-white">{{ __('panel.games_filter') }}</button>
        </form>

        <form id="bulk-form" method="POST" action="{{ route('panel.games.block') }}" class="mb-2 flex flex-wrap gap-2">
            @csrf
            <input type="hidden" name="scope" value="game">
            <input type="hidden" name="target" value="{{ $targetParam }}">
            <button type="submit" name="blocked" value="1" class="h-10 rounded-lg bg-red-600 px-3 text-sm font-semibold text-white">{{ __('panel.games_close_selected') }}</button>
            <button type="submit" name="blocked" value="0" class="h-10 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white">{{ __('panel.games_open_selected') }}</button>
        </form>

        <div class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
            @forelse ($games as $game)
                @php
                    $gameState = $state['game'][(string) $game->id] ?? null;
                    $gameLocked = ! $isOwner && $gameState === 'global';
                @endphp
                <div class="flex flex-wrap items-center gap-3 px-3 py-2 {{ $gameState ? 'bg-red-50' : '' }}">
                    <input type="checkbox" form="bulk-form" name="value[]" value="{{ $game->id }}" class="h-5 w-5" @disabled($gameLocked) aria-label="{{ $game->name }}">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-semibold text-slate-800">{{ $game->name }}</div>
                        <div class="text-xs text-slate-500">
                            {{ \App\Support\Vendors::name($game->vendor) ?? $game->provider?->name }}
                            · {{ __('panel.games_cat_'.\App\Services\Casino\GameAvailability::categoryOf($game)) }}
                            @if ($gameState) · <span class="text-red-700">{{ $gameLocked ? __('panel.games_global_closed') : __('panel.games_closed') }}</span>@endif
                        </div>
                    </div>
                    @if ($canCurate)
                        <form method="POST" action="{{ route('panel.casino.games.update', $game) }}" class="flex items-center gap-2">
                            @csrf
                            @method('PUT')
                            <label class="inline-flex items-center gap-1 text-xs text-slate-600">
                                <input type="checkbox" name="is_popular" value="1" @checked($game->is_popular)> {{ __('site.popular') }}
                            </label>
                            <input name="sort_order" value="{{ $game->sort_order }}" inputmode="numeric" class="h-9 w-16 rounded border border-slate-300 px-2 text-sm" aria-label="{{ __('site.order') }}">
                            <button type="submit" class="h-9 rounded border border-slate-300 px-2 text-xs font-semibold">{{ __('panel.save') }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('panel.games.block') }}">
                        @csrf
                        <input type="hidden" name="scope" value="game">
            <input type="hidden" name="target" value="{{ $targetParam }}">
                        <input type="hidden" name="value[]" value="{{ $game->id }}">
                        <input type="hidden" name="blocked" value="{{ $gameState ? 0 : 1 }}">
                        <button type="submit" @disabled($gameLocked)
                                class="h-9 rounded-lg px-3 text-xs font-semibold text-white disabled:opacity-40 {{ $gameState ? 'bg-emerald-600' : 'bg-red-600' }}">
                            {{ $gameState ? __('panel.games_open_action') : __('panel.games_close_action') }}
                        </button>
                    </form>
                </div>
            @empty
                <div class="px-3 py-6 text-center text-sm text-slate-500">{{ __('panel.games_empty') }}</div>
            @endforelse
        </div>

        <div class="mt-3">{{ $games->links() }}</div>
    </section>
</div>
@endsection
