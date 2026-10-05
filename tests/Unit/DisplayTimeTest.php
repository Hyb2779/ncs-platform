<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class DisplayTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_offsetless_and_offset_inputs_format_as_istanbul(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Istanbul']);

        $unix = Carbon::parse('2026-10-05 17:00:00', 'UTC')->timestamp;
        $expected = display_instant('2026-10-05T17:00:00Z');

        foreach ([
            '2026-10-05 17:00:00',
            '2026-10-05T17:00:00',
            '2026-10-05T17:00:00Z',
            '2026-10-05T20:00:00+03:00',
            '2026-10-05T19:00:00+02:00',
            $unix,
            (string) $unix,
            Carbon::parse('2026-10-05 17:00:00', 'UTC'),
        ] as $input) {
            $shown = display_instant($input);
            $this->assertSame($expected->timestamp, $shown->timestamp);
            $this->assertSame('Europe/Istanbul', $shown->timezoneName);
            $this->assertSame('20:00', display_clock($input));
            $this->assertSame('2026-10-05', $shown->toDateString());
        }
    }

    public function test_source_instant_is_not_mutated(): void
    {
        $source = Carbon::parse('2026-10-05 17:00:00', 'UTC');

        display_instant($source);

        $this->assertSame('UTC', $source->timezoneName);
        $this->assertSame('2026-10-05 17:00', $source->format('Y-m-d H:i'));
    }

    public function test_midnight_kickoff_stays_on_the_istanbul_day(): void
    {
        config(['app.display_timezone' => 'Europe/Istanbul']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 21:30:00', 'UTC'));

        $kickoff = display_instant('2026-10-05T21:30:00Z');

        $this->assertSame('2026-10-06 00:30', $kickoff->format('Y-m-d H:i'));
        $this->assertTrue($kickoff->isToday());
        $this->assertFalse(display_instant('2026-10-05T20:30:00Z')->isToday());

        [$start, $end] = display_span_utc();
        $this->assertSame('2026-10-05 21:00:00', $start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 20:59:59', $end->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($kickoff->utc()->betweenIncluded($start, $end));
    }

    public function test_clock_stays_istanbul_in_other_locales(): void
    {
        config(['app.display_timezone' => 'Europe/Istanbul']);

        foreach (['tr', 'en', 'de', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('20:00', display_clock('2026-10-05T17:00:00Z'));
        }
    }
}
