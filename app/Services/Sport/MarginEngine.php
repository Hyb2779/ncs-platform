<?php

namespace App\Services\Sport;

use App\Models\SportFixture;
use App\Models\SportMargin;

class MarginEngine
{
    public function show(string $raw, SportFixture $fixture, string $marketCode, ?int $superadminId): string
    {
        $rule = $this->rule($fixture, $marketCode, $superadminId);
        $margin = bcadd((string) ($rule->margin ?? '0'), '0', 4);
        $divisor = bcadd('1', $margin, 4);
        $shown = number_format(round((float) bcdiv($raw, $divisor, 6), 2), 2, '.', '');

        if (bccomp($shown, '1.01', 2) === -1) {
            $shown = '1.01';
        }

        $max = $rule?->max_odd;
        if ($max !== null && bccomp($shown, (string) $max, 2) === 1) {
            $shown = bcadd((string) $max, '0', 2);
        }

        return $shown;
    }

    private function rule(SportFixture $fixture, string $marketCode, ?int $superadminId): ?SportMargin
    {
        $layers = [
            ['market', fn ($q) => $q->where('fixture_id', $fixture->id)->where('market_code', $marketCode)],
            ['fixture', fn ($q) => $q->where('fixture_id', $fixture->id)->whereNull('market_code')],
            ['league', fn ($q) => $q->where('league_id', $fixture->league_id)],
            ['global', fn ($q) => $q],
        ];

        foreach ($layers as [$layer, $narrow]) {
            $query = SportMargin::query()->where('layer', $layer);
            $narrow($query);
            $rows = $query->get();
            $match = $rows->firstWhere('superadmin_id', $superadminId) ?? $rows->firstWhere('superadmin_id', null);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }
}
