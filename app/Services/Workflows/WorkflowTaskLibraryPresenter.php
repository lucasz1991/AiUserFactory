<?php

namespace App\Services\Workflows;

class WorkflowTaskLibraryPresenter
{
    private const GROUPS = [
        'navigation' => [
            'label' => 'Start & Navigation',
            'short_label' => 'Navigation',
            'description' => 'Browser starten, Seiten öffnen, Verlauf steuern und den Browser sauber schließen.',
        ],
        'discovery' => [
            'label' => 'Finden & Auslesen',
            'short_label' => 'Finden',
            'description' => 'Elemente, Eingabefelder, Suchergebnisse, Mail-Listen und Werte gezielt erfassen.',
        ],
        'interaction' => [
            'label' => 'Interagieren & Eingeben',
            'short_label' => 'Interaktion',
            'description' => 'Scrollen, klicken, Tasten senden und Formulareingaben ausfüllen oder absenden.',
        ],
        'decisions' => [
            'label' => 'Warten & Entscheiden',
            'short_label' => 'Entscheiden',
            'description' => 'Auf Zustände warten und anhand von Elementen, Variablen oder Listen verzweigen.',
        ],
        'loops' => [
            'label' => 'Schleifen & Listen',
            'short_label' => 'Schleifen',
            'description' => 'Listen durchlaufen, Treffer verarbeiten und Ergebnisse als Arrays sammeln.',
        ],
        'accounts' => [
            'label' => 'Mail & Sitzungen',
            'short_label' => 'Mail',
            'description' => 'Mail-Adressen, Webmail-Zugriffe und gespeicherte Konto- oder Browser-Sitzungen verwalten.',
        ],
        'data' => [
            'label' => 'Daten & Abschluss',
            'short_label' => 'Daten',
            'description' => 'Workflow-Eingaben prüfen, Personen- und Login-Daten bereitstellen und Ergebnisse zurückgeben.',
        ],
        'workflows' => [
            'label' => 'Unter-Workflows',
            'short_label' => 'Workflows',
            'description' => 'Einen bestehenden aktiven Workflow als wiederverwendbaren Baustein einbinden.',
        ],
    ];

    private const TASK_ORDER = [
        'browser.open' => 10,
        'browser.open_url' => 20,
        'browser.navigate_back' => 30,
        'browser.navigate_forward' => 40,
        'browser.reload' => 50,
        'browser.close' => 60,
        'browser.find_element' => 10,
        'browser.find_inputs' => 20,
        'browser.read_element_fields' => 30,
        'browser.read_searchengine_result' => 40,
        'mail.inbox_list_scan' => 50,
        'mail.extract_value' => 60,
        'browser.scroll' => 10,
        'browser.hover' => 20,
        'browser.click' => 30,
        'browser.highlight' => 40,
        'browser.press_key' => 50,
        'input.fill_field' => 60,
        'input.submit' => 70,
        'mail.fill_address' => 80,
        'wait.selector' => 10,
        'wait.status' => 20,
        'wait.seconds' => 30,
        'human.recaptcha_handoff' => 35,
        'decision.element_exists' => 40,
        'decision.variable' => 50,
        'decision.array_length' => 60,
        'mail.check_address_availability' => 70,
        'loop.for_each_element' => 10,
        'mail.list_search_loop' => 20,
        'mail.list_action_loop' => 30,
        'data.append_to_array' => 40,
        'browser.open_webmail_session' => 10,
        'webmail.check_session' => 20,
        'webmail.read_verification_code' => 30,
        'mail.generate_address' => 40,
        'mail.generate_password' => 50,
        'data.persist_mail_account' => 60,
        'data.persist_webmail_session' => 70,
        'data.delete_browser_session' => 80,
        'data.validate_inputs' => 10,
        'data.resolve_person' => 20,
        'data.read_account_data' => 30,
        'data.read_login_data' => 40,
        'data.workflow_return' => 50,
    ];

    /** @return array<string, array<string, string>> */
    public function groups(): array
    {
        return self::GROUPS;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $options
     * @return array<int, array<string, mixed>>
     */
    public function arrange(iterable $options): array
    {
        $groupKeys = array_keys(self::GROUPS);
        $arranged = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $taskKey = trim((string) ($option['key'] ?? $option['task_key'] ?? ''));
            $group = $this->groupFor($taskKey, $option);
            $groupOrder = array_search($group, $groupKeys, true);
            $groupMeta = self::GROUPS[$group] ?? [];

            $arranged[] = [
                ...$option,
                'library_group' => $group,
                'library_group_label' => (string) ($groupMeta['label'] ?? $group),
                'library_group_short_label' => (string) ($groupMeta['short_label'] ?? $group),
                'library_group_description' => (string) ($groupMeta['description'] ?? ''),
                'library_group_order' => $groupOrder === false ? count($groupKeys) : $groupOrder,
                'library_order' => self::TASK_ORDER[$taskKey] ?? 500,
            ];
        }

        usort($arranged, static function (array $left, array $right): int {
            return [
                (int) ($left['library_group_order'] ?? 99),
                (int) ($left['library_order'] ?? 500),
                mb_strtolower((string) ($left['label'] ?? $left['key'] ?? '')),
            ] <=> [
                (int) ($right['library_group_order'] ?? 99),
                (int) ($right['library_order'] ?? 500),
                mb_strtolower((string) ($right['label'] ?? $right['key'] ?? '')),
            ];
        });

        return array_values($arranged);
    }

    /** @param array<string, mixed> $definition */
    public function groupFor(string $taskKey, array $definition = []): string
    {
        if (($definition['runner'] ?? null) === 'workflow' || str_starts_with($taskKey, 'workflow.include.')) {
            return 'workflows';
        }

        return match (true) {
            in_array($taskKey, [
                'browser.open',
                'browser.open_url',
                'browser.navigate_back',
                'browser.navigate_forward',
                'browser.reload',
                'browser.close',
            ], true) => 'navigation',
            in_array($taskKey, [
                'browser.find_element',
                'browser.find_inputs',
                'browser.read_element_fields',
                'browser.read_searchengine_result',
                'mail.inbox_list_scan',
                'mail.extract_value',
            ], true) => 'discovery',
            in_array($taskKey, [
                'browser.scroll',
                'browser.hover',
                'browser.click',
                'browser.highlight',
                'browser.press_key',
                'input.fill_field',
                'input.submit',
                'mail.fill_address',
            ], true) => 'interaction',
            in_array($taskKey, [
                'wait.selector',
                'wait.status',
                'wait.seconds',
                'human.recaptcha_handoff',
                'decision.element_exists',
                'decision.variable',
                'decision.array_length',
                'mail.check_address_availability',
            ], true) => 'decisions',
            in_array($taskKey, [
                'loop.for_each_element',
                'mail.list_search_loop',
                'mail.list_action_loop',
                'data.append_to_array',
            ], true) => 'loops',
            in_array($taskKey, [
                'browser.open_webmail_session',
                'webmail.check_session',
                'webmail.read_verification_code',
                'mail.generate_address',
                'mail.generate_password',
                'data.persist_mail_account',
                'data.persist_webmail_session',
                'data.delete_browser_session',
            ], true) => 'accounts',
            default => 'data',
        };
    }
}
