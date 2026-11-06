<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StrongAccountPassword implements ValidationRule
{
    public function __construct(private readonly string $accountName) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = (string) $value;
        if (strlen($password) < 8) {
            $fail('The password must be at least 8 characters.');
        }
        if (! preg_match('/[A-Za-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            $fail('The password must contain at least one letter and one number.');
        }
        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            $fail('The password must contain at least one special character.');
        }

        $normalizedPassword = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $password));
        $nameParts = preg_split('/[^a-z0-9]+/i', $this->accountName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalizedName = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $this->accountName));
        $containsName = strlen($normalizedName) >= 3 && str_contains($normalizedPassword, $normalizedName);
        $containsNamePart = collect($nameParts)->contains(fn (string $part): bool => strlen($part) >= 4 && str_contains($normalizedPassword, strtolower($part)));
        if ($containsName || $containsNamePart) {
            $fail('The password must not contain your display name.');
        }
    }
}
