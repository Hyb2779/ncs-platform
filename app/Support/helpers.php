<?php

use App\Services\Sport\SportNames;
use App\Support\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

function brand(): Brand
{
    return app(Brand::class);
}

function sport_name(?Model $entity): string
{
    return app(SportNames::class)->name($entity);
}

function sport_status(?string $code): string
{
    $key = 'sport.statuses.'.($code ?? '');

    return Lang::has($key) ? __($key) : (string) $code;
}

function sport_date(Carbon $date, string $format): string
{
    $formatted = $date->copy()->locale(app()->getLocale())->translatedFormat($format);
    $eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    return str_replace($eastern, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $formatted);
}
