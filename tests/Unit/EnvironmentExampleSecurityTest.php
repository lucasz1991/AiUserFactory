<?php

namespace Tests\Unit;

use Tests\TestCase;

class EnvironmentExampleSecurityTest extends TestCase
{
    public function test_versioned_environment_example_contains_no_credential_values(): void
    {
        $source = (string) file_get_contents(base_path('.env.example'));
        $credentialKeys = [
            'APP_KEY',
            'GITHUB_TOKEN',
            'SPEECH_SERVICE_TOKEN',
            'SPEECH_SERVICE_TOKEN_FILE',
            'DB_PASSWORD',
            'WEBAIDETECTIVE_BASE_API_PASSWORD',
            'WEBAIDETECTIVE_BASE_APP_KEY',
            'REDIS_PASSWORD',
            'MAIL_PASSWORD',
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
            'PUSHER_APP_SECRET',
            'REVERB_APP_SECRET',
        ];

        foreach ($credentialKeys as $key) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($key, '/').'=$/m',
                $source,
                $key.' must remain an empty placeholder in .env.example.',
            );
        }

        $this->assertStringContainsString('SPEECH_SERVICE_URL=http://127.0.0.1:8092', $source);
        $this->assertStringContainsString('SPEECH_SERVICE_CLIENT_ID=followflow', $source);
    }
}
