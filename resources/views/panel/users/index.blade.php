@extends('layouts.panel')

@section('heading', __('panel.users'))

@section('content')
    @if (count($breadcrumb) > 1)
        <nav class="mb-4 flex flex-wrap gap-2 text-sm text-start">
            @foreach ($breadcrumb as $crumb)
                <a class="text-slate-600" href="{{ route('panel.users.index', $crumb->id === auth()->id() ? [] : ['parent' => $crumb->id]) }}">{{ $crumb->username }}</a>
                @if (! $loop->last)
                    <span aria-hidden="true">›</span>
                @endif
            @endforeach
        </nav>
    @endif
    <div class="mb-4 flex flex-wrap items-center justify-end gap-3">
        @if ($canCreate)
            <a class="inline-flex h-10 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" href="{{ route('panel.users.create', $parent->id === auth()->id() ? [] : ['parent' => $parent->id]) }}">{{ __('panel.create_user') }}</a>
        @endif
    </div>
    <form class="mb-4 flex flex-wrap gap-2" method="GET">
        @if ($parent->id !== auth()->id())
            <input type="hidden" name="parent" value="{{ $parent->id }}">
        @endif
        <input class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="q" value="{{ request('q') }}" placeholder="{{ __('panel.search_username') }}">
        <select class="rounded-md border border-slate-300 px-3 py-2 text-sm" name="status">
            <option value="">{{ __('panel.status_all') }}</option>
            @foreach (['active', 'passive', 'banned'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('panel.statuses.'.$status) }}</option>
            @endforeach
        </select>
        <button class="inline-flex h-10 items-center rounded-lg border border-[#E3E6EB] bg-white px-3 text-sm" type="submit">{{ __('panel.filter') }}</button>
    </form>

    <div class="hidden overflow-x-auto rounded-lg bg-white md:block">
        <table class="w-full text-start text-sm">
            <thead class="bg-[#F3F4F6] text-slate-500">
                <tr>
                    <th class="px-3 py-2 font-medium">{{ __('panel.fields.username') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('panel.fields.role') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('panel.fields.status') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('panel.fields.commission_rate') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('panel.fields.user_limit') }}</th>
                    <th class="px-3 py-2 font-medium">{{ __('panel.fields.last_login') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="border-b border-[#E3E6EB]">
                        <td class="px-3 py-2"><a href="{{ route('panel.users.index', ['parent' => $user->id]) }}">{{ $user->username }}</a></td>
                        <td class="px-3 py-2">{{ __('panel.roles.'.$user->role->value) }}</td>
                        <td class="px-3 py-2">{{ __('panel.statuses.'.$user->status->value) }}</td>
                        <td class="px-3 py-2 font-numeric">{{ $user->commission_rate }}</td>
                        <td class="px-3 py-2 font-numeric">{{ $user->children_count }} / {{ $user->user_limit ?? __('panel.unlimited') }}</td>
                        <td class="px-3 py-2">{{ $user->last_login_at?->timezone($user->timezone)->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2"><a href="{{ route('panel.users.edit', $user) }}">{{ __('panel.edit') }}</a></td>
                    </tr>
                @empty
                    <tr><td class="px-3 py-4" colspan="7">{{ __('panel.empty_users') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="grid gap-3 md:hidden">
        @forelse ($users as $user)
            <article class="rounded-md bg-white p-3 text-start">
                <a class="font-medium" href="{{ route('panel.users.index', ['parent' => $user->id]) }}">{{ $user->username }}</a>
                <p class="text-sm">{{ __('panel.roles.'.$user->role->value) }} · {{ __('panel.statuses.'.$user->status->value) }}</p>
                <p class="text-sm">{{ __('panel.fields.commission_rate') }}: {{ $user->commission_rate }}</p>
                <p class="text-sm">{{ $user->children_count }} / {{ $user->user_limit ?? __('panel.unlimited') }}</p>
                <p class="text-sm">{{ $user->last_login_at?->timezone($user->timezone)->format('Y-m-d H:i') }}</p>
                <a class="text-sm" href="{{ route('panel.users.edit', $user) }}">{{ __('panel.edit') }}</a>
            </article>
        @empty
            <p>{{ __('panel.empty_users') }}</p>
        @endforelse
    </div>
@endsection
