@props(['columns' => [], 'rows' => [], 'empty' => null])

@php
    $primary = array_values(array_filter($columns, fn (array $column): bool => ($column['priority'] ?? 'primary') !== 'detail'));
    $detail = array_values(array_filter($columns, fn (array $column): bool => ($column['priority'] ?? 'primary') === 'detail'));
    $cell = function (mixed $value): string {
        if ($value instanceof \Illuminate\Support\HtmlString) {
            return (string) $value;
        }

        return e((string) $value);
    };
@endphp

@if ($rows === [])
    <x-panel.empty :message="$empty ?? __('panel.empty_rows')" />
@else
    <div class="hidden overflow-x-auto rounded-lg bg-white md:block">
        <table class="w-full text-start text-sm">
            <thead>
                <tr class="border-b border-[#E3E6EB] text-xs text-slate-500">
                    @foreach ($columns as $column)
                        <th class="px-3 py-2 font-medium">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-[#EEF1F4] last:border-0"@if (isset($row['_attrs']['data-before'])) data-before="{{ $row['_attrs']['data-before'] }}" data-amount="{{ $row['_attrs']['data-amount'] }}" data-after="{{ $row['_attrs']['data-after'] }}"@endif>
                        @foreach ($columns as $column)
                            <td class="px-3 py-2">{!! $cell($row[$column['key']] ?? '') !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="grid gap-3 md:hidden">
        @foreach ($rows as $row)
            <article class="rounded-lg bg-white p-3">
                @foreach ($primary as $column)
                    <p class="text-xs text-slate-500">{{ $column['label'] }}</p>
                    <p class="mb-2 text-sm">{!! $cell($row[$column['key']] ?? '') !!}</p>
                @endforeach
                @if ($detail !== [])
                    <details>
                        <summary class="flex h-11 cursor-pointer list-none items-center text-sm text-slate-600">{{ __('panel.table_detail') }}</summary>
                        @foreach ($detail as $column)
                            <p class="text-xs text-slate-500">{{ $column['label'] }}</p>
                            <p class="mb-2 text-sm">{!! $cell($row[$column['key']] ?? '') !!}</p>
                        @endforeach
                    </details>
                @endif
            </article>
        @endforeach
    </div>
@endif
