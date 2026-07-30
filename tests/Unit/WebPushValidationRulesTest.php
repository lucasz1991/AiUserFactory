<?php

namespace Tests\Unit;

use App\Rules\PushEndpoint;
use App\Rules\WebPushKey;
use Tests\TestCase;

/**
 * Spur W. Die beiden Regeln sind die Aussengrenze: ueber `store` schickt der
 * Browser fremdbestimmte Werte, und der Server stellt spaeter genau dorthin
 * zu. Ein zu lascher Endpunkt waere ein SSRF-Ziel.
 */
class WebPushValidationRulesTest extends TestCase
{
    private function endpointFails(string $value): bool
    {
        $failed = false;

        (new PushEndpoint)->validate('endpoint', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    private function keyFails(string $type, string $value): bool
    {
        $failed = false;

        (new WebPushKey($type))->validate('key', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    public function test_known_push_services_are_accepted(): void
    {
        $this->assertFalse($this->endpointFails('https://fcm.googleapis.com/fcm/send/abc123'));
        $this->assertFalse($this->endpointFails('https://updates.push.services.mozilla.com/wpush/v2/abc'));
        $this->assertFalse($this->endpointFails('https://web.push.apple.com/QAbc'));
    }

    public function test_everything_outside_the_allow_list_is_rejected(): void
    {
        $this->assertTrue($this->endpointFails('http://fcm.googleapis.com/fcm/send/abc'), 'http muss abgelehnt werden');
        $this->assertTrue($this->endpointFails('https://evil.example.com/collect'), 'fremder Host muss abgelehnt werden');
        $this->assertTrue($this->endpointFails('https://127.0.0.1/fcm/send/abc'), 'IP-Host muss abgelehnt werden');
        $this->assertTrue($this->endpointFails('https://169.254.169.254/latest/meta-data'), 'Metadaten-Endpunkt muss abgelehnt werden');
    }

    /**
     * `*.push.services.mozilla.com` darf nicht auch die nackte Basisdomain
     * freigeben — sonst wuerde ein Muster mehr erlauben als geschrieben.
     */
    public function test_a_wildcard_pattern_does_not_match_its_own_base_domain(): void
    {
        $this->assertTrue($this->endpointFails('https://push.services.mozilla.com/wpush/v2/abc'));
    }

    public function test_a_real_p256_public_key_is_accepted_and_a_broken_one_is_not(): void
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($key);
        $point = "\x04".str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $encoded = rtrim(strtr(base64_encode($point), '+/', '-_'), '=');

        $this->assertFalse($this->keyFails('p256dh', $encoded));

        // Richtige Laenge, aber kein Punkt auf der Kurve.
        $bogus = rtrim(strtr(base64_encode("\x04".str_repeat("\x01", 64)), '+/', '-_'), '=');

        $this->assertTrue($this->keyFails('p256dh', $bogus));
        $this->assertTrue($this->keyFails('p256dh', 'nicht base64url!!'));
    }

    public function test_the_auth_secret_must_be_exactly_sixteen_bytes(): void
    {
        $valid = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $tooShort = rtrim(strtr(base64_encode(random_bytes(8)), '+/', '-_'), '=');

        $this->assertFalse($this->keyFails('auth', $valid));
        $this->assertTrue($this->keyFails('auth', $tooShort));
    }
}
