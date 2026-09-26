<?php

namespace App\Services\Sport;

use Illuminate\Support\Facades\Log;

class SelectionEvaluator
{
    public function evaluate(string $market, string $outcome, ?int $ftHome, ?int $ftAway, ?int $htHome, ?int $htAway): ?string
    {
        return match ($market) {
            '1X2' => $this->winner($outcome, $ftHome, $ftAway),
            'HT1X2' => $this->winner($outcome, $htHome, $htAway),
            'DC' => $this->doubleChance($outcome, $ftHome, $ftAway),
            'OU15' => $this->totals($outcome, $ftHome, $ftAway, '1.5'),
            'OU25' => $this->totals($outcome, $ftHome, $ftAway, '2.5'),
            'OU35' => $this->totals($outcome, $ftHome, $ftAway, '3.5'),
            'BTTS' => $this->bothTeams($outcome, $ftHome, $ftAway),
            default => $this->unknown($market, $outcome),
        };
    }

    private function winner(string $outcome, ?int $home, ?int $away): ?string
    {
        if ($home === null || $away === null) {
            return null;
        }

        $actual = match (true) {
            $home > $away => 'home',
            $home < $away => 'away',
            default => 'draw',
        };

        return match ($outcome) {
            'home', 'draw', 'away' => $actual === $outcome ? 'won' : 'lost',
            default => $this->unknown('winner', $outcome),
        };
    }

    private function doubleChance(string $outcome, ?int $home, ?int $away): ?string
    {
        if ($home === null || $away === null) {
            return null;
        }

        $hit = match ($outcome) {
            'home_draw' => $home >= $away,
            'home_away' => $home !== $away,
            'draw_away' => $home <= $away,
            default => null,
        };

        if ($hit === null) {
            return $this->unknown('DC', $outcome);
        }

        return $hit ? 'won' : 'lost';
    }

    private function totals(string $outcome, ?int $home, ?int $away, string $line): ?string
    {
        if ($home === null || $away === null) {
            return null;
        }

        $total = (string) ($home + $away);
        $cmp = bccomp($total, $line, 1);
        if ($cmp === 0) {
            return 'void';
        }

        return match ($outcome) {
            'over' => $cmp === 1 ? 'won' : 'lost',
            'under' => $cmp === -1 ? 'won' : 'lost',
            default => $this->unknown('OU', $outcome),
        };
    }

    private function bothTeams(string $outcome, ?int $home, ?int $away): ?string
    {
        if ($home === null || $away === null) {
            return null;
        }

        $yes = $home > 0 && $away > 0;

        return match ($outcome) {
            'yes' => $yes ? 'won' : 'lost',
            'no' => $yes ? 'lost' : 'won',
            default => $this->unknown('BTTS', $outcome),
        };
    }

    private function unknown(string $market, string $outcome): ?string
    {
        Log::warning('sport.selection.unknown', ['market' => $market, 'outcome' => $outcome]);

        return null;
    }
}
