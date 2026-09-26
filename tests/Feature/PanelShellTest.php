<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Stats\StatRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PanelShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_drawer_includes_margins_and_the_bottom_bar_has_four_links(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->get(route('panel.dashboard'))
            ->assertOk()
            ->assertSee(route('panel.sport.margins'), false)
            ->assertSee('data-nav="bottom"', false)
            ->assertSee(__('panel.overview'))
            ->assertSee(__('sport.panel.status'));
    }

    public function test_a_bayi_does_not_see_margins(): void
    {
        $owner = $this->owner();
        $hierarchy = app(HierarchyService::class);
        $superadmin = $hierarchy->create($owner, $this->payload('sa', [
            'language' => 'tr',
            'currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
        ]));
        $bayi = $hierarchy->create($superadmin, $this->payload('bayi'));

        $this->actingAs($bayi)
            ->get(route('panel.dashboard'))
            ->assertOk()
            ->assertDontSee(route('panel.sport.margins'), false)
            ->assertSee(route('panel.transactions'), false);
    }

    public function test_stat_table_filter_and_chart_components_render(): void
    {
        $stat = Blade::render('<x-panel.stat label="GGR" value="10" change="-12.5" />');
        $this->assertStringContainsString('↓', $stat);
        $this->assertStringContainsString('12.5%', $stat);

        $table = Blade::render('<x-panel.table :columns="$columns" :rows="$rows" />', [
            'columns' => [
                ['key' => 'name', 'label' => 'Ad', 'priority' => 'primary'],
                ['key' => 'note', 'label' => 'Not', 'priority' => 'detail'],
            ],
            'rows' => [
                ['name' => 'kupon', 'note' => 'gizli-not'],
            ],
        ]);
        $this->assertStringContainsString('md:hidden', $table);
        $this->assertStringContainsString('gizli-not', $table);
        $this->assertStringContainsString(__('panel.table_detail'), $table);

        $empty = Blade::render('<x-panel.table :columns="$columns" :rows="$rows" />', [
            'columns' => [['key' => 'name', 'label' => 'Ad']],
            'rows' => [],
        ]);
        $this->assertStringContainsString(__('panel.empty_rows'), $empty);

        $chart = Blade::render('<x-panel.chart type="bar" :labels="$labels" :datasets="$datasets" />', [
            'labels' => ['Dün', 'Bugün'],
            'datasets' => [['label' => 'Ciro', 'data' => [-3, 4]]],
        ]);
        $this->assertStringContainsString('data-chart="{&quot;type&quot;:&quot;bar&quot;', $chart);
        $this->assertStringContainsString('-3', $chart);

        $source = file_get_contents(base_path('resources/js/panel-chart.js'));
        $this->assertIsString($source);
        $this->assertStringContainsString('beginAtZero: !negative', $source);
        $this->assertStringContainsString("locale: 'en-US'", $source);
        $this->assertStringContainsString('reverse: rtl', $source);
        $this->assertStringContainsString("type === 'doughnut' ? palette : color", $source);
    }

    public function test_adjustment_products_that_enter_payout_are_the_game_products(): void
    {
        $this->assertSame(['sport', 'slot', 'live_casino'], StatRules::PAYOUT_ADJUSTMENT_PRODUCTS);
        $this->assertSame(['transfer', 'adjustment', 'bonus'], StatRules::EXCLUDED_PRODUCTS);
    }

    private function owner(): User
    {
        $owner = User::query()->create([
            'username' => 'owner',
            'password' => 'password',
            'role' => UserRole::Owner,
            'parent_id' => null,
            'path' => '/',
            'depth' => 0,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'UTC',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        return $owner->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $username, array $extra = []): array
    {
        return array_merge([
            'username' => $username,
            'password' => 'password',
            'commission_rate' => 0,
            'user_limit' => null,
            'note' => null,
        ], $extra);
    }
}
