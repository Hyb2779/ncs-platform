<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
        });
        DB::table('platform_settings')->insert(['key' => 'credit_fee_rate', 'value' => '12.00']);

        Schema::create('credit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'currency', 'created_at']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_update
BEFORE UPDATE ON credit_issues
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'credit_issues are immutable';
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_delete
BEFORE DELETE ON credit_issues
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'credit_issues are immutable';
END
SQL);
        }
        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_update
BEFORE UPDATE ON credit_issues
BEGIN
    SELECT RAISE(ABORT, 'credit_issues are immutable');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_delete
BEFORE DELETE ON credit_issues
BEGIN
    SELECT RAISE(ABORT, 'credit_issues are immutable');
END
SQL);
        }

        $ownerIds = DB::table('users')->where('role', 'owner')->pluck('id');
        if ($ownerIds->isNotEmpty()) {
            DB::table('wallets')->whereIn('user_id', $ownerIds)->update(['allow_negative' => true]);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS credit_issues_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS credit_issues_no_delete');
        }
        Schema::dropIfExists('credit_issues');
        Schema::dropIfExists('platform_settings');
    }
};
