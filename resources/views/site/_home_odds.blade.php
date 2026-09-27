<div class="grid grid-cols-3 gap-1.5 md:gap-2">
    @foreach (['o1' => '1', 'ox' => 'X', 'o2' => '2'] as $key => $label)
        @if ($m[$key])
            <form method="POST" action="{{ route('site.sport.add', $m[$key]) }}">
                @csrf
                <button class="flex w-full items-center justify-between rounded-lg border border-[var(--site-line)] bg-[var(--site-panel)] px-2.5 text-[var(--site-text)] {{ $size === 'lg' ? 'h-12' : 'h-10' }}" type="submit">
                    <span class="text-xs text-[var(--site-muted)]">{{ $label }}</span>
                    <span class="font-numeric font-bold {{ $size === 'lg' ? 'text-[22px]' : 'text-lg' }}">{{ $m[$key]->shown_odd }}</span>
                </button>
            </form>
        @endif
    @endforeach
</div>
