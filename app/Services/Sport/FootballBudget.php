<?php

namespace App\Services\Sport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FootballBudget
{
    public function allows(bool $critical): bool
    {
        if ($critical || $this->used() < (int) config('football.soft_cap')) {
            return true;
        }

        Log::warning('football.budget.soft_cap', ['used' => $this->used(), 'remaining' => $this->remaining()]);

        return false;
    }

    public function record(int $remaining): void
    {
        Cache::increment($this->key());
        Cache::put('football:remaining', $remaining, now()->addDays(2));
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    public function remaining(): ?int
    {
        $value = Cache::get('football:remaining');

        return $value === null ? null : (int) $value;
    }

    private function key(): string
    {
        return 'football:requests:'.now()->utc()->toDateString();
    }
}
