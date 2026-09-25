<?php

use App\Enums\WalletProduct;
use App\Enums\WalletTransactionType;
use App\Models\Wallet;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Contracts\Console\Kernel;

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

Illuminate\Support\Facades\DB::purge('mysql');
Illuminate\Support\Facades\DB::reconnect('mysql');

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    $kernel->call('migrate:fresh', ['--force' => true]);

    $owner = App\Models\User::query()->create([
        'username' => 'conc-owner',
        'password' => 'password',
        'role' => App\Enums\UserRole::Owner,
        'parent_id' => null,
        'path' => '/',
        'depth' => 0,
        'superadmin_id' => null,
        'language' => App\Enums\Language::Tr,
        'currency' => App\Enums\Currency::Try,
        'timezone' => 'UTC',
        'commission_rate' => 0,
        'status' => App\Enums\UserStatus::Active,
    ]);
    $owner->path = '/'.$owner->id.'/';
    $owner->save();

    $superadmin = app(App\Services\HierarchyService::class)->create($owner, [
        'username' => 'conc-sa',
        'password' => 'password',
        'commission_rate' => 0,
        'user_limit' => null,
        'note' => null,
        'language' => 'tr',
        'currency' => 'TRY',
        'timezone' => 'UTC',
    ]);
    $wallet = $superadmin->wallets()->where('currency', 'TRY')->firstOrFail();
    app(WalletService::class)->credit(
        $wallet,
        '50.00',
        WalletTransactionType::Bonus,
        WalletProduct::Adjustment,
        'conc-seed',
    );
    echo $wallet->id;
    exit(0);
}

if ($mode === 'debit') {
    $wallet = Wallet::query()->findOrFail((int) $argv[2]);

    try {
        app(WalletService::class)->debit(
            $wallet,
            '10.00',
            WalletTransactionType::Adjustment,
            WalletProduct::Adjustment,
            $argv[3],
        );
        echo "RESULT 0\n";
        exit(0);
    } catch (WalletException) {
        echo "RESULT 2\n";
        exit(2);
    }
}

fwrite(STDERR, "unknown mode\n");
exit(1);
