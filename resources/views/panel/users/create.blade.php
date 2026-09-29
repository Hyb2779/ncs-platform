@extends('layouts.panel')

@php $childRole = ['owner' => 'superadmin', 'superadmin' => 'bayi', 'bayi' => 'uye'][auth()->user()->role->value] ?? null; @endphp
@section('heading', __('panel.create_user').($childRole && trans()->has('panel.roles.'.$childRole) ? ' · '.__('panel.roles.'.$childRole) : ''))

@section('content')
    <form class="grid max-w-lg gap-4" method="POST" action="{{ route('panel.users.store') }}">
        @csrf
        @if ($parent->id !== auth()->id())
            <input type="hidden" name="parent" value="{{ $parent->id }}">
        @endif
        @include('panel.users._fields', ['creating' => true])
        <button class="inline-flex h-10 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.save') }}</button>
    </form>
@endsection
