@php
    $zone = auth()->user()->timezone ?? 'UTC';
    $kickoff = $fixture->starts_at->timezone($zone);
    $day = $kickoff->isToday() ? __('sport.today') : ($kickoff->isTomorrow() ? __('sport.tomorrow') : $kickoff->format('d.m'));
    $count = count($columns);
@endphp
<div class="grid items-center gap-x-1 border-b border-[#1D2430] px-3" style="grid-template-columns: 4.75rem 3.25rem minmax(0,1fr) repeat({{ $count }}, 3.625rem) 3.5rem; min-height: 52px">
    <div class="flex flex-col">
        @if (in_array($when ?? request('when', 'today'), ['all', 'tomorrow'], true))
            <span class="text-[11px] font-semibold text-[#9AA4B5]">{{ $day }}</span>
        @endif
        <span class="text-sm font-bold">{{ $kickoff->format('H:i') }}</span>
    </div>
    <span class="font-numeric text-xs font-bold text-[var(--accent)]">{{ $fixture->bulletin_code }}</span>
    <a class="truncate text-sm font-semibold" href="{{ route('site.sport.show', $fixture) }}" title="{{ $fixture->home->name }} – {{ $fixture->away->name }}">{{ $fixture->home->name }} <span class="text-[#6E7889]">–</span> {{ $fixture->away->name }}</a>
    @foreach ($columns as $column)
        @include('site.sport._odd', ['market' => $column['market'], 'outcome' => $column['outcome'], 'compact' => true])
    @endforeach
    <a class="text-center text-[13px] font-bold text-[#9AA4B5]" href="{{ route('site.sport.show', $fixture) }}">{{ __('sport.other', ['count' => $fixture->odds->pluck('market_id')->unique()->count()]) }}</a>
</div>
