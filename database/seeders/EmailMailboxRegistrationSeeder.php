<?php

namespace Database\Seeders;

use App\Services\Workflows\WorkflowTemplateService;
use Illuminate\Database\Seeder;

class EmailMailboxRegistrationSeeder extends Seeder
{
    public function run(): void
    {
        $templates = app(WorkflowTemplateService::class);

        $templates->ensureMailSettings();
        $templates->ensureDefaults();
    }
}
