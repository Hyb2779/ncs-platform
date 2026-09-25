<?php

namespace App\Services\Sport;

use RuntimeException;

class CouponException extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     * @param  list<array{odd_id: int, outcome: string, from: string, to: string}>  $changed
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $replace = [],
        public readonly array $changed = [],
    ) {
        parent::__construct($translationKey);
    }
}
