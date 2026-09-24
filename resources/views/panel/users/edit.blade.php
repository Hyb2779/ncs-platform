@extends('layouts.panel')

@section('content')
    <h1 class="text-2xl font-semibold text-start">{{ __('panel.edit_user', ['username' => $user->username]) }}</h1>
    <form class="mt-4 grid max-w-lg gap-4" method="POST" action="{{ route('panel.users.update', $user) }}">
        @csrf
        @method('PUT')
        @include('panel.users._fields', ['creating' => false])
        <button class="rounded-md bg-slate-900 px-3 py-2 text-sm text-white" type="submit">{{ __('panel.save') }}</button>
    </form>
@endsection
