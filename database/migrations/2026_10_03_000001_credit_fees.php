<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alt owner'ın süperadminlerine verdiği kredi üzerinden kök owner'a ödeyeceği oran (%). NULL = ücret yok.
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('credit_fee_rate', 5, 2)->nullable()->after('commission_rate');
        });

        // Kök owner'ın alt owner'dan yaptığı tahsilatlar (elle girilir).
        Schema::create('credit_fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_owner_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['sub_owner_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_fee_payments');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credit_fee_rate');
        });
    }
};
