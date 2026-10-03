@extends('layouts.panel')

@section('heading', __('panel.password_title'))

@section('content')
@php($input = 'rounded-md border border-slate-300 px-3 py-2')
<div class="max-w-md">
    @if (session('status'))
        <p class="mb-3 rounded-lg border border-[#E3E6EB] bg-white px-3 py-2 text-sm font-medium" role="status">{{ session('status') }}</p>
    @endif
    <form class="grid gap-3 rounded-lg border border-[#E3E6EB] bg-white p-4" method="POST" action="{{ route('panel.password.update') }}">
        @csrf
        @foreach (['current_password' => 'password_current', 'password' => 'password_new', 'password_confirmation' => 'password_confirm'] as $field => $label)
            <label class="grid gap-1 text-sm">
                <span>{{ __('panel.'.$label) }}</span>
                <input class="{{ $input }}" type="password" name="{{ $field }}" autocomplete="{{ $field === 'current_password' ? 'current-password' : 'new-password' }}" required>
                @error($field)<span class="text-red-600">{{ $message }}</span>@enderror
            </label>
        @endforeach
        <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.password_save') }}</button>
    </form>
</div>
@endsection
