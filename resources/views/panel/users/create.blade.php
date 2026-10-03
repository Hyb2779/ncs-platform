@extends('layouts.panel')

@section('heading', __('panel.create_user'))

@section('content')
    @php($pickedRole = old('role', $defaultRole))
    <form class="grid max-w-lg gap-4" method="POST" action="{{ route('panel.users.store') }}" x-data="{ role: @js($pickedRole) }">
        @csrf
        <label class="grid gap-1 text-sm">
            <span>{{ __('panel.create_type') }}</span>
            <select class="rounded-md border border-slate-300 px-3 py-2" name="role" x-model="role">
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected($pickedRole === $role)>{{ __('panel.roles.'.$role) }}</option>
                @endforeach
            </select>
        </label>
        @foreach ($parents as $role => $options)
            <label class="grid gap-1 text-sm" x-show="role === @js($role)" @if ($pickedRole !== $role) style="display: none" @endif>
                <span>{{ __('panel.create_parent') }} · {{ __('panel.roles.'.($role === 'bayi' ? 'superadmin' : 'bayi')) }}</span>
                @if ($options === [])
                    <span class="text-sm text-rose-600">{{ __('panel.create_no_parent') }}</span>
                @else
                    <select class="rounded-md border border-slate-300 px-3 py-2" name="parent" :disabled="role !== @js($role)" @disabled($pickedRole !== $role)>
                        @foreach ($options as $option)
                            <option value="{{ $option['id'] }}" @selected((int) old('parent') === $option['id'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                @endif
            </label>
        @endforeach
        @error('parent')
            <p class="text-sm text-rose-600">{{ $message }}</p>
        @enderror
        @include('panel.users._fields', ['creating' => true])
        <button class="inline-flex h-10 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">{{ __('panel.save') }}</button>
    </form>
@endsection
