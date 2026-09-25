<?php

namespace App\Services\Casino;

class ProviderRegistry
{
    /** @param  array<string, CasinoProvider>  $providers */
    public function __construct(private readonly array $providers) {}

    public function get(string $code): ?CasinoProvider
    {
        return $this->providers[$code] ?? null;
    }

    /**
     * @return array<string, CasinoProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
