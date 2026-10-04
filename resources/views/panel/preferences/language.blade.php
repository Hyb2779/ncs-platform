@extends('layouts.panel')

@section('heading', __('panel.menu_language'))

@section('content')
    <form method="POST" action="{{ route('panel.preferences.language') }}" class="grid max-w-md gap-2">
        @csrf
        @foreach ($locales as $lc)
            <button class="flex h-14 items-center justify-between rounded-lg border px-4 text-start {{ app()->getLocale() === $lc ? 'border-[#161A22] bg-[#161A22] text-white' : 'border-[#E3E6EB] bg-white' }}" type="submit" name="language" value="{{ $lc }}">
                <span class="font-semibold">{{ __('panel.languages.'.$lc) }}</span>
                <span class="text-xs font-semibold uppercase opacity-70">{{ $lc }}</span>
            </button>
        @endforeach
    </form>
@endsection
