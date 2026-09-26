<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SportCountry;
use App\Models\SportFixture;
use App\Models\SportLeague;
use App\Models\SportLimit;
use App\Models\SportMarket;
use App\Models\SportOdd;
use App\Models\SportTeam;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Sport\CouponException;
use App\Services\Sport\CouponPlacer;
use App\Services\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$username = getenv('DB_USERNAME');

if (! is_string($username) || $username === '' || $username === 'root') {
    fwrite(STDERR, "DB_USERNAME must be the application database user\n");
    exit(1);
}

foreach ([
    'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'wallet_conc_test',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

putenv('DB_URL');
unset($_ENV['DB_URL'], $_SERVER['DB_URL']);

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => env('DB_HOST', '127.0.0.1'),
    'database.connections.mysql.port' => env('DB_PORT', '3306'),
    'database.connections.mysql.database' => 'wallet_conc_test',
    'database.connections.mysql.username' => $username,
    'database.connections.mysql.password' => env('DB_PASSWORD', ''),
    'database.connections.mysql.unix_socket' => '',
]);

DB::purge('mysql');
DB::reconnect('mysql');

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    $kernel->call('migrate:fresh', ['--force' => true]);
    $owner = User::query()->create([
        'username' => 'limit-owner',
        'password' => 'password',
        'role' => UserRole::Owner,
        'parent_id' => null,
        'path' => '/',
        'depth' => 0,
        'superadmin_id' => null,
        'language' => 'tr',
        'currency' => 'TRY',
        'timezone' => 'UTC',
        'commission_rate' => 0,
        'status' => UserStatus::Active,
    ]);
    $owner->path = '/'.$owner->id.'/';
    $owner->save();
    $fields = [
        'password' => 'password', 'commission_rate' => 0, 'user_limit' => null, 'note' => null,
        'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'UTC',
    ];
    $superadmin = app(HierarchyService::class)->create($owner, ['username' => 'limit-sa'] + $fields);
    $bayi = app(HierarchyService::class)->create($superadmin, ['username' => 'limit-bayi'] + $fields);
    $member = app(HierarchyService::class)->create($bayi, ['username' => 'limit-uye'] + $fields);
    app(WalletService::class)->transfer($owner, $member, '100.00', 'limit-fund', $owner);
    SportLimit::query()->whereNull('user_id')->where('currency', 'TRY')->update(['daily_max' => '15.00']);

    $country = SportCountry::query()->firstOrCreate(['name' => 'England'], ['code' => 'EN']);
    $league = SportLeague::query()->create([
        'api_id' => 9001, 'country_id' => $country->id, 'name' => 'Limit League', 'season' => 2026, 'is_active' => true,
    ]);
    $home = SportTeam::query()->create(['api_id' => 9002, 'name' => 'Limit Home']);
    $away = SportTeam::query()->create(['api_id' => 9003, 'name' => 'Limit Away']);
    $fixture = SportFixture::query()->create([
        'api_id' => 9004,
        'league_id' => $league->id,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'starts_at' => now()->addDay(),
        'status' => 'NS',
        'bulletin_code' => 9004,
    ]);
    $market = SportMarket::query()->where('code', '1X2')->firstOrFail();
    $odd = SportOdd::query()->create([
        'fixture_id' => $fixture->id,
        'market_id' => $market->id,
        'outcome' => 'home',
        'raw_odd' => '1.50',
        'shown_odd' => '1.50',
    ]);
    echo $member->id.' '.$odd->id;
    exit(0);
}

if ($mode === 'place') {
    $member = User::query()->findOrFail((int) $argv[2]);
    $odd = SportOdd::query()->findOrFail((int) $argv[3]);

    try {
        app(CouponPlacer::class)->place($member, [
            'selections' => [[
                'odd_id' => $odd->id,
                'fixture_id' => $odd->fixture_id,
                'outcome' => 'home',
                'shown' => '1.50',
            ]],
            'stake' => '10.00',
            'accept' => true,
            'mode' => 'single',
        ], $argv[4], '127.0.0.1', 'limit-worker');
        echo "RESULT 0\n";
        exit(0);
    } catch (CouponException) {
        echo "RESULT 2\n";
        exit(2);
    }
}

fwrite(STDERR, "unknown mode\n");
exit(1);
