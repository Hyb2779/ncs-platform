@extends('layouts.panel')

@section('heading', __('sport.panel.leagues'))

@section('content')
    @php
        $rows = [];
        foreach ($leagues as $league) {
            $form = 'league-'.$league->id;
            $rows[] = [
                'name' => sport_name($league->country).' · '.sport_name($league),
                'active' => new \Illuminate\Support\HtmlString('<label class="inline-flex h-11 items-center gap-2"><input form="'.e($form).'" type="checkbox" name="is_active" value="1"'.($league->is_active ? ' checked' : '').'> '.e(__('sport.panel.active')).'</label>'),
                'featured' => new \Illuminate\Support\HtmlString('<label class="inline-flex h-11 items-center gap-2"><input form="'.e($form).'" type="checkbox" name="is_featured" value="1"'.($league->is_featured ? ' checked' : '').'> '.e(__('sport.panel.featured')).'</label>'),
                'sort' => new \Illuminate\Support\HtmlString('<input form="'.e($form).'" class="h-11 w-20 rounded-md border px-2" name="sort_order" value="'.e($league->sort_order).'">'),
                'save' => new \Illuminate\Support\HtmlString('<form id="'.e($form).'" method="POST" action="'.e(route('panel.sport.leagues.update', $league)).'">'.csrf_field().method_field('PUT').'<button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">'.e(__('sport.panel.save')).'</button></form>'),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'name', 'label' => __('sport.panel.leagues')],
            ['key' => 'active', 'label' => __('sport.panel.active')],
            ['key' => 'featured', 'label' => __('sport.panel.featured'), 'priority' => 'detail'],
            ['key' => 'sort', 'label' => __('site.order'), 'priority' => 'detail'],
            ['key' => 'save', 'label' => __('sport.panel.save')],
        ]"
        :rows="$rows"
    />
@endsection
