<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * İkinci owner (Volkan) ve onun altındaki hesapları sayar.
 * --dry-run yalnızca sayar. Gerçek silme --force ister.
 * Cüzdan ve kredi tetikleyicileri MySQL'de DDL olduğu için transaction dışında kalkar ve finally ile geri kurulur.
 */
class PrelaunchCleanupCommand extends Command
{
    protected $signature = 'wegas:prelaunch-cleanup {--dry-run : Silmeden tablo tablo say} {--force : Gerçekten sil}';

    protected $description = 'İkinci owner ağacını sayar; silme yalnız --force ile';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('wegas:prelaunch-cleanup yalnızca APP_ENV=local iken çalışır.');

            return self::FAILURE;
        }

        if ($this->option('force') && $this->option('dry-run')) {
            $this->error('--force ve --dry-run birlikte kullanılamaz.');

            return self::FAILURE;
        }

        $subs = User::withTrashed()->where('role', UserRole::Owner)->whereNotNull('parent_id')->orderBy('id')->get();
        if ($subs->isEmpty()) {
            $this->info('İkinci owner yok. Silinecek hesap yok.');

            return self::SUCCESS;
        }

        $ids = [];
        foreach ($subs as $sub) {
            $tree = User::withTrashed()->where('path', 'like', $sub->path.'%')->pluck('id')->all();
            $ids = array_merge($ids, $tree);
            $this->line($sub->username.' #'.$sub->id.' alt hesap: '.count($tree));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $rootIds = User::query()->where('role', UserRole::Owner)->whereNull('parent_id')->pluck('id')->all();
        if (array_intersect($ids, $rootIds) !== []) {
            $this->error('Kök owner silme listesine girdi. Duruldu.');

            return self::FAILURE;
        }

        $couponIds = $this->idsIn('coupons', 'user_id', $ids);
        $tipoIds = $this->idsIn('tipo_coupons', 'user_id', $ids);
        $counts = [
            'users' => count($ids),
            'coupon_selections' => $this->countWhereIn('coupon_selections', 'coupon_id', $couponIds),
            'coupons' => count($couponIds),
            'coupon_placements' => $this->countWhereIn('coupon_placements', 'user_id', $ids),
            'tipo_selections' => $this->countWhereIn('tipo_selections', 'tipo_coupon_id', $tipoIds),
            'tipo_coupons' => count($tipoIds),
            'wallet_transactions' => $this->countWhereIn('wallet_transactions', 'user_id', $ids),
            'wallets' => $this->countWhereIn('wallets', 'user_id', $ids),
            'game_rounds' => $this->countWhereIn('game_rounds', 'user_id', $ids),
            'game_sessions' => $this->countWhereIn('game_sessions', 'user_id', $ids),
            'casino_provider_users' => $this->countWhereIn('casino_provider_users', 'user_id', $ids),
            'casino_favorites' => $this->countWhereIn('casino_favorites', 'user_id', $ids),
            'daily_stats' => $this->countWhereIn('daily_stats', 'user_id', $ids),
            'sport_warnings' => $this->countWhereIn('sport_warnings', 'user_id', $ids),
            'sport_limits' => $this->countWhereIn('sport_limits', 'user_id', $ids),
            'credit_issues' => Schema::hasTable('credit_issues') ? $this->countWhereIn('credit_issues', 'user_id', $ids) : 0,
            'credit_fee_payments' => $this->countWhereIn('credit_fee_payments', 'sub_owner_id', $ids),
            'activity_logs' => $this->logCount($ids),
            'sessions' => $this->countWhereIn('sessions', 'user_id', $ids),
        ];

        $this->newLine();
        $this->table(['tablo', 'silinecek'], collect($counts)->map(fn ($n, $table) => [$table, $n])->values()->all());
        $this->line('Korunan: kök owner, onun süperadmin ağacı, oyun kataloğu, slaytlar, spor ayarları, çeviriler, genel Oyun Yönetimi (game_blocks), sağlayıcı tarafındaki geçmiş.');

        if (! $this->option('force')) {
            $this->warn('Dry-run. Kayıt silinmedi.');

            return self::SUCCESS;
        }

        $outside = User::withTrashed()
            ->where(function ($query) use ($ids): void {
                $query->whereIn('parent_id', $ids)->orWhereIn('superadmin_id', $ids);
            })
            ->whereNotIn('id', $ids)
            ->count();
        if ($outside > 0) {
            $this->error('Silinecek ağacın dışında ona bağlı hesap var. Duruldu.');

            return self::FAILURE;
        }

        $rootId = (int) User::query()->where('role', UserRole::Owner)->whereNull('parent_id')->value('id');
        $this->dropLedgerTriggers();
        try {
            DB::transaction(function () use ($ids, $couponIds, $tipoIds, $rootId): void {
                $this->purge($ids, $couponIds, $tipoIds, $rootId);
            });
        } finally {
            $this->restoreLedgerTriggers();
        }

        $this->info('Silindi. Kalan ikinci owner: '.User::withTrashed()->where('role', UserRole::Owner)->whereNotNull('parent_id')->count());

        return self::SUCCESS;
    }

    /** @param  list<int>  $ids
     * @param  list<int>  $couponIds
     * @param  list<int>  $tipoIds
     */
    private function purge(array $ids, array $couponIds, array $tipoIds, int $rootId): void
    {
        $this->deleteWhereIn('coupon_selections', 'coupon_id', $couponIds);
        $this->nullWhereIn('coupons', 'cancelled_by', $ids);
        $this->nullWhereIn('coupons', 'superadmin_id', $ids);
        $this->deleteWhereIn('coupons', 'user_id', $ids);
        $this->nullWhereIn('coupon_placements', 'superadmin_id', $ids);
        $this->deleteWhereIn('coupon_placements', 'user_id', $ids);
        $this->deleteWhereIn('tipo_selections', 'tipo_coupon_id', $tipoIds);
        $this->deleteWhereIn('tipo_coupons', 'user_id', $ids);
        $this->nullWhereIn('wallet_transactions', 'counterparty_user_id', $ids);
        $this->nullWhereIn('wallet_transactions', 'created_by', $ids);
        $this->deleteWhereIn('wallet_transactions', 'user_id', $ids);
        $this->deleteWhereIn('wallets', 'user_id', $ids);
        $this->deleteWhereIn('game_rounds', 'user_id', $ids);
        $this->deleteWhereIn('game_sessions', 'user_id', $ids);
        $this->deleteWhereIn('casino_provider_users', 'user_id', $ids);
        $this->deleteWhereIn('casino_favorites', 'user_id', $ids);
        $this->deleteWhereIn('daily_stats', 'user_id', $ids);
        $this->deleteWhereIn('sport_warnings', 'user_id', $ids);
        $this->deleteWhereIn('sport_limits', 'user_id', $ids);
        $this->deleteWhereIn('sport_margins', 'superadmin_id', $ids);
        $this->deleteWhereIn('credit_issues', 'user_id', $ids);
        $this->deleteWhereIn('credit_fee_payments', 'sub_owner_id', $ids);
        if (Schema::hasTable('credit_fee_payments') && Schema::hasColumn('credit_fee_payments', 'created_by')) {
            DB::table('credit_fee_payments')->whereIn('created_by', $ids)->update(['created_by' => $rootId]);
        }
        if (Schema::hasTable('game_blocks')) {
            DB::table('game_blocks')->whereIn('superadmin_id', $ids)->delete();
            DB::table('game_blocks')->whereIn('created_by', $ids)->update(['created_by' => $rootId]);
        }
        if (Schema::hasTable('activity_logs')) {
            DB::table('activity_logs')
                ->where(function ($query) use ($ids): void {
                    $query->whereIn('actor_id', $ids)
                        ->orWhere(function ($inner) use ($ids): void {
                            $inner->where('target_type', User::class)->whereIn('target_id', $ids);
                        });
                })
                ->delete();
        }
        $this->deleteWhereIn('sessions', 'user_id', $ids);
        if (Schema::hasTable('password_reset_tokens')) {
            $names = User::withTrashed()->whereIn('id', $ids)->pluck('username');
            DB::table('password_reset_tokens')->whereIn('username', $names)->delete();
        }

        $this->detachUserSelfReferences($ids);

        $ordered = User::withTrashed()->whereIn('id', $ids)->orderByDesc('depth')->orderByDesc('id')->pluck('id');
        foreach ($ordered as $id) {
            DB::table('users')->where('id', $id)->delete();
        }
    }

    /**
     * users.parent_id ve users.superadmin_id (süperadminde kendi id'si) silmeyi RESTRICT ile durdurur.
     * Yalnızca silinecek satırlar güncellenir.
     *
     * @param  list<int>  $ids
     */
    private function detachUserSelfReferences(array $ids): void
    {
        $columns = $this->userSelfReferenceColumns();
        if ($ids === [] || $columns === []) {
            return;
        }

        DB::table('users')->whereIn('id', $ids)->update(array_fill_keys($columns, null));
    }

    /** @return list<string> */
    private function userSelfReferenceColumns(): array
    {
        $columns = [];
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $columns = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'users')
                ->where('REFERENCED_TABLE_NAME', 'users')
                ->pluck('COLUMN_NAME')
                ->all();
        } elseif ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA foreign_key_list(users)') as $fk) {
                $row = (array) $fk;
                if (($row['table'] ?? null) === 'users' && isset($row['from'])) {
                    $columns[] = (string) $row['from'];
                }
            }
        }

        foreach (['parent_id', 'superadmin_id', 'created_by', 'owner_id'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique(array_map('strval', $columns)));
    }

    private function dropLedgerTriggers(): void
    {
        foreach (['wallet_transactions_no_update', 'wallet_transactions_no_delete', 'credit_issues_no_update', 'credit_issues_no_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }

    private function restoreLedgerTriggers(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_update BEFORE UPDATE ON wallet_transactions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transactions are immutable';
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_delete BEFORE DELETE ON wallet_transactions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'wallet_transactions are immutable';
END
SQL);
            if (Schema::hasTable('credit_issues')) {
                DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_update BEFORE UPDATE ON credit_issues FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'credit_issues are immutable';
END
SQL);
                DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_delete BEFORE DELETE ON credit_issues FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'credit_issues are immutable';
END
SQL);
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_update BEFORE UPDATE ON wallet_transactions
BEGIN
    SELECT RAISE(ABORT, 'wallet_transactions are immutable');
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER wallet_transactions_no_delete BEFORE DELETE ON wallet_transactions
BEGIN
    SELECT RAISE(ABORT, 'wallet_transactions are immutable');
END
SQL);
        if (Schema::hasTable('credit_issues')) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_update BEFORE UPDATE ON credit_issues
BEGIN
    SELECT RAISE(ABORT, 'credit_issues are immutable');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_issues_no_delete BEFORE DELETE ON credit_issues
BEGIN
    SELECT RAISE(ABORT, 'credit_issues are immutable');
END
SQL);
        }
    }

    /** @param  list<int>  $ids */
    private function deleteWhereIn(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        DB::table($table)->whereIn($column, $ids)->delete();
    }

    /** @param  list<int>  $ids */
    private function nullWhereIn(string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        DB::table($table)->whereIn($column, $ids)->update([$column => null]);
    }

    /** @param  list<int>  $ids */
    private function idsIn(string $table, string $column, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->whereIn($column, $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param  list<int>  $ids */
    private function countWhereIn(string $table, string $column, array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->whereIn($column, $ids)->count();
    }

    /** @param  list<int>  $ids */
    private function logCount(array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable('activity_logs')) {
            return 0;
        }

        return DB::table('activity_logs')
            ->where(function ($query) use ($ids): void {
                $query->whereIn('actor_id', $ids)
                    ->orWhere(function ($inner) use ($ids): void {
                        $inner->where('target_type', User::class)->whereIn('target_id', $ids);
                    });
            })
            ->count();
    }
}
