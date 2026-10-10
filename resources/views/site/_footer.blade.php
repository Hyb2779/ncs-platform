@php($footer = $siteFooter ?? null)
@if ($footer)
<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-stats">
            @foreach ([
                ['key' => 'slots', 'label' => 'site.footer_slots'],
                ['key' => 'live', 'label' => 'site.footer_tables'],
                ['key' => 'providers', 'label' => 'site.footer_providers'],
            ] as $stat)
                <div class="site-footer-stat">
                    <p class="site-footer-stat-value" data-footer-stat="{{ $stat['key'] }}">{{ \App\Services\Casino\GameCatalog::formatPlus($footer[$stat['key']]) }}</p>
                    <p class="site-footer-stat-label">{{ __($stat['label']) }}</p>
                </div>
            @endforeach
        </div>

        @if ($footer['groups'] !== [])
            <div class="site-footer-groups">
                @foreach ($footer['groups'] as $group)
                    <section class="site-footer-group">
                        <h2 class="site-footer-group-title">{{ __($group['title']) }}</h2>
                        <div class="site-footer-pills">
                            @foreach ($group['items'] as $item)
                                <a class="site-footer-pill" href="{{ route($group['route'], ['vendor' => $item['slug']]) }}">{{ $item['name'] }}</a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif

        <div class="site-footer-badges">
            <a class="site-footer-seal" dir="ltr" href="{{ route('site.license') }}" target="_blank" rel="noopener noreferrer">
                <span class="site-footer-seal-icon" aria-hidden="true">⛨</span>
                <span class="site-footer-seal-copy">
                    <span class="site-footer-seal-title">{{ __('site.license_regulator') }}</span>
                    <span class="site-footer-seal-line">{{ __('site.license_no', ['no' => $footer['license_no']]) }}</span>
                    <span class="site-footer-seal-line">{{ __('site.license_company', ['no' => $footer['company_no']]) }}</span>
                    <span class="site-footer-seal-line">{{ __('site.license_verify', ['domain' => \App\Services\Casino\GameCatalog::domain()]) }}</span>
                </span>
            </a>
            <div class="site-footer-age">{{ __('site.footer_age') }}</div>
        </div>
        <a class="site-footer-logo" href="{{ route('site.home') }}" aria-label="{{ brand()->name() }}">@include('brand.logo')</a>
    </div>
</footer>
@endif
