@extends('layouts.panel')

@section('heading', __('panel.online_title').' ('.count($rows).')')

@section('content')
<p class="mb-3 text-xs text-slate-500">{{ __('panel.online_note') }}</p>
<x-panel.table :empty="__('panel.online_empty')" :rows="$rows" :columns="[
    ['key' => 'user', 'label' => __('panel.online_user')],
    ['key' => 'role', 'label' => __('panel.online_role')],
    ['key' => 'parent', 'label' => __('panel.online_parent')],
    ['key' => 'area', 'label' => __('panel.online_area')],
    ['key' => 'seen', 'label' => __('panel.online_seen')],
    ['key' => 'ip', 'label' => __('panel.online_ip'), 'priority' => 'detail'],
    ['key' => 'device', 'label' => __('panel.online_device'), 'priority' => 'detail'],
]" />
<script>setTimeout(function () { location.reload(); }, 60000);</script>
@endsection
