@foreach ($rows as $row)
    @if ($tab === 'games')
        <article class="min-w-0 rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] p-3">
            <div class="flex min-w-0 items-center gap-3">
                @if ($row['image'] !== '')
                    <img class="h-10 w-10 shrink-0 rounded-md object-cover" src="{{ $row['image'] }}" alt="" width="40" height="40">
                @else
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-[var(--site-panel-2)] text-sm font-bold">{{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}</span>
                @endif
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold">{{ $row['name'] }}</p>
                    <p class="truncate text-xs text-[var(--site-muted)]">{{ $row['category'] }}</p>
                </div>
            </div>
            <dl class="mt-3 grid grid-cols-3 gap-2 text-xs">
                <div class="min-w-0">
                    <dt class="truncate text-[var(--site-muted)]">{{ __('account.bet') }}</dt>
                    <dd class="break-words font-numeric text-sm font-bold">{{ $row['bet'] }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="truncate text-[var(--site-muted)]">{{ __('account.win') }}</dt>
                    <dd class="break-words font-numeric text-sm font-bold">{{ $row['win'] }}</dd>
                </div>
                <div class="min-w-0 text-end">
                    <dt class="truncate text-[var(--site-muted)]">{{ __('account.net') }}</dt>
                    <dd class="acct-{{ $row['tone'] }} break-words font-numeric text-sm font-bold">{{ $row['net'] }}</dd>
                </div>
            </dl>
        </article>
    @else
        <article class="min-w-0 rounded-xl border border-[var(--site-line)] bg-[var(--site-panel)] p-3">
            <div class="flex min-w-0 items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-[var(--site-muted)]">{{ $row['when'] }}</p>
                    <p class="mt-1 break-words text-sm font-semibold">{{ ($row['game'] ?? '') !== '' ? $row['game'] : $row['label'] }}</p>
                    @if (($row['game'] ?? '') !== '')
                        <p class="text-xs text-[var(--site-muted)]">{{ $row['label'] }}</p>
                    @endif
                </div>
                <p class="acct-{{ $row['tone'] }} shrink-0 font-numeric text-base font-bold">{{ $row['amount'] }}</p>
            </div>
            <p class="mt-2 text-xs text-[var(--site-muted)]">{{ __('account.after') }} <span class="font-numeric text-sm font-semibold text-[var(--site-text)]">{{ $row['after'] }}</span></p>
        </article>
    @endif
@endforeach
