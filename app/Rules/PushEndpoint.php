<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PushEndpoint implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP)
            || ! $this->hostIsAllowed($host)
        ) {
            $fail('Der Push-Endpunkt ist ungueltig oder gehoert zu keinem bekannten Push-Dienst.');
        }
    }

    protected function hostIsAllowed(string $host): bool
    {
        foreach (config('webpush.allowed_endpoint_hosts', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));

            if ($pattern === $host) {
                return true;
            }

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);

                if (str_ends_with($host, $suffix) && $host !== substr($suffix, 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
