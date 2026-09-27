<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('last_sequence')->default(0);
        });
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('sequence')->nullable();
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && DB::table('wallet_transactions')->exists()) {
            // Number existing rows in their historical order. The immutability trigger is lifted only for this backfill.
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_no_update');
            DB::statement('UPDATE wallet_transactions t JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY wallet_id ORDER BY created_at, id) AS rn FROM wallet_transactions) x ON x.id = t.id SET t.sequence = x.rn');
            DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_update
BEFORE UPDATE ON wallet_transactions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transactions are immutable';
END
SQL);
            DB::statement('UPDATE wallets w SET w.last_sequence = (SELECT COALESCE(MAX(t.sequence), 0) FROM wallet_transactions t WHERE t.wallet_id = w.id)');
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unique(['wallet_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['wallet_id', 'sequence']);
            $table->dropColumn('sequence');
        });
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('last_sequence');
        });
    }
};
