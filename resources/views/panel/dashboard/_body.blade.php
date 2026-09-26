@if ($currencies !== [])
    <div class="mb-4 flex gap-2">
        @foreach ($currencies as $code)
            <a class="inline-flex h-11 items-center rounded-lg border px-3 text-sm {{ $currency === $code ? 'bg-[#161A22] text-white' : 'bg-white' }}" href="{{ route('panel.dashboard', array_merge(request()->only(['from', 'to']), ['currency' => $code])) }}">{{ $code }}</a>
        @endforeach
    </div>
@endif
<x-panel.filter-bar class="mb-4" />
<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ($cards as $card)
        <x-panel.stat :label="$card['label']" :value="$card['value']" :change="$card['change']" />
    @endforeach
</div>
<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <x-panel.card :title="__('panel.chart_turnover_ggr')">
        <x-panel.chart type="line" :labels="$line['labels']" :datasets="$line['datasets']" />
    </x-panel.card>
    <x-panel.card :title="__('panel.chart_products')">
        <x-panel.chart :type="$products['type']" :labels="$products['labels']" :datasets="$products['datasets']" />
    </x-panel.card>
    @if ($rank !== null)
        <x-panel.card :title="__('panel.chart_ggr_rank')">
            @if ($rank['labels'] === [])
                <x-panel.empty :message="__('panel.empty_rows')" />
            @else
                <x-panel.chart type="bar" :labels="$rank['labels']" :datasets="$rank['datasets']" />
            @endif
        </x-panel.card>
    @endif
    @if ($showPlayers ?? true)
        <x-panel.card :title="__('panel.chart_players')">
            <x-panel.chart type="line" :labels="$players['labels']" :datasets="$players['datasets']" />
        </x-panel.card>
    @endif
</div>
