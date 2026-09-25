@extends('layouts.site')

@section('heading', __('site.sport'))

@section('content')
    <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)_20rem]">
        <aside class="hidden rounded-lg bg-[#151A23] p-3 lg:block">
            <p class="mb-2 text-xs text-[#9AA4B5]">{{ __('sport.featured') }}</p>
            @foreach ($leagues as $league)
                <a class="flex h-11 items-center gap-2 text-sm" href="{{ route('site.sport', ['league' => $league->id]) }}">
                    <span>{{ $league->country->name }}</span>
                    <span class="text-[#9AA4B5]">{{ $league->name }}</span>
                </a>
            @endforeach
        </aside>
        <section>
            <form class="mb-4 flex flex-wrap gap-2" method="GET">
                @foreach (['today' => __('sport.today'), 'tomorrow' => __('sport.tomorrow'), '3h' => __('sport.hours'), 'all' => __('sport.all')] as $key => $label)
                    <a class="inline-flex h-11 items-center rounded-lg border border-[#232B39] px-3 text-sm {{ request('when', 'all') === $key ? 'bg-[var(--accent)] text-[#0E1117]' : '' }}" href="{{ route('site.sport', ['when' => $key, 'q' => request('q'), 'league' => request('league')]) }}">{{ $label }}</a>
                @endforeach
                <input class="h-11 flex-1 rounded-md border border-[#232B39] bg-[#151A23] px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('sport.search') }}">
            </form>
            @forelse ($fixtures as $group)
                @php $league = $group->first()->league; @endphp
                <h2 class="mb-2 mt-4 text-sm text-[#9AA4B5]">{{ $league->country->name }} · {{ $league->name }}</h2>
                <div class="hidden overflow-x-auto rounded-lg bg-[#151A23] md:block">
                    <table class="w-full text-sm">
                        @foreach ($group as $fixture)
                            @include('site.sport._row', ['fixture' => $fixture])
                        @endforeach
                    </table>
                </div>
                <div class="grid gap-3 md:hidden">
                    @foreach ($group as $fixture)
                        @include('site.sport._card', ['fixture' => $fixture])
                    @endforeach
                </div>
            @empty
                <p class="text-[#9AA4B5]">{{ __('sport.empty') }}</p>
            @endforelse
        </section>
        <aside class="hidden lg:block">
            @include('site.sport._coupon')
        </aside>
    </div>
    <div class="fixed inset-x-0 bottom-16 z-20 px-4 lg:hidden">
        @if (count($coupon['rows']) > 0)
            <button class="h-11 w-full rounded-lg bg-[var(--accent)] font-semibold text-[#0E1117]" type="button" onclick="document.getElementById('coupon-sheet').showModal()">{{ __('sport.coupon.open') }}</button>
        @endif
    </div>
    <dialog id="coupon-sheet" class="w-full max-w-none bg-transparent p-0 backdrop:bg-black/60 lg:hidden">
        <div class="mt-auto rounded-t-xl bg-[#151A23] p-4 text-[#E8ECF3]">
            @include('site.sport._coupon')
        </div>
    </dialog>
@endsection
