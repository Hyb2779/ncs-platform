@extends('layouts.panel')

@section('heading', __('sport.panel.translations'))

@section('content')
    <p class="mb-4 text-sm text-slate-600">{{ __('sport.panel.pending', ['count' => $pending]) }}</p>
    <x-panel.filter-bar class="mb-4" :dates="false">
        <form class="flex flex-wrap items-center gap-2" method="GET">
            <select class="h-11 rounded-md border px-2" name="type">
                @foreach (['team', 'league', 'country'] as $option)
                    <option value="{{ $option }}" @selected($type === $option)>{{ __('sport.panel.types.'.$option) }}</option>
                @endforeach
            </select>
            <select class="h-11 rounded-md border px-2" name="locale">
                @foreach (['tr', 'en', 'de', 'ar'] as $option)
                    <option value="{{ $option }}" @selected($locale === $option)>{{ __('panel.languages.'.$option) }}</option>
                @endforeach
            </select>
            <label class="inline-flex h-11 items-center gap-2 text-sm">
                <input type="checkbox" name="missing" value="1" @checked(request()->boolean('missing'))>
                {{ __('sport.panel.missing') }}
            </label>
            <input class="h-11 rounded-md border px-3" name="q" value="{{ request('q') }}" placeholder="{{ __('sport.panel.search') }}">
            <button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">{{ __('sport.panel.search') }}</button>
        </form>
    </x-panel.filter-bar>
    @php
        $tableRows = [];
        foreach ($rows as $row) {
            $form = 'translation-'.$type.'-'.$row->id;
            $tableRows[] = [
                'source' => $row->name,
                'name' => new \Illuminate\Support\HtmlString('<input form="'.e($form).'" class="h-11 min-w-48 rounded-md border px-3" name="name" value="'.e($names[$row->id] ?? '').'">'),
                'save' => new \Illuminate\Support\HtmlString('<form id="'.e($form).'" method="POST" action="'.e(route('panel.sport.translations.update')).'">'.csrf_field().method_field('PUT').'<input type="hidden" name="entity_type" value="'.e($type).'"><input type="hidden" name="entity_id" value="'.e($row->id).'"><input type="hidden" name="locale" value="'.e($locale).'"><button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">'.e(__('sport.panel.save')).'</button></form>'),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'source', 'label' => __('sport.panel.translations')],
            ['key' => 'name', 'label' => __('panel.languages.'.$locale)],
            ['key' => 'save', 'label' => __('sport.panel.save')],
        ]"
        :rows="$tableRows"
    />
@endsection
