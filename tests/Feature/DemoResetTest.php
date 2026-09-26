<?php

namespace Tests\Feature;

use Tests\TestCase;

class DemoResetTest extends TestCase
{
    public function test_demo_reset_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:reset')
            ->expectsOutput(__('wallet.errors.demo_reset_forbidden'))
            ->assertFailed();
    }
}
