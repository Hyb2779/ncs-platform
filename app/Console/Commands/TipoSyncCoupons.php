<?php

namespace App\Console\Commands;

use App\Models\WalletTransaction;
use App\Services\Sport\TipoCouponSync;
use Illuminate\Console\Command;

class TipoSyncCoupons extends Command
{
    protected $signature = 'tipo:sync-coupons';

    protected $description = 'Wegas Spor (Tipo) kuponlarini NCS koprusunden ceker (tipo_coupons).';

    public function handle(TipoCouponSync $sync): int
    {
        $userIds = WalletTransaction::query()->where('reference', 'like', 'tipo:%')
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $count = $sync->syncUsers($userIds);
        if ($count === null) {
            $this->error('Kopru hatasi; senkron yarida kaldi.');

            return self::FAILURE;
        }

        $this->info($count.' kupon senkronlandi.');

        return self::SUCCESS;
    }
}
