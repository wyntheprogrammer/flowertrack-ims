<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Bcrypt accepts at most 72 bytes; reject truncation and null bytes explicitly. */
class SafeAccountPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
            $fail('The :attribute must be at most 72 UTF-8 bytes and contain no null characters.');
        }
    }
}
