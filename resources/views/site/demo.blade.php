@extends('layouts.site')

@section('content')
    <h1 class="text-xl font-semibold">{{ $game->name }}</h1>
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach (['bet' => 'demo_bet', 'win' => 'demo_win', 'refund' => 'demo_refund'] as $action => $label)
            <form method="POST" action="{{ route('site.demo.action', $game) }}">
                @csrf
                <input type="hidden" name="action" value="{{ $action }}">
                <button class="inline-flex h-11 items-center rounded-lg bg-[#151A23] px-3" type="submit">{{ __('site.'.$label) }}</button>
            </form>
        @endforeach
    </div>
@endsection
