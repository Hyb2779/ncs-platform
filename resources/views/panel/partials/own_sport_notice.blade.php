@unless (config('sport.own_book_enabled'))
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-slate-700">
        <p class="mb-1 font-semibold text-slate-900">{{ __('panel.own_sport_notice_title') }}</p>
        <p>{{ __('panel.own_sport_notice_body') }}</p>
    </div>
@endunless
