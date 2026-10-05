@if ($pager->hasPages())
    <div class="mt-3 flex items-center justify-between text-sm">
        @if ($pager->onFirstPage())
            <span class="inline-flex h-11 items-center text-slate-400">{{ __('panel.logs_prev') }}</span>
        @else
            <a class="inline-flex h-11 items-center font-medium" href="{{ $pager->previousPageUrl() }}">{{ __('panel.logs_prev') }}</a>
        @endif
        <span class="font-numeric text-slate-500">{{ $pager->currentPage() }} / {{ $pager->lastPage() }}</span>
        @if ($pager->hasMorePages())
            <a class="inline-flex h-11 items-center font-medium" href="{{ $pager->nextPageUrl() }}">{{ __('panel.logs_next') }}</a>
        @else
            <span class="inline-flex h-11 items-center text-slate-400">{{ __('panel.logs_next') }}</span>
        @endif
    </div>
@endif
