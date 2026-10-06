@extends('layouts.panel')

@section('heading', __('panel.home_slides_title'))

@section('content')
<div class="flex max-w-3xl flex-col gap-4 text-start">
    @if (session('status'))
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
    @endif

    <p class="text-sm text-slate-600">{{ __('panel.home_slides_hint') }}</p>

    <form class="flex flex-wrap items-center gap-2" method="GET" action="{{ route('panel.home-slides.index') }}">
        <input class="h-11 min-w-0 flex-1 rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="search" name="q" value="{{ $q }}" placeholder="{{ __('panel.home_slides_search') }}">
        <button class="inline-flex h-11 items-center rounded-lg bg-[#161A22] px-4 text-sm text-white" type="submit">{{ __('panel.home_slides_search_btn') }}</button>
    </form>

    @if ($found->isNotEmpty())
        <ul class="flex flex-col gap-2">
            @foreach ($found as $game)
                <li class="flex items-center justify-between gap-3 rounded-lg border border-[#E3E6EB] bg-white px-3 py-2">
                    <span class="min-w-0 truncate text-sm">{{ $game->name }} <span class="text-slate-500">{{ \App\Support\Vendors::name($game->vendor) ?? $game->provider?->name }}</span></span>
                    <form method="POST" action="{{ route('panel.home-slides.store') }}">
                        @csrf
                        <input type="hidden" name="game_id" value="{{ $game->id }}">
                        <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3 text-sm" type="submit">{{ __('panel.home_slides_add') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <ul class="flex flex-col gap-3">
        @foreach ($slides as $slide)
            @php
                $label = match ($slide->key) {
                    'match' => __('panel.home_slides_match'),
                    'top-win' => __('panel.home_slides_top_win'),
                    'sweet-bonanza-2500' => 'Sweet Bonanza 2500',
                    'sweet-bonanza-super-scatter' => 'Sweet Bonanza Super Scatter',
                    default => $slide->game?->name ?? __('panel.home_slides_missing'),
                };
            @endphp
            <li class="flex flex-col gap-3 rounded-xl border border-[#E3E6EB] bg-white p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm font-semibold">{{ $label }}</span>
                    <span class="text-xs text-slate-500">{{ $slide->is_active ? __('panel.home_slides_active') : __('panel.home_slides_passive') }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form method="POST" action="{{ route('panel.home-slides.move', $slide) }}">
                        @csrf
                        <input type="hidden" name="direction" value="up">
                        <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3 text-sm" type="submit">{{ __('panel.home_slides_up') }}</button>
                    </form>
                    <form method="POST" action="{{ route('panel.home-slides.move', $slide) }}">
                        @csrf
                        <input type="hidden" name="direction" value="down">
                        <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3 text-sm" type="submit">{{ __('panel.home_slides_down') }}</button>
                    </form>
                    <form method="POST" action="{{ route('panel.home-slides.active', $slide) }}">
                        @csrf
                        <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3 text-sm" type="submit">{{ $slide->is_active ? __('panel.home_slides_passive') : __('panel.home_slides_active') }}</button>
                    </form>
                    @unless ($slide->isSystem())
                        <form method="POST" action="{{ route('panel.home-slides.destroy', $slide) }}">
                            @csrf
                            @method('DELETE')
                            <button class="inline-flex h-10 items-center rounded-lg border border-red-200 px-3 text-sm text-red-700" type="submit">{{ __('panel.home_slides_delete') }}</button>
                        </form>
                    @endunless
                </div>
                @unless ($slide->isMatch())
                    <form class="flex flex-wrap items-center gap-2" method="POST" action="{{ route('panel.home-slides.image', $slide) }}" enctype="multipart/form-data">
                        @csrf
                        <input class="text-sm" type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
                        <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3 text-sm" type="submit">{{ __('panel.home_slides_image') }}</button>
                    </form>
                    @if ($slide->image_path)
                        <form method="POST" action="{{ route('panel.home-slides.image.clear', $slide) }}">
                            @csrf
                            @method('DELETE')
                            <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] px-3 text-sm" type="submit">{{ __('panel.home_slides_image_remove') }}</button>
                        </form>
                    @endif
                @endunless
            </li>
        @endforeach
    </ul>
</div>
@endsection
