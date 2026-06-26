<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Workflows\WorkflowTemplateService;
use Illuminate\Database\Seeder;

class WebmailPortalLoginSeeder extends Seeder
{
    public function run(): void
    {
        app(WorkflowTemplateService::class)->ensureDefaults();

        Setting::setValue('webmail', 'portal_login', [
            'default_provider' => 'proton',
            'providers' => [
                'proton' => [
                    'label' => 'Proton',
                    'webmail_url' => 'https://mail.proton.me',
                    'workflow_slug' => 'webmail-portal-login',
                ],
                'gmx' => [
                    'label' => 'GMX',
                    'webmail_url' => 'https://www.gmx.net',
                    'workflow_slug' => 'webmail-portal-login',
                ],
            ],
        ]);
    }
}
