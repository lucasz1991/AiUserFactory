<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\SettingsPage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowCopilotSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_vision_fallback_order_and_optimization_budgets_are_persisted(): void
    {
        Livewire::test(SettingsPage::class, ['tab' => 'assistant'])
            ->set('assistantVisionFallbackModels', "google/gemini-2.5-flash\nanthropic/claude-sonnet-4\ngoogle/gemini-2.5-flash")
            ->set('assistantCopilotMaxMinutes', 120)
            ->set('assistantCopilotMaxRepairIterations', 20)
            ->set('assistantCopilotMaxProbeActions', 75)
            ->set('assistantCopilotMaxSameStateRepeats', 3)
            ->set('assistantCopilotMaxCostUsd', 12.5)
            ->set('assistantCopilotPermissionMode', 'ask_all')
            ->call('saveAssistant')
            ->assertHasNoErrors();

        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');

        $this->assertSame([
            'google/gemini-2.5-flash',
            'anthropic/claude-sonnet-4',
        ], $settings['vision_fallback_models']);
        $this->assertSame([
            'max_minutes' => 120,
            'max_repair_iterations' => 20,
            'max_probe_actions' => 75,
            'max_same_state_repeats' => 3,
            'max_cost_usd' => 12.5,
            'auto_execute_workflow_actions' => false,
            'permission_mode' => 'ask_all',
        ], $settings['optimization_defaults']);
    }
}
