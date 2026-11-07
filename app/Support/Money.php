<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const MAX_CENTS = 999999999999;

    /** Convert a nonnegative decimal amount to bounded cents without floating-point math. */
    public static function parse(string $amount): int
    {
        $amount = trim($amount);
        if (! preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/', $amount, $matches)) {
            throw new InvalidArgumentException('Enter a nonnegative amount with at most two decimal places.');
        }
        if (strlen($matches[1]) > 10) {
            throw new InvalidArgumentException('The amount exceeds the supported limit.');
        }
        $cents = ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('The amount exceeds the supported limit.');
        }

        return $cents;
    }

    /** Render integer cents as an exact decimal amount. */
    public static function format(int $cents): string
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('Invalid monetary value.');
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
