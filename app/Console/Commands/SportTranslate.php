<?php

namespace App\Console\Commands;

use App\Services\Sport\SportTranslator;
use Illuminate\Console\Command;

class SportTranslate extends Command
{
    protected $signature = 'sport:translate';

    protected $description = 'Translate football names that are still waiting';

    public function handle(SportTranslator $translator): int
    {
        $pending = $translator->pendingCount();
        $key = config('services.anthropic.key');

        if (! is_string($key) || $key === '') {
            $this->info(__('sport.translate.waiting', ['count' => $pending]));

            return self::SUCCESS;
        }

        $requests = $translator->translate();
        $this->info(__('sport.translate.done', [
            'requests' => $requests,
            'waiting' => $translator->pendingCount(),
        ]));

        return self::SUCCESS;
    }
}
