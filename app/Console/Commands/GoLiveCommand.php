<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Canlıya çıkış temizliği: owner hariç tüm hesaplar ve oyun/defter verisi silinir.
 * Oyun kataloğu ve Fenix spor verisi korunur. Varsayılan kip sadece rapordur.
 */
class GoLiveCommand extends Command
{
    protected $signature = 'platform:go-live {--force : Gerçekten sil} {--owner-password= : (test için) yeni owner şifresi}';

    protected $description = 'Owner hariç tüm hesapları ve demo/test verisini siler (varsayılan: sadece rapor)';

    /** Tamamen boşaltılacak tablolar */
    private const WIPE = [
        'activity_logs', 'casino_favorites', 'casino_provider_users', 'coupon_selections', 'coupon_placements',
        'sport_warnings', 'coupons', 'daily_stats', 'game_rounds', 'game_sessions', 'wallet_transactions',
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
        'game_blocks', 'credit_fee_payments', // FK users RESTRICT (03.10)
    ];

    private const DEMO_API_ID = 9000000000000;

    public function handle(): int
    {
        $owners = User::query()->where('role', UserRole::Owner)->whereNull('parent_id')->get(); // kök owner; alt owner'lar diğer hesaplar gibi silinir
        if ($owners->count() !== 1) {
            $this->error('Tam olarak 1 owner olmalı, bulunan: '.$owners->count());

            return self::FAILURE;
        }
        $owner = $owners->first();

        $demoFixtures = DB::table('sport_fixtures')->where('score_source', 'manual')->where('api_id', '>=', self::DEMO_API_ID)->pluck('id');

        $plan = [];
        foreach (self::WIPE as $t) {
            if (Schema::hasTable($t)) {
                $plan[$t] = DB::table($t)->count();
            }
        }
        $plan['users (owner hariç)'] = DB::table('users')->where('id', '!=', $owner->id)->count();
        $plan['wallets (owner hariç)'] = DB::table('wallets')->where('user_id', '!=', $owner->id)->count();
        $plan['sport_limits (owner hariç)'] = DB::table('sport_limits')->where('user_id', '!=', $owner->id)->count();
        $plan['sport_margins (süperadmin/demo)'] = $this->marginQuery($owner->id, $demoFixtures)->count();
        $plan['sport_odds (demo maç)'] = DB::table('sport_odds')->whereIn('fixture_id', $demoFixtures)->count();
        $plan['sport_fixtures (demo maç)'] = $demoFixtures->count();

        $this->info("Owner: {$owner->username} (#{$owner->id}) — cüzdanları kalır, bakiye/sıra sıfırlanır.");
        $this->table(['Tablo', 'Silinecek satır'], collect($plan)->map(fn ($c, $t) => [$t, $c])->values()->all());
        $this->line('Korunanlar: casino_games, casino_providers, Fenix maçları/oranları, ülke/lig/takım/market, migrations.');

        if (! $this->option('force')) {
            $this->warn('RAPOR KİPİ: hiçbir şey silinmedi. Gerçek silme için --force.');

            return self::SUCCESS;
        }

        if ($this->ask('Onay için CANLIYA-AL yazın') !== 'CANLIYA-AL') {
            $this->error('Onay verilmedi, işlem iptal.');

            return self::FAILURE;
        }

        $password = $this->option('owner-password');
        if ($password === null) {
            $password = (string) $this->secret('Yeni owner şifresi (en az 12 karakter)');
            if ($password !== (string) $this->secret('Yeni owner şifresi (tekrar)')) {
                $this->error('Şifreler eşleşmedi, işlem iptal.');

                return self::FAILURE;
            }
        }
        if (mb_strlen((string) $password) < 12) {
            $this->error('Şifre en az 12 karakter olmalı, işlem iptal.');

            return self::FAILURE;
        }

        $sqlite = DB::getDriverName() === 'sqlite';
        $triggers = $sqlite
            ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'wallet_transactions'")
            : [];

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($triggers as $tr) {
                DB::statement('DROP TRIGGER IF EXISTS '.$tr->name);
            }
            foreach (self::WIPE as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->truncate();
                }
            }
            foreach ($triggers as $tr) {
                DB::statement($tr->sql);
            }

            $this->marginQuery($owner->id, $demoFixtures)->delete();
            DB::table('sport_odds')->whereIn('fixture_id', $demoFixtures)->delete();
            DB::table('sport_fixtures')->whereIn('id', $demoFixtures)->delete();
            DB::table('sport_limits')->where('user_id', '!=', $owner->id)->delete();
            DB::table('wallets')->where('user_id', '!=', $owner->id)->delete();
            DB::table('users')->where('id', '!=', $owner->id)->delete();

            DB::table('wallets')->where('user_id', $owner->id)
                ->update(['balance' => 0, 'settlement_overdraft_amount' => 0, 'last_sequence' => 0, 'updated_at' => now()]);

            $next = max(1000, (int) $owner->id + 1);
            if ($sqlite) {
                DB::table('sqlite_sequence')->where('name', 'users')->delete();
                DB::table('sqlite_sequence')->insert(['name' => 'users', 'seq' => $next - 1]);
            } else {
                DB::statement('ALTER TABLE users AUTO_INCREMENT = '.$next);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $owner->forceFill(['password' => Hash::make((string) $password), 'last_login_at' => null, 'last_login_ip' => null])->save();

        $this->info('Tamamlandı. Kalan kullanıcı: '.DB::table('users')->count().', yeni kullanıcılar #'.$next.'\'den başlar.');
        $this->info('Şimdi: php artisan wallet:verify');

        return self::SUCCESS;
    }

    private function marginQuery(int $ownerId, $demoFixtures)
    {
        return DB::table('sport_margins')->where(function ($q) use ($ownerId, $demoFixtures) {
            $q->where(fn ($s) => $s->whereNotNull('superadmin_id')->where('superadmin_id', '!=', $ownerId))
                ->orWhereIn('fixture_id', $demoFixtures);
        });
    }
}
