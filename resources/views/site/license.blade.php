@extends('layouts.site')

@section('heading', __('site.license_title'))

@section('content')
<div class="mx-auto flex w-full max-w-lg flex-col gap-4">
    <a class="self-start text-sm font-semibold text-[var(--accent)]" href="{{ route('site.home') }}">{{ __('site.license_back') }}</a>
    <article class="flex flex-col gap-3 rounded-[10px] border border-[var(--site-line)] bg-[var(--site-panel)] p-6" dir="ltr">
        <h1 class="text-lg font-bold text-[var(--site-text)]">{{ __('site.license_regulator') }}</h1>
        <p class="font-mono text-sm text-[var(--site-text-2)]">{{ __('site.license_no', ['no' => $licenseNo]) }}</p>
        <p class="font-mono text-sm text-[var(--site-text-2)]">{{ __('site.license_company', ['no' => $companyNo]) }}</p>
        <p class="font-mono text-sm text-[var(--site-text-2)]">{{ __('site.license_domain') }}: {{ $domain }}</p>
        <p class="text-sm text-[var(--site-text-2)]">{{ __('site.license_status') }}: {{ __('site.license_status_value') }}</p>
    </article>
</div>
@endsection
