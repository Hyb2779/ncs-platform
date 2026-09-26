@extends('layouts.panel')

@section('heading', __('site.panel_sessions'))

@section('content')
    <x-panel.filter-bar class="mb-4" />
    @php
        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                'when' => $row->opened_at->timezone(auth()->user()->timezone)->format('d.m.Y H:i'),
                'user' => $row->user->username,
                'game' => $row->game->name,
                'provider' => $row->game->provider->name,
                'ip' => $row->ip,
                'device' => $row->device,
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'when', 'label' => __('wallet.when')],
            ['key' => 'user', 'label' => __('sport.panel.user')],
            ['key' => 'game', 'label' => __('site.panel_games')],
            ['key' => 'provider', 'label' => __('site.panel_providers'), 'priority' => 'detail'],
            ['key' => 'ip', 'label' => __('sport.panel.cols.ip'), 'priority' => 'detail'],
            ['key' => 'device', 'label' => __('site.device'), 'priority' => 'detail'],
        ]"
        :rows="$tableRows"
    />
@endsection
