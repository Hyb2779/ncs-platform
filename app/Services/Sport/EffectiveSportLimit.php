<?php

namespace App\Services\Sport;

class EffectiveSportLimit
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    public static function open(): self
    {
        $values = ['cash_out_enabled' => true];
        foreach ([...SportLimitFields::MIN, ...SportLimitFields::MAX] as $field) {
            $values[$field] = null;
        }

        return new self($values);
    }

    public function get(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    public function __get(string $field): mixed
    {
        return $this->get($field);
    }
}
