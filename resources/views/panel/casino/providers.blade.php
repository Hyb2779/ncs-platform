@extends('layouts.panel')

@section('heading', __('site.panel_providers'))

@section('content')
    @php
        $rows = [];
        foreach ($providers as $provider) {
            $tone = $provider->status === 'active' ? 'success' : 'warning';
            $label = $provider->status === 'active' ? __('site.active') : __('site.passive');
            $badge = \Illuminate\Support\Facades\Blade::render('<x-panel.badge :tone="$tone">{{ $label }}</x-panel.badge>', ['tone' => $tone, 'label' => $label]);
            $toggle = '<form method="POST" action="'.e(route('panel.casino.providers.update', $provider)).'">'.csrf_field().method_field('PUT').'<input type="hidden" name="status" value="'.e($provider->status === 'active' ? 'passive' : 'active').'"><button class="inline-flex h-11 items-center rounded-lg border px-3 text-sm" type="submit">'.e($label).'</button></form>';
            $sync = '<form method="POST" action="'.e(route('panel.casino.providers.sync', $provider)).'">'.csrf_field().'<button class="inline-flex h-11 items-center rounded-lg bg-[#161A22] px-3 text-sm text-white" type="submit">'.e(__('site.sync')).'</button></form>';
            $rows[] = [
                'name' => $provider->name,
                'status' => new \Illuminate\Support\HtmlString($badge),
                'actions' => new \Illuminate\Support\HtmlString('<div class="flex flex-wrap gap-2">'.$toggle.$sync.'</div>'),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'name', 'label' => __('site.panel_providers')],
            ['key' => 'status', 'label' => __('panel.fields.status')],
            ['key' => 'actions', 'label' => __('panel.fields.actions')],
        ]"
        :rows="$rows"
    />
@endsection
