<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifiziert die ausgelieferte Google-Such-Import-CSV: echter Import-Roundtrip
 * plus Validator -> muss ausfuehrbar sein (valid, keine error-Diagnosen).
 */
class GoogleSearchImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_search_csv_imports_and_validates(): void
    {
        $path = base_path('docs/examples/google-suche-ergebnisse.csv');
        $csv = file_get_contents($path);
        $this->assertNotFalse($csv, 'CSV nicht lesbar: '.$path);

        $result = app(WorkflowTransferService::class)->importCsv($csv);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);

        $workflow = Workflow::query()->where('slug', 'google-suche-ergebnisse')->with('steps')->firstOrFail();
        $this->assertSame(6, $workflow->steps->count());

        $validation = app(WorkflowDefinitionValidator::class)->validate(
            $workflow,
            ['Rueckgabewert = array'],
            [],
        );

        $errors = collect($validation['diagnostics'])->where('severity', 'error')->values()->all();
        $this->assertTrue(
            $validation['valid'],
            'Validator meldet Fehler: '.json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $this->assertSame([], $errors);
    }
}
