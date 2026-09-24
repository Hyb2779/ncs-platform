@extends('layouts.panel')

@section('heading', __('panel.edit_user', ['username' => $user->username]))

@section('content')
    <form class="grid max-w-lg gap-4" method="POST" action="{{ route('panel.users.update', $user) }}">
        @csrf
        @method('PUT')
        @include('panel.users._fields', ['creating' => false])
        <button class="inline-flex h-10 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.save') }}</button>
    </form>
@endsection
