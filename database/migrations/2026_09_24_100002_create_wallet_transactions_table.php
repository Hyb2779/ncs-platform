<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->foreignId('user_id')->constrained('users');
            $table->enum('type', ['mint', 'transfer_in', 'transfer_out', 'bet', 'win', 'refund', 'bonus', 'adjustment']);
            $table->enum('product', ['sport', 'slot', 'live_casino', 'transfer', 'bonus', 'adjustment']);
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('idempotency_key')->unique();
            $table->string('reference')->nullable();
            $table->foreignId('counterparty_user_id')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('type');
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_update
BEFORE UPDATE ON wallet_transactions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transactions are immutable';
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_delete
BEFORE DELETE ON wallet_transactions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transactions are immutable';
END
SQL);
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_update
BEFORE UPDATE ON wallet_transactions
BEGIN
    SELECT RAISE(ABORT, 'wallet_transactions are immutable');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_delete
BEFORE DELETE ON wallet_transactions
BEGIN
    SELECT RAISE(ABORT, 'wallet_transactions are immutable');
END
SQL);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_no_delete');
        }

        Schema::dropIfExists('wallet_transactions');
    }
};
