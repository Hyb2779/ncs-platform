<form class="grid gap-4" method="POST" action="{{ route('login.store') }}">
    @csrf
    <label class="grid gap-1.5 text-sm font-semibold text-[var(--site-text-2)]">
        <span>{{ __('auth.username') }}</span>
        <input class="h-12 rounded-xl border border-[var(--site-line)] bg-[var(--site-bg)] px-4 text-[15px] text-[var(--site-text)] outline-none focus:border-[var(--accent)]" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="off" spellcheck="false" required>
    </label>
    <label class="grid gap-1.5 text-sm font-semibold text-[var(--site-text-2)]">
        <span>{{ __('auth.password') }}</span>
        <span class="relative block">
            <input id="{{ $prefix }}-password" class="h-12 w-full rounded-xl border border-[var(--site-line)] bg-[var(--site-bg)] pe-20 ps-4 text-[15px] text-[var(--site-text)] outline-none focus:border-[var(--accent)]" type="password" name="password" autocomplete="current-password" required>
            <button class="absolute inset-y-0 end-0 px-4 text-xs font-bold text-[var(--accent)]" type="button" data-show="{{ __('auth.show_password') }}" data-hide="{{ __('auth.hide_password') }}" onclick="const i = document.getElementById('{{ $prefix }}-password'); const hidden = i.type === 'password'; i.type = hidden ? 'text' : 'password'; this.textContent = hidden ? this.dataset.hide : this.dataset.show;">{{ __('auth.show_password') }}</button>
        </span>
    </label>
    @error('username')
        <p class="rounded-xl border border-[var(--site-live)] px-3 py-2 text-sm text-[var(--site-text)]" role="alert">{{ $message }}</p>
    @enderror
    <button class="h-12 rounded-xl bg-[var(--accent)] text-[15px] font-extrabold text-[var(--site-on-accent)]" type="submit">{{ __('auth.submit') }}</button>
    @if ($hint ?? true)
        <p class="text-center text-xs text-[var(--site-muted)]">{{ __('auth.password_hint') }}</p>
    @endif
</form>
