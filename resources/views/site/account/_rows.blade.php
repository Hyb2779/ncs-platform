@foreach ($rows as $row)
    @if ($tab === 'games')
        <tr class="border-t border-[var(--site-line)]">
            <td class="px-3 py-2">
                <span class="flex min-w-0 items-center gap-2">
                    @if ($row['image'] !== '')
                        <img class="h-8 w-8 shrink-0 rounded-md object-cover" src="{{ $row['image'] }}" alt="" width="32" height="32">
                    @else
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-[var(--site-panel-2)] text-xs font-bold">{{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}</span>
                    @endif
                    <span class="truncate">{{ $row['name'] }}</span>
                </span>
            </td>
            <td class="px-3 py-2 text-[var(--site-muted)]">{{ $row['category'] }}</td>
            <td class="px-3 py-2 text-end font-numeric">{{ $row['bet'] }}</td>
            <td class="px-3 py-2 text-end font-numeric">{{ $row['win'] }}</td>
            <td class="acct-{{ $row['tone'] }} px-3 py-2 text-end font-numeric">{{ $row['net'] }}</td>
        </tr>
    @else
        <tr class="border-t border-[var(--site-line)]">
            <td class="px-3 py-2">{{ $row['when'] }}</td>
            <td class="px-3 py-2">
                @if (($row['game'] ?? '') !== '')
                    <span class="block break-words font-semibold">{{ $row['game'] }}</span>
                    <span class="block text-xs text-[var(--site-muted)]">{{ $row['label'] }}</span>
                @else
                    <span class="block break-words">{{ $row['label'] }}</span>
                @endif
            </td>
            <td class="acct-{{ $row['tone'] }} px-3 py-2 text-end font-numeric">{{ $row['amount'] }}</td>
            <td class="px-3 py-2 text-end font-numeric">{{ $row['after'] }}</td>
        </tr>
    @endif
@endforeach
