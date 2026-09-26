<?php

namespace App\Console\Commands;

use App\Services\Sport\SettleCheck;
use Illuminate\Console\Command;

class SportSettleCheck extends Command
{
    protected $signature = 'sport:settle-check';

    protected $description = 'Fetch finished scores and settle due sport coupons';

    public function handle(SettleCheck $check): int
    {
        $this->info('settled='.$check->run());

        return self::SUCCESS;
    }
}
