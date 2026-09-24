<label class="grid gap-1 text-sm">
    <span>{{ __('panel.fields.username') }}</span>
    @if ($creating)
        <input class="rounded-md border border-slate-300 px-3 py-2" name="username" value="{{ old('username') }}">
    @else
        <input class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2" value="{{ $user->username }}" disabled>
    @endif
</label>
<label class="grid gap-1 text-sm">
    <span>{{ $creating ? __('panel.fields.password') : __('panel.fields.password_reset') }}</span>
    <input class="rounded-md border border-slate-300 px-3 py-2" type="password" name="password">
</label>
<label class="grid gap-1 text-sm">
    <span>{{ __('panel.fields.commission_rate') }}</span>
    <input class="rounded-md border border-slate-300 px-3 py-2" name="commission_rate" value="{{ old('commission_rate', $creating ? '' : $user->commission_rate) }}">
</label>
<label class="grid gap-1 text-sm">
    <span>{{ __('panel.fields.user_limit') }}</span>
    <input class="rounded-md border border-slate-300 px-3 py-2" name="user_limit" value="{{ old('user_limit', $creating ? '' : $user->user_limit) }}">
</label>
<label class="grid gap-1 text-sm">
    <span>{{ __('panel.fields.note') }}</span>
    <textarea class="rounded-md border border-slate-300 px-3 py-2" name="note">{{ old('note', $creating ? '' : $user->note) }}</textarea>
</label>
@if (! $creating)
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.fields.status') }}</span>
        <select class="rounded-md border border-slate-300 px-3 py-2" name="status">
            @foreach (['active', 'passive', 'banned'] as $status)
                <option value="{{ $status }}" @selected(old('status', $user->status->value) === $status)>{{ __('panel.statuses.'.$status) }}</option>
            @endforeach
        </select>
    </label>
@endif
@if ($creating && auth()->user()->role->value === 'owner')
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.fields.language') }}</span>
        <select class="rounded-md border border-slate-300 px-3 py-2" name="language">
            @foreach (['tr', 'en', 'de', 'ar'] as $language)
                <option value="{{ $language }}" @selected(old('language') === $language)>{{ __('panel.languages.'.$language) }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.fields.currency') }}</span>
        <select class="rounded-md border border-slate-300 px-3 py-2" name="currency">
            @foreach (['TRY', 'USD', 'EUR'] as $currency)
                <option value="{{ $currency }}" @selected(old('currency') === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1 text-sm">
        <span>{{ __('panel.fields.timezone') }}</span>
        <input class="rounded-md border border-slate-300 px-3 py-2" name="timezone" value="{{ old('timezone', 'UTC') }}">
    </label>
@endif
@if ($errors->any())
    <ul class="text-sm text-red-700">
        @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
@endif
