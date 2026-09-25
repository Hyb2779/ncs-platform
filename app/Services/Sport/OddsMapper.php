<?php

namespace App\Services\Sport;

class OddsMapper
{
    /**
     * @param  array<string, mixed>  $bet
     * @return list<array{market: string, outcome: string, raw: string}>
     */
    public function map(array $bet): array
    {
        $id = (int) ($bet['id'] ?? 0);
        $rows = [];

        foreach ($bet['values'] ?? [] as $value) {
            $label = (string) ($value['value'] ?? '');
            $odd = (string) ($value['odd'] ?? '');
            if ($odd === '' || ! is_numeric($odd)) {
                continue;
            }

            $mapped = match ($id) {
                1 => $this->winner('1X2', $label),
                13 => $this->winner('HT1X2', $label),
                12 => match ($label) {
                    'Home/Draw' => ['DC', 'home_draw'],
                    'Home/Away' => ['DC', 'home_away'],
                    'Draw/Away' => ['DC', 'draw_away'],
                    default => null,
                },
                8 => match ($label) {
                    'Yes' => ['BTTS', 'yes'],
                    'No' => ['BTTS', 'no'],
                    default => null,
                },
                5 => $this->total($label),
                default => null,
            };

            if ($mapped !== null) {
                $rows[] = ['market' => $mapped[0], 'outcome' => $mapped[1], 'raw' => bcadd($odd, '0', 2)];
            }
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function winner(string $market, string $label): ?array
    {
        $outcome = match ($label) {
            'Home' => 'home',
            'Draw' => 'draw',
            'Away' => 'away',
            default => null,
        };

        return $outcome === null ? null : [$market, $outcome];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function total(string $label): ?array
    {
        if (! preg_match('/^(Over|Under) (1\.5|2\.5|3\.5)$/', $label, $match)) {
            return null;
        }

        $market = match ($match[2]) {
            '1.5' => 'OU15',
            '2.5' => 'OU25',
            '3.5' => 'OU35',
        };

        return [$market, $match[1] === 'Over' ? 'over' : 'under'];
    }
}
