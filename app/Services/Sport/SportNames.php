<?php

namespace App\Services\Sport;

use App\Models\SportCountry;
use App\Models\SportLeague;
use App\Models\SportTeam;
use App\Models\SportTranslation;
use Illuminate\Database\Eloquent\Model;

class SportNames
{
    /** @var array<string, array<int, string>> */
    private array $cache = [];

    private ?string $locale = null;

    public function name(?Model $entity): string
    {
        if ($entity === null) {
            return '';
        }

        $type = $this->type($entity);
        $fallback = (string) $entity->getAttribute('name');
        if ($type === null) {
            return $fallback;
        }

        $locale = app()->getLocale();
        if ($this->locale !== $locale) {
            $this->cache = [];
            $this->locale = $locale;
        }

        if (! isset($this->cache[$type])) {
            $this->cache[$type] = SportTranslation::query()
                ->where('entity_type', $type)
                ->where('locale', $locale)
                ->pluck('name', 'entity_id')
                ->all();
        }

        $translated = $this->cache[$type][$entity->getKey()] ?? '';

        return $translated !== '' ? $translated : $fallback;
    }

    public function type(Model $entity): ?string
    {
        return match (true) {
            $entity instanceof SportTeam => 'team',
            $entity instanceof SportLeague => 'league',
            $entity instanceof SportCountry => 'country',
            default => null,
        };
    }
}
