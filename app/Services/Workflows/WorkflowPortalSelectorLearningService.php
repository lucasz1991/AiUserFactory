<?php

namespace App\Services\Workflows;

/**
 * Verbindet den echten Copilot-Probezyklus mit dem Portal-Gedaechtnis.
 *
 * Ein gespeicherter Selector ist nie alleinige Ausfuehrungsevidenz: Er darf
 * lediglich Kandidaten priorisieren, die in der aktuellen DOM-Beobachtung
 * erneut sichtbar und fuer eindeutige Aktionen genau einmal belegt wurden.
 */
class WorkflowPortalSelectorLearningService
{
    public function __construct(
        private readonly WorkflowPortalProfileService $profiles,
        private readonly WorkflowSelectorProbeService $selectorProbes,
    ) {}

    /** @return array{domain:string, role:string} */
    public function contextFor(array $task, array $observation): array
    {
        $domain = $this->profiles->normalizeDomain((string) data_get($observation, 'page.url', ''));
        $role = $this->selectorProbes->semanticRoleForTask($task);

        return $domain !== '' && $role !== ''
            ? ['domain' => $domain, 'role' => $role]
            : ['domain' => '', 'role' => ''];
    }

    /** @return list<string> */
    public function preferredSelectors(array $task, array $observation, array $rejectedSelectors = []): array
    {
        $context = $this->contextFor($task, $observation);

        if ($context['domain'] === '' || $context['role'] === '') {
            return [];
        }

        return collect($this->profiles->rankedSelectorsFor($context['domain'], $context['role']))
            ->reject(fn (string $selector): bool => in_array($selector, $rejectedSelectors, true))
            ->filter(fn (string $selector): bool => $this->selectorProbes->isSafeSelector($selector))
            ->values()
            ->all();
    }

    public function rememberSuccessfulProbe(array $plan): void
    {
        $context = is_array($plan['portal_profile_context'] ?? null) ? $plan['portal_profile_context'] : [];
        $selector = trim((string) data_get($plan, 'changes.selector', ''));

        if (filled($context['domain'] ?? null) && filled($context['role'] ?? null) && $selector !== '') {
            $this->profiles->remember(
                (string) $context['domain'],
                (string) $context['role'],
                $selector,
                'copilot_probe',
                [
                    'workflow_run_id' => data_get($plan, 'workflow_run_id'),
                    'workflow_step_id' => data_get($plan, 'workflow_step_id'),
                    'task_key' => data_get($plan, 'task_key'),
                    'failure_class' => data_get($plan, 'decision_trace.failure_class'),
                    'decision_source' => data_get($plan, 'decision_trace.source'),
                    'element_ref' => data_get($plan, 'evidence.element_ref'),
                    'match_count' => data_get($plan, 'evidence.match_count', 1),
                    'observed_at' => now()->toIso8601String(),
                ],
            );
        }
    }

    public function recordFailedProbe(array $plan): void
    {
        $context = is_array($plan['portal_profile_context'] ?? null) ? $plan['portal_profile_context'] : [];
        $selector = trim((string) data_get($plan, 'changes.selector', ''));

        if (filled($context['domain'] ?? null) && filled($context['role'] ?? null) && $selector !== '') {
            $this->profiles->recordMiss((string) $context['domain'], (string) $context['role'], $selector);
        }
    }
}
