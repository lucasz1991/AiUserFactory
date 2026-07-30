<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class WebPushKey implements ValidationRule
{
    public function __construct(
        private readonly string $type,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            $fail('Der uebermittelte Push-Schluessel ist ungueltig.');

            return;
        }

        $unpadded = rtrim($value, '=');
        $padding = (4 - strlen($unpadded) % 4) % 4;
        $decoded = base64_decode(
            strtr($unpadded, '-_', '+/').str_repeat('=', $padding),
            true,
        );

        $valid = match ($this->type) {
            'p256dh' => is_string($decoded)
                && strlen($decoded) === 65
                && ord($decoded[0]) === 4
                && $this->isValidP256Point($decoded),
            'auth' => is_string($decoded) && strlen($decoded) === 16,
            default => false,
        };

        if (! $valid) {
            $fail('Der uebermittelte Push-Schluessel ist ungueltig.');
        }
    }

    protected function isValidP256Point(string $rawKey): bool
    {
        if (! function_exists('openssl_pkey_get_public')) {
            return false;
        }

        // SubjectPublicKeyInfo prefix for an uncompressed prime256v1 point.
        $derPrefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');

        if ($derPrefix === false) {
            return false;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($derPrefix.$rawKey), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
        $publicKey = @openssl_pkey_get_public($pem);

        if ($publicKey === false) {
            return false;
        }

        $details = openssl_pkey_get_details($publicKey);
        $curveName = $details['ec']['curve_name'] ?? null;

        return ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC
            && ($details['bits'] ?? null) === 256
            && in_array($curveName, ['prime256v1', 'secp256r1'], true);
    }
}
