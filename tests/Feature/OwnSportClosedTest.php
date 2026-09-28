<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnSportClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_sportsbook_is_closed_but_results_and_wegas_sport_stay(): void
    {
        config(['sport.own_book_enabled' => false]);

        foreach (['/sport', '/sport/live', '/sport/live/data', '/account/coupons', '/account/coupons/live'] as $url) {
            $this->assertSame(404, $this->get($url)->getStatusCode(), 'GET '.$url);
        }
        foreach (['/sport/combo', '/sport/coupon', '/sport/coupon/place', '/sport/coupon/clear'] as $url) {
            $this->assertSame(404, $this->post($url)->getStatusCode(), 'POST '.$url);
        }

        $this->get('/sport/results')->assertOk();
        $this->assertNotSame(404, $this->get('/wegas-spor')->getStatusCode());
    }
}
