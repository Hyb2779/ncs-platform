<?php

namespace Tests\Unit;

use App\Services\Sport\SelectionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SelectionEvaluatorTest extends TestCase
{
    private SelectionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SelectionEvaluator;
    }

    #[DataProvider('winnerCases')]
    public function test_match_result_uses_full_time_score(string $outcome, int $home, int $away, string $expected): void
    {
        $this->assertSame($expected, $this->evaluator->evaluate('1X2', $outcome, $home, $away, 0, 0));
    }

    #[DataProvider('winnerCases')]
    public function test_half_time_result_uses_half_time_score(string $outcome, int $home, int $away, string $expected): void
    {
        $this->assertSame($expected, $this->evaluator->evaluate('HT1X2', $outcome, 9, 9, $home, $away));
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function winnerCases(): array
    {
        return [
            ['home', 1, 0, 'won'],
            ['home', 0, 0, 'lost'],
            ['home', 0, 1, 'lost'],
            ['draw', 0, 0, 'won'],
            ['draw', 2, 1, 'lost'],
            ['away', 0, 1, 'won'],
            ['away', 3, 3, 'lost'],
        ];
    }

    #[DataProvider('doubleChanceCases')]
    public function test_double_chance(string $outcome, int $home, int $away, string $expected): void
    {
        $this->assertSame($expected, $this->evaluator->evaluate('DC', $outcome, $home, $away, 0, 0));
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function doubleChanceCases(): array
    {
        return [
            ['home_draw', 1, 0, 'won'],
            ['home_draw', 0, 0, 'won'],
            ['home_draw', 0, 1, 'lost'],
            ['home_away', 2, 1, 'won'],
            ['home_away', 1, 2, 'won'],
            ['home_away', 0, 0, 'lost'],
            ['draw_away', 0, 0, 'won'],
            ['draw_away', 0, 2, 'won'],
            ['draw_away', 1, 0, 'lost'],
        ];
    }

    #[DataProvider('totalsCases')]
    public function test_totals_never_land_on_half_lines(string $market, string $outcome, int $home, int $away, string $expected): void
    {
        $this->assertSame($expected, $this->evaluator->evaluate($market, $outcome, $home, $away, 0, 0));
    }

    /**
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: string}>
     */
    public static function totalsCases(): array
    {
        return [
            ['OU15', 'over', 0, 0, 'lost'],
            ['OU15', 'under', 0, 0, 'won'],
            ['OU15', 'over', 1, 1, 'won'],
            ['OU15', 'under', 1, 0, 'won'],
            ['OU25', 'over', 1, 1, 'lost'],
            ['OU25', 'under', 1, 1, 'won'],
            ['OU25', 'over', 2, 1, 'won'],
            ['OU25', 'under', 3, 0, 'lost'],
            ['OU35', 'over', 2, 1, 'lost'],
            ['OU35', 'under', 2, 1, 'won'],
            ['OU35', 'over', 2, 2, 'won'],
            ['OU35', 'under', 4, 0, 'lost'],
        ];
    }

    public function test_totals_void_when_total_equals_the_line(): void
    {
        $method = new \ReflectionMethod(SelectionEvaluator::class, 'totals');
        $this->assertSame('void', $method->invoke($this->evaluator, 'over', 1, 1, '2.0'));
        $this->assertSame('void', $method->invoke($this->evaluator, 'under', 2, 0, '2.0'));
    }

    #[DataProvider('bttsCases')]
    public function test_both_teams_to_score(string $outcome, int $home, int $away, string $expected): void
    {
        $this->assertSame($expected, $this->evaluator->evaluate('BTTS', $outcome, $home, $away, 0, 0));
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function bttsCases(): array
    {
        return [
            ['yes', 0, 0, 'lost'],
            ['no', 0, 0, 'won'],
            ['yes', 1, 0, 'lost'],
            ['no', 1, 0, 'won'],
            ['yes', 1, 1, 'won'],
            ['no', 2, 2, 'lost'],
        ];
    }

    public function test_unknown_market_stays_pending(): void
    {
        $this->assertNull($this->evaluator->evaluate('CS', '1-0', 1, 0, 0, 0));
        $this->assertNull($this->evaluator->evaluate('1X2', 'other', 1, 0, 0, 0));
        $this->assertNull($this->evaluator->evaluate('1X2', 'home', null, 0, 0, 0));
    }
}
