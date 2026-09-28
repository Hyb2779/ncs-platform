<?php

namespace Tests\Feature;

use App\Services\Sport\FenixResults;
use Tests\TestCase;

class FenixResultsParseTest extends TestCase
{
    private function market(int $id, string $name, array $wonNames, array $lostNames = []): array
    {
        $odds = [];
        foreach ($wonNames as $n) {
            $odds[] = ['status' => 3, 'metadata' => ['name' => $n]];
        }
        foreach ($lostNames as $n) {
            $odds[] = ['status' => 4, 'metadata' => ['name' => $n]];
        }

        return ['id' => $id, 'name' => $name, 'odds' => $odds];
    }

    public function test_correct_score_and_half_time_from_team_totals(): void
    {
        $r = FenixResults::parse([
            $this->market(537, 'Correct Score', ['3:1'], ['1:1', '2:0']),
            $this->market(2529, '1st half - Total Goals Home', ['Over 0.5', 'Over 1.5', 'Under 2.5'], ['Under 0.5', 'Under 1.5', 'Over 2.5']),
            $this->market(2531, '1st Half - Away Total Goals', ['Under 0.5', 'Under 1.5'], ['Over 0.5', 'Over 1.5']),
        ]);

        $this->assertSame(['ft' => [3, 1], 'ht' => [2, 0]], $r);
    }

    public function test_half_time_correct_score_wins_and_inconsistent_half_is_dropped(): void
    {
        $this->assertSame(['ft' => [2, 2], 'ht' => [1, 0]], FenixResults::parse([
            $this->market(537, 'Correct Score', ['2:2']),
            $this->market(999, '1st Half - Correct Score', ['1:0']),
        ]));

        $this->assertSame(['ft' => [1, 0], 'ht' => null], FenixResults::parse([
            $this->market(537, 'Correct Score', ['1:0']),
            $this->market(999, '1st Half - Correct Score', ['2:0']),
        ]));
    }

    public function test_without_correct_score_nothing_is_written(): void
    {
        $this->assertNull(FenixResults::parse([
            $this->market(547, 'Full Time', ['1'], ['X', '2']),
        ]));
    }
}
