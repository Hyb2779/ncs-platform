<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->boolean('allow_negative')->default(false)->after('balance');
        });

        $ownerIds = DB::table('users')->where('role', UserRole::Owner->value)->pluck('id');

        if ($ownerIds->isNotEmpty()) {
            DB::table('wallets')->whereIn('user_id', $ownerIds)->update(['allow_negative' => true]);
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT wallets_balance_non_negative');
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0 OR allow_negative = 1)');
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS wallets_balance_non_negative');
            DB::statement('DROP TRIGGER IF EXISTS wallets_balance_non_negative_insert');
            DB::statement("CREATE TRIGGER wallets_balance_non_negative BEFORE UPDATE ON wallets BEGIN SELECT RAISE(ABORT, 'negative balance') WHERE NEW.balance < 0 AND NEW.allow_negative = 0; END");
            DB::statement("CREATE TRIGGER wallets_balance_non_negative_insert BEFORE INSERT ON wallets BEGIN SELECT RAISE(ABORT, 'negative balance') WHERE NEW.balance < 0 AND NEW.allow_negative = 0; END");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT wallets_balance_non_negative');
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS wallets_balance_non_negative');
            DB::statement('DROP TRIGGER IF EXISTS wallets_balance_non_negative_insert');
            DB::statement("CREATE TRIGGER wallets_balance_non_negative BEFORE UPDATE ON wallets BEGIN SELECT RAISE(ABORT, 'negative balance') WHERE NEW.balance < 0; END");
            DB::statement("CREATE TRIGGER wallets_balance_non_negative_insert BEFORE INSERT ON wallets BEGIN SELECT RAISE(ABORT, 'negative balance') WHERE NEW.balance < 0; END");
        }

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('allow_negative');
        });
    }
};
