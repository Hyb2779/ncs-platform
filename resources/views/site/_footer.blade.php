@php($footer = $siteFooter ?? null)
@if ($footer)
<footer class="border-t border-[var(--site-line)] px-6 py-8 text-[var(--site-text)]">
    <div class="mx-auto flex w-full max-w-[1100px] flex-col items-center gap-[18px]">
        <div class="flex w-full max-w-full flex-wrap items-stretch justify-center gap-3">
            @foreach ([
                ['key' => 'slots', 'label' => 'site.footer_slots'],
                ['key' => 'live', 'label' => 'site.footer_tables'],
                ['key' => 'providers', 'label' => 'site.footer_providers'],
            ] as $stat)
                <div class="flex min-w-[96px] flex-col items-center justify-center gap-1 rounded-[10px] border border-[var(--site-line)] bg-[var(--site-panel)] px-3 py-3 md:min-w-[120px]">
                    <p class="font-numeric text-[17px] font-bold leading-none md:text-[20px]" data-footer-stat="{{ $stat['key'] }}">{{ \App\Services\Casino\GameCatalog::formatPlus($footer[$stat['key']]) }}</p>
                    <p class="text-center text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--site-muted)]">{{ __($stat['label']) }}</p>
                </div>
            @endforeach
        </div>

        @foreach ($footer['groups'] as $group)
            <section class="flex w-full max-w-full flex-col items-center gap-2">
                <h2 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-[var(--site-muted)]">{{ __($group['title']) }}</h2>
                <div class="flex w-full max-w-full flex-wrap justify-center gap-2">
                    @foreach ($group['items'] as $item)
                        <a class="inline-flex max-w-full items-center justify-center rounded-full border border-[var(--site-line)] px-3 py-1.5 text-center text-[11px] leading-tight text-[var(--site-text-2)] hover:border-[var(--site-text)] hover:text-[var(--site-text)] md:text-[12px]" href="{{ route($group['route'], ['vendor' => $item['slug']]) }}">{{ $item['name'] }}</a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex w-full max-w-full flex-wrap items-stretch justify-center gap-3">
            <a class="flex max-w-full items-center gap-3 rounded-[10px] border border-[var(--site-line)] bg-[var(--site-panel)] px-3 py-2.5 text-start hover:border-[var(--site-text)]" dir="ltr" href="{{ route('site.license') }}" target="_blank" rel="noopener noreferrer">
                <span class="inline-flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-lg border border-[var(--site-line)] text-lg" aria-hidden="true">⛨</span>
                <span class="flex min-w-0 flex-col">
                    <span class="text-[12px] font-bold leading-tight">{{ __('site.license_regulator') }}</span>
                    <span class="font-mono text-[10px] leading-tight text-[var(--site-muted)]">{{ __('site.license_no', ['no' => $footer['license_no']]) }}</span>
                    <span class="font-mono text-[10px] leading-tight text-[var(--site-muted)]">{{ __('site.license_company', ['no' => $footer['company_no']]) }}</span>
                    <span class="font-mono text-[10px] leading-tight text-[var(--site-muted)]">{{ __('site.license_verify', ['domain' => \App\Services\Casino\GameCatalog::domain()]) }}</span>
                </span>
            </a>
            <div class="inline-flex min-w-[96px] items-center justify-center rounded-[10px] border border-[var(--site-line)] bg-[var(--site-panel)] px-4 text-base font-extrabold">{{ __('site.footer_age') }}</div>
        </div>
    </div>
</footer>
@endif
