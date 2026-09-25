<?php

namespace App\Support;

class SecretMask
{
    public static function mask(string $value): string
    {
        foreach (self::secrets() as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, '***', $value);
            }
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function secrets(): array
    {
        $values = [
            (string) config('casino.goldpalace.api_token'),
            (string) config('casino.goldpalace.callback_token'),
            (string) config('casino.goldpalace.agent_code'),
            (string) config('casino.onegamex.secret_key'),
            (string) config('casino.onegamex.token_name'),
            (string) config('casino.demo_secret'),
        ];

        return array_values(array_filter($values, fn (string $secret) => strlen($secret) >= 6));
    }
}
