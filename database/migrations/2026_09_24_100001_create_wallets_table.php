<?php

use App\Enums\Currency;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('currency', ['TRY', 'USD', 'EUR']);
            $table->decimal('balance', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'currency']);
            $table->check('balance >= 0');
        });

        $now = now();

        foreach (DB::table('users')->select('id', 'role', 'currency')->orderBy('id')->get() as $user) {
            $currencies = $user->role === UserRole::Owner->value
                ? array_column(Currency::cases(), 'value')
                : [$user->currency];

            foreach ($currencies as $currency) {
                DB::table('wallets')->insert([
                    'user_id' => $user->id,
                    'currency' => $currency,
                    'balance' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
