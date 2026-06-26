<?php

namespace App\Services\Workflows;

use App\Models\Setting;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Support\Str;

class WorkflowTemplateService
{
    public function ensureDefaults(): void
    {
        $registrationWorkflow = $this->workflow(
            slug: 'email-mailbox-registration',
            name: 'E-Mail-Postfach registrieren',
            description: 'Registriert ein Persona-Postfach und speichert danach die Webmail-Session.'
        );

        $this->step($registrationWorkflow, [
            'name' => 'E-Mail-Postfach registrieren',
            'type' => WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION,
            'position' => 10,
            'config_json' => [
                'provider_key' => 'proton',
                'allow_partial' => false,
            ],
        ]);

        $this->step($registrationWorkflow, [
            'name' => 'Webmailportal Login speichern',
            'type' => WorkflowStep::TYPE_WEBMAIL_LOGIN,
            'position' => 20,
            'config_json' => [
                'provider' => 'proton',
                'use_person_email_account' => true,
                'allow_partial' => false,
            ],
        ]);

        $webmailWorkflow = $this->workflow(
            slug: 'webmail-portal-login',
            name: 'Webmailportal Login',
            description: 'Oeffnet das konfigurierte Webmail-Portal und speichert eine wiederverwendbare Session.'
        );

        $this->step($webmailWorkflow, [
            'name' => 'Webmailportal Login speichern',
            'type' => WorkflowStep::TYPE_WEBMAIL_LOGIN,
            'position' => 10,
            'config_json' => [
                'provider' => 'proton',
                'use_person_email_account' => true,
                'allow_partial' => false,
            ],
        ]);
    }

    public function ensureMailSettings(): array
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $existing = Setting::getValue(MailAccountRegistrationRunner::SETTINGS_TYPE, MailAccountRegistrationRunner::SETTINGS_KEY);
        $settings = is_array($existing)
            ? array_replace_recursive($runner->defaultSettings(), $existing)
            : $runner->defaultSettings();

        $settings['providers'][0] = array_replace($settings['providers'][0] ?? [], [
            'key' => 'proton',
            'label' => 'Proton',
            'mode' => MailAccountRegistrationRunner::PROVIDER_MODE_PROTON_USERNAME_CHECK,
            'enabled' => true,
            'phone_required' => false,
            'registration_url' => 'https://account.proton.me/mail/signup',
            'webmail_url' => 'https://mail.proton.me',
        ]);

        $settings = $runner->saveSettings($settings);

        Setting::setValue('webmail', 'portal_login', [
            'default_provider' => 'proton',
            'providers' => [
                'proton' => [
                    'label' => 'Proton',
                    'webmail_url' => 'https://mail.proton.me',
                ],
                'gmx' => [
                    'label' => 'GMX',
                    'webmail_url' => 'https://www.gmx.net',
                ],
            ],
            'workflow_slug' => 'webmail-portal-login',
        ]);

        return $settings;
    }

    protected function workflow(string $slug, string $name, string $description): Workflow
    {
        return Workflow::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'category' => 'mail',
                'is_active' => true,
                'trigger_type' => 'manual',
                'settings_json' => [
                    'seeded' => true,
                    'template_version' => 1,
                ],
            ]
        );
    }

    protected function step(Workflow $workflow, array $payload): WorkflowStep
    {
        $name = trim((string) ($payload['name'] ?? 'Workflow-Schritt'));
        $type = trim((string) ($payload['type'] ?? WorkflowStep::TYPE_PLANNED_ACTION));
        $actionKey = trim((string) ($payload['action_key'] ?? Str::slug($name.'-'.$type)));

        return WorkflowStep::query()->updateOrCreate(
            [
                'workflow_id' => $workflow->id,
                'action_key' => $actionKey,
            ],
            [
                'name' => $name,
                'type' => $type,
                'position' => (int) ($payload['position'] ?? 10),
                'is_enabled' => (bool) ($payload['is_enabled'] ?? true),
                'config_json' => is_array($payload['config_json'] ?? null) ? $payload['config_json'] : [],
                'retry_attempts' => (int) ($payload['retry_attempts'] ?? 0),
                'wait_after_seconds' => (int) ($payload['wait_after_seconds'] ?? 0),
            ]
        );
    }
}
