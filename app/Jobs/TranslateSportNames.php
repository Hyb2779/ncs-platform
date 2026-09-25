<?php

namespace App\Jobs;

use App\Services\Sport\SportTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranslateSportNames implements ShouldQueue
{
    use Queueable;

    public function handle(SportTranslator $translator): void
    {
        $translator->translate();
    }
}
