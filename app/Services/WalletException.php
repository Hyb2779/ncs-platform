<?php

namespace App\Services;

use RuntimeException;

class WalletException extends RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replace = [],
    ) {
        parent::__construct($translationKey);
    }
}
