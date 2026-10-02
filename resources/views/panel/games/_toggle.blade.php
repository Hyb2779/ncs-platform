@php
    $closed = $state !== null;
    $locked = ! $isOwner && $state === 'global';
@endphp
<form method="POST" action="{{ route('panel.games.block') }}"
      class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 {{ $closed ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-white' }}">
    @csrf
    <input type="hidden" name="scope" value="{{ $scope }}">
    <input type="hidden" name="value[]" value="{{ $value }}">
    <input type="hidden" name="blocked" value="{{ $closed ? 0 : 1 }}">
    <div class="min-w-0">
        <div class="truncate text-sm font-semibold text-slate-800">{{ $label }}</div>
        <div class="text-xs {{ $closed ? 'text-red-700' : 'text-slate-500' }}">
            @isset($count){{ __('panel.games_count', ['count' => $count]) }} · @endisset
            @if ($state === 'global' && ! $isOwner)
                {{ __('panel.games_global_closed') }}
            @elseif ($closed)
                {{ __('panel.games_closed') }}
            @else
                {{ __('panel.games_open') }}
            @endif
        </div>
    </div>
    <button type="submit" @disabled($locked)
            class="inline-flex h-10 shrink-0 items-center rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-40 {{ $closed ? 'bg-emerald-600' : 'bg-red-600' }}">
        {{ $closed ? __('panel.games_open_action') : __('panel.games_close_action') }}
    </button>
</form>
