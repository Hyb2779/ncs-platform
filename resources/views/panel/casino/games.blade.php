@extends('layouts.panel')

@section('heading', __('site.panel_games'))

@section('content')
    @php
        $rows = [];
        foreach ($games as $game) {
            $form = 'game-'.$game->id;
            $rows[] = [
                'name' => $game->name,
                'active' => new \Illuminate\Support\HtmlString('<label class="inline-flex h-11 items-center gap-2"><input form="'.e($form).'" type="checkbox" name="is_active" value="1"'.($game->is_active ? ' checked' : '').'> '.e(__('site.active')).'</label>'),
                'popular' => new \Illuminate\Support\HtmlString('<label class="inline-flex h-11 items-center gap-2"><input form="'.e($form).'" type="checkbox" name="is_popular" value="1"'.($game->is_popular ? ' checked' : '').'> '.e(__('site.popular')).'</label>'),
                'sort' => new \Illuminate\Support\HtmlString('<input form="'.e($form).'" class="h-11 w-20 rounded-md border px-2" name="sort_order" value="'.e($game->sort_order).'" aria-label="'.e(__('site.order')).'">'),
                'save' => new \Illuminate\Support\HtmlString('<form id="'.e($form).'" method="POST" action="'.e(route('panel.casino.games.update', $game)).'">'.csrf_field().method_field('PUT').'<button class="inline-flex h-11 items-center rounded-lg border px-3" type="submit">'.e(__('panel.save')).'</button></form>'),
            ];
        }
    @endphp
    <x-panel.table
        :columns="[
            ['key' => 'name', 'label' => __('site.panel_games')],
            ['key' => 'active', 'label' => __('site.active')],
            ['key' => 'popular', 'label' => __('site.popular'), 'priority' => 'detail'],
            ['key' => 'sort', 'label' => __('site.order'), 'priority' => 'detail'],
            ['key' => 'save', 'label' => __('panel.save')],
        ]"
        :rows="$rows"
    />
@endsection
