<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\HtmlString;

/** Süperadmin ve bayi yanıtlarında ev sonucu (GGR) alanları yer almaz. */
final class GgrPayload
{
    public static function visible(User $viewer): bool
    {
        return $viewer->role === UserRole::Owner;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function present(User $viewer, array $payload): array
    {
        $payload = self::scalars($payload);

        return self::visible($viewer) ? $payload : self::strip($payload);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function strip(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::blocked($key)) {
                continue;
            }
            if ($key === 'datasets' && is_array($value)) {
                $value = array_values(array_filter($value, function ($set): bool {
                    $label = is_array($set) ? (string) ($set['label'] ?? '') : '';

                    return ! self::blocked($label);
                }));
            }
            $out[$key] = is_array($value) ? self::strip($value) : $value;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private static function scalars(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if ($value instanceof HtmlString) {
                $out[$key] = trim(html_entity_decode(strip_tags($value->toHtml())));

                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::scalars($value);

                continue;
            }
            if (is_object($value)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private static function blocked(string $key): bool
    {
        $name = strtolower($key);
        if (str_contains($name, 'ggr') || str_contains($name, 'net_gaming') || str_contains($name, 'house_edge')) {
            return true;
        }

        return in_array($name, ['general', 'commission'], true);
    }
}
