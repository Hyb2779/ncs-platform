<?php

namespace App\Support;

class Brand
{
    public function name(): string
    {
        return (string) config('app.name');
    }

    public function logo(): ?string
    {
        return null;
    }
}
