@props([
    'workflowRun' => null,
    'workflow' => null,
    'activeStepId' => null,
    'activeTaskKey' => null,
    'selectedStepId' => null,
    'selectedTaskKey' => null,
    'compact' => false,
    'showHeader' => true,
    'selectableTasks' => false,
    'zoomable' => false,
    'initialZoom' => 'detail',
    'instance' => null,
    'source' => null,
    'routeMap' => null,
    'liveFlow' => false,
    'runtimeTask' => null,
])

@php
    // Route-Berechnungen verwenden spaeter lokal ebenfalls `$source`; die
    // oeffentliche Event-Quelle deshalb vorab instanzsicher festhalten.
    $minimapEventSource = $source;
    $workflow = $workflow ?: $workflowRun?->workflow;
    $zoomLevels = [
        'overview' => 'Übersicht',
        'standard' => 'Standard',
        'detail' => 'Detail',
    ];
    $initialZoom = array_key_exists((string) $initialZoom, $zoomLevels)
        ? (string) $initialZoom
        : ($compact ? 'standard' : 'detail');
    $steps = collect($workflow?->steps ?? [])->values();
    $stepRuns = collect($workflowRun?->stepRuns ?? [])->values();
    $runningStepRun = $stepRuns->first(fn ($stepRun) => in_array($stepRun->status, ['running', 'waiting'], true));
    $activeStepId = $activeStepId ?: ($workflowRun?->current_workflow_step_id ?: $runningStepRun?->workflow_step_id);
    $activeTaskKey = trim((string) ($activeTaskKey ?: data_get($workflowRun?->context_json, 'next_task_key', '')));
    if ($liveFlow) {
        $runtimeTask = is_array($runtimeTask)
            ? $runtimeTask
            : ($workflow ? app(\App\Services\Workflows\WorkflowLiveTaskPresenter::class)->present($workflow, $workflowRun) : null);
        $activeStepId = $runtimeTask['step_id'] ?? $workflowRun?->current_workflow_step_id;
        $activeTaskKey = (string) ($runtimeTask['task_key'] ?? '');
    }
    // A retained terminal cursor is not a task still running. The ordinary
    // definition/historical preview keeps its existing selection contract.
    if ($liveFlow && ! in_array($workflowRun?->status, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true)) {
        $activeStepId = null;
        $activeTaskKey = '';
    }
    $selectedStepId = (int) $selectedStepId;
    $selectedTaskKey = trim((string) $selectedTaskKey);
    $stepRunByStep = $stepRuns->groupBy('workflow_step_id')->map(fn ($runs) => $runs->last());
    // Feature R3: Beim Ruecksprung ueberschreibt der neue Lauf `result_json` der
    // Liste mit den Tasks ab dem Sprungziel. Die eingefrorene Historie liefert
    // den letzten bekannten Zustand der herausgefallenen Tasks nach, damit der
    // zurueckgelegte Weg sichtbar bleibt.
    $taskHistoryByStep = collect(data_get($workflowRun?->context_json, 'task_history', []))
        ->filter(fn ($entry) => is_array($entry) && trim((string) data_get($entry, 'task_key', '')) !== '')
        ->groupBy(fn ($entry) => (int) data_get($entry, 'workflow_step_id', 0))
        ->map(function ($entries) {
            $byKey = collect();

            foreach ($entries->sortBy(fn ($entry) => (int) data_get($entry, 'seq', 0)) as $entry) {
                $byKey->put((string) $entry['task_key'], [
                    'key' => (string) $entry['task_key'],
                    'status' => trim((string) data_get($entry, 'status', '')) ?: 'completed',
                    'title' => (string) data_get($entry, 'title', ''),
                    'seq' => (int) data_get($entry, 'seq', 0),
                ]);
            }

            return $byKey;
        });
    $taskResultsByStep = $stepRuns
        ->groupBy('workflow_step_id')
        ->map(function ($runs, $stepId) use ($taskHistoryByStep) {
            // Historie zuerst, aktueller Snapshot ueberschreibt sie.
            $results = collect($taskHistoryByStep->get((int) $stepId, collect()))->all();
            $results = collect($results);

            foreach ($runs as $run) {
                foreach ((array) data_get($run?->result_json, 'tasks', []) as $taskResult) {
                    if (is_array($taskResult) && trim((string) data_get($taskResult, 'key', '')) !== '') {
                        $taskKey = (string) data_get($taskResult, 'key');
                        $knownResult = $results->get($taskKey);

                        // withTaskStatuses() ergaenzt den Snapshot um noch nicht
                        // gelaufene Template-Karten ohne Status. Deren Felder
                        // duerfen den letzten historischen Status nicht loeschen.
                        if (is_array($knownResult)
                            && trim((string) data_get($taskResult, 'status', '')) === ''
                            && trim((string) data_get($knownResult, 'status', '')) !== '') {
                            $taskResult['status'] = data_get($knownResult, 'status');
                        }

                        $results->put(
                            $taskKey,
                            is_array($knownResult) ? array_replace($knownResult, $taskResult) : $taskResult,
                        );
                    }
                }
            }

            return $results;
        });

    // Listen, die nur in der Historie vorkommen (aktueller Step-Run hat sie
    // ueberschrieben), duerfen nicht verloren gehen.
    foreach ($taskHistoryByStep as $historyStepId => $historyTasks) {
        if (! $taskResultsByStep->has($historyStepId)) {
            $taskResultsByStep->put($historyStepId, collect($historyTasks));
        }
    }
    $stepById = $steps->keyBy('id');
    $stepByAction = $steps->keyBy(fn ($step) => (string) $step->action_key);
    $actionKeyForTask = static function (string $taskKey, string $preferredAction = '') use ($steps): string {
        if ($taskKey === '') {
            return '';
        }

        $matchingActions = [];

        foreach ($steps as $step) {
            foreach (collect($step->task_cards)->values() as $task) {
                if ((string) ($task['key'] ?? '') === $taskKey) {
                    $action = trim((string) $step->action_key);
                    if ($action === $preferredAction) {
                        return $action;
                    }

                    if ($action !== '' && ! in_array($action, $matchingActions, true)) {
                        $matchingActions[] = $action;
                    }
                }
            }
        }

        return count($matchingActions) === 1 ? $matchingActions[0] : '';
    };
    $nodePositions = [];
    $firstTaskNodeByStep = [];
    $taskLabelByNode = [];

    foreach ($steps as $stepIndex => $step) {
        $actionKey = trim((string) $step->action_key);

        if ($actionKey === '') {
            continue;
        }

        $stepNode = $actionKey.'::*';
        $nodePositions[$stepNode] = ['step' => $stepIndex, 'task' => -1];
        $taskLabelByNode[$stepNode] = $step->name;

        foreach (collect($step->task_cards)->values() as $taskIndex => $task) {
            $taskKey = trim((string) ($task['key'] ?? ''));

            if ($taskKey === '') {
                continue;
            }

            $node = $actionKey.'::'.$taskKey;
            $nodePositions[$node] = ['step' => $stepIndex, 'task' => $taskIndex];
            $taskLabelByNode[$node] = $step->name.' / '.(string) ($task['title'] ?? $taskKey);
            $firstTaskNodeByStep[$actionKey] ??= $node;
        }
    }

    $routeDirection = static function (string $sourceNode, string $targetNode, string $type) use ($nodePositions): string {
        if (in_array($type, ['end', 'fail'], true)) {
            return $type;
        }

        if ($sourceNode === '' || $targetNode === '' || ! isset($nodePositions[$sourceNode], $nodePositions[$targetNode])) {
            return 'route';
        }

        $source = $nodePositions[$sourceNode];
        $target = $nodePositions[$targetNode];

        if ($sourceNode === $targetNode) {
            return 'loop';
        }

        if ($target['step'] < $source['step'] || ($target['step'] === $source['step'] && $target['task'] <= $source['task'])) {
            return 'back';
        }

        return 'forward';
    };
    $runtimeRouteEvents = collect(data_get($workflowRun?->context_json, 'route_history', []))
        ->filter(fn ($event) => is_array($event) && is_array(data_get($event, 'route')))
        ->map(function (array $event, int $index) use ($stepById, $stepByAction, $firstTaskNodeByStep, $taskLabelByNode, $routeDirection) {
            $route = data_get($event, 'route', []);
            $outcome = (string) data_get($event, 'outcome', '-');
            $logicalOutcome = trim((string) data_get($event, 'logical_outcome', ''));
            $routeDisposition = trim((string) data_get($event, 'route_disposition', ''));
            $routeType = trim((string) data_get($route, 'type', 'step')) ?: 'step';
            $sourceStep = $stepById->get((int) data_get($event, 'workflow_step_id'));
            $sourceAction = trim((string) ($sourceStep?->action_key ?? ''));
            $sourceCard = trim((string) data_get($route, '_source_card_key', ''));
            $sourceNode = $sourceAction !== '' ? $sourceAction.'::'.($sourceCard !== '' ? $sourceCard : '*') : '';
            $targetAction = trim((string) data_get($route, 'action_key', data_get($route, 'step', '')));
            $targetCard = trim((string) data_get($route, 'card_key', data_get($route, 'card', '')));
            $targetNode = '';

            if (! in_array($routeType, ['end', 'fail'], true) && ! in_array($targetAction, ['', 'next', 'end', 'fail'], true)) {
                $targetNode = $targetAction.'::'.($targetCard !== '' ? $targetCard : '*');

                if ($targetCard === '' && isset($firstTaskNodeByStep[$targetAction])) {
                    $targetNode = $firstTaskNodeByStep[$targetAction];
                }
            }

            $direction = $routeDirection($sourceNode, $targetNode, $routeType);
            $targetStep = $stepByAction->get($targetAction);
            $directionLabel = match ($direction) {
                'back' => 'Zuruecksprung',
                'forward' => 'Weiterlauf',
                'loop' => 'Schleife',
                'end' => 'Ende',
                'fail' => 'Abbruch',
                default => 'Route',
            };
            $lineTone = match (true) {
                in_array($routeDisposition, ['fail', 'invalid'], true) => 'failed',
                in_array($logicalOutcome, ['condition_true', 'success'], true) => 'success',
                $logicalOutcome === 'condition_false' => 'waiting',
                in_array($logicalOutcome, ['technical_error', 'timeout'], true) => 'failed',
                $outcome === 'success' => 'success',
                in_array($outcome, ['partial', 'waiting'], true) => 'waiting',
                default => 'default',
            };

            return [
                'id' => 'route-'.$index,
                'at' => (string) data_get($event, 'at', ''),
                'outcome' => $outcome,
                'outcomeLabel' => match ($logicalOutcome) {
                    'condition_true' => 'Bedingung wahr',
                    'condition_false' => 'Bedingung falsch',
                    'technical_error' => 'Technischer Fehler',
                    default => $outcome,
                },
                'logicalOutcome' => $logicalOutcome,
                'routeDisposition' => $routeDisposition,
                'type' => $routeType,
                'direction' => $direction,
                'directionLabel' => $directionLabel,
                'lineTone' => $lineTone,
                'sourceNode' => $sourceNode,
                'targetNode' => $targetNode,
                'sourceLabel' => $taskLabelByNode[$sourceNode] ?? ($sourceStep?->name ?? '-'),
                'targetLabel' => $targetNode !== ''
                    ? ($taskLabelByNode[$targetNode] ?? ($targetStep?->name ?? '-'))
                    : (string) data_get($route, 'label', $routeType),
                'routeLabel' => (string) data_get($route, 'label', data_get($route, 'action_key', data_get($route, 'type', '-'))),
            ];
        })
        ->filter()
        ->values();
    $configuredRouteEvents = collect();

    if (! $workflowRun) {
        $appendConfiguredRoute = static function (
            string $sourceNode,
            string $sourceAction,
            mixed $route,
            string $outcome,
        ) use (&$configuredRouteEvents, $firstTaskNodeByStep, $taskLabelByNode, $stepByAction, $routeDirection): void {
            if (! is_array($route)) {
                return;
            }

            $routeType = trim((string) data_get($route, 'type', ''));
            $targetAction = trim((string) data_get($route, 'action_key', data_get($route, 'step', '')));
            $targetCard = trim((string) data_get($route, 'card_key', data_get($route, 'card', '')));

            if ($targetAction === '' && $targetCard !== '') {
                $targetAction = $sourceAction;
            }

            if (in_array($targetAction, ['end', 'fail'], true)) {
                $routeType = $targetAction;
            }

            if (in_array($routeType, ['end', 'fail'], true)) {
                $targetNode = '';
            } elseif ($targetAction === '' || $targetAction === 'next') {
                // Die lineare Standardroute ist bereits durch die grauen
                // Kartenverbinder sichtbar und braucht keine zweite Linie.
                return;
            } else {
                $targetNode = $targetAction.'::'.($targetCard !== '' ? $targetCard : '*');

                if ($targetCard === '' && isset($firstTaskNodeByStep[$targetAction])) {
                    $targetNode = $firstTaskNodeByStep[$targetAction];
                }
            }

            $direction = $routeDirection($sourceNode, $targetNode, $routeType ?: 'step');
            $targetStep = $stepByAction->get($targetAction);
            $configuredRouteEvents->push([
                'id' => 'configured-'.$configuredRouteEvents->count(),
                'at' => '',
                'outcome' => $outcome,
                'outcomeLabel' => match ($outcome) {
                    'success' => 'Erfolg',
                    'failed' => 'Fehler',
                    'partial' => 'Teilergebnis',
                    'timeout' => 'Zeitüberschreitung',
                    default => $outcome,
                },
                'logicalOutcome' => '',
                'routeDisposition' => '',
                'type' => $routeType ?: 'step',
                'direction' => $direction,
                'directionLabel' => 'Geplante Route',
                'lineTone' => 'default',
                'sourceNode' => $sourceNode,
                'targetNode' => $targetNode,
                'sourceLabel' => $taskLabelByNode[$sourceNode] ?? $sourceNode,
                'targetLabel' => $targetNode !== ''
                    ? ($taskLabelByNode[$targetNode] ?? ($targetStep?->name ?? $targetAction))
                    : ($routeType === 'fail' ? 'Fehlerroute' : 'Workflow-Ende'),
                'routeLabel' => (string) data_get($route, 'label', $targetCard ?: $targetAction ?: $routeType),
                'configured' => true,
            ]);
        };

        foreach ($steps as $step) {
            $stepAction = trim((string) $step->action_key);

            if ($stepAction === '') {
                continue;
            }

            foreach ([
                'success' => 'success',
                'failed' => 'failed',
                'partial' => 'partial',
                'timeout' => 'timeout',
            ] as $routeName => $outcome) {
                $appendConfiguredRoute(
                    $stepAction.'::*',
                    $stepAction,
                    data_get($step->routes, $routeName),
                    $outcome,
                );
            }

            foreach (collect($step->task_cards)->values() as $task) {
                $taskKey = trim((string) ($task['key'] ?? ''));

                if ($taskKey === '') {
                    continue;
                }

                foreach ([
                    'next' => 'success',
                    'on_error' => 'failed',
                    'on_partial' => 'partial',
                ] as $routeName => $outcome) {
                    $appendConfiguredRoute(
                        $stepAction.'::'.$taskKey,
                        $stepAction,
                        data_get($task, $routeName),
                        $outcome,
                    );
                }

                foreach (is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [] as $status => $route) {
                    $outcome = mb_strtolower(trim((string) $status));

                    if ($outcome === '') {
                        continue;
                    }

                    // Dieselbe Prioritaet wie die Runtime verwenden: Die
                    // allgemeinen Task-Routen uebersteuern statusgenaue
                    // Alternativen. So zeigt die statische Karte keine
                    // konfigurierte, aber zur Laufzeit unerreichbare Linie.
                    if (
                        ($outcome === 'success' && is_array($task['next'] ?? null))
                        || ($outcome === 'partial' && is_array($task['on_partial'] ?? null))
                        || (in_array($outcome, ['failed', 'timeout'], true) && is_array($task['on_error'] ?? null))
                    ) {
                        continue;
                    }

                    $appendConfiguredRoute(
                        $stepAction.'::'.$taskKey,
                        $stepAction,
                        $route,
                        $outcome,
                    );
                }
            }
        }
    }
    $routeEvents = $workflowRun ? $runtimeRouteEvents : $configuredRouteEvents
        ->unique(fn (array $event): string => implode('|', [
            (string) ($event['sourceNode'] ?? ''),
            (string) ($event['targetNode'] ?? ''),
            (string) ($event['outcome'] ?? ''),
        ]))
        ->values();
    $pendingRouteTargetCard = trim((string) data_get($workflowRun?->context_json, 'next_task_key', ''));
    $pendingRouteOutcome = trim((string) data_get($workflowRun?->context_json, 'next_task_route_outcome', ''));
    $pendingLogicalOutcome = trim((string) data_get($workflowRun?->context_json, 'next_task_logical_outcome', ''));
    $pendingRouteDisposition = trim((string) data_get($workflowRun?->context_json, 'next_task_route_disposition', ''));
    $pendingRouteSourceCard = trim((string) data_get($workflowRun?->context_json, 'next_task_route_source_key', ''));
    $pendingRouteTargetAction = trim((string) data_get($workflowRun?->context_json, 'next_step_action_key', ''));
    $pendingRouteSourceAction = $actionKeyForTask($pendingRouteSourceCard);

    if ($pendingRouteTargetAction === '') {
        $pendingRouteTargetAction = $actionKeyForTask($pendingRouteTargetCard);
    }

    if ($pendingRouteSourceAction === '') {
        $pendingRouteSourceAction = $pendingRouteTargetAction;
    }

    if ($pendingRouteTargetCard !== '' && $pendingRouteOutcome !== '' && $pendingRouteTargetAction !== '') {
        $sourceNode = $pendingRouteSourceAction !== '' && $pendingRouteSourceCard !== ''
            ? $pendingRouteSourceAction.'::'.$pendingRouteSourceCard
            : '';
        $targetNode = $pendingRouteTargetAction.'::'.$pendingRouteTargetCard;
        $direction = $routeDirection($sourceNode, $targetNode, 'card');
        $pendingRouteEvent = [
            'id' => 'route-pending',
            'at' => '',
            'outcome' => $pendingRouteOutcome,
            'outcomeLabel' => match ($pendingLogicalOutcome) {
                'condition_true' => 'Bedingung wahr',
                'condition_false' => 'Bedingung falsch',
                'technical_error' => 'Technischer Fehler',
                default => $pendingRouteOutcome,
            },
            'logicalOutcome' => $pendingLogicalOutcome,
            'routeDisposition' => $pendingRouteDisposition,
            'type' => 'card',
            'direction' => $direction,
            'directionLabel' => match ($direction) {
                'back' => 'Aktiver Ruecksprung',
                'forward' => 'Aktiver Weiterlauf',
                'loop' => 'Aktive Schleife',
                default => 'Aktive Route',
            },
            'lineTone' => match (true) {
                in_array($pendingRouteDisposition, ['fail', 'invalid'], true) => 'failed',
                in_array($pendingLogicalOutcome, ['condition_true', 'success'], true) => 'success',
                $pendingLogicalOutcome === 'condition_false' => 'waiting',
                in_array($pendingLogicalOutcome, ['technical_error', 'timeout'], true) => 'failed',
                $pendingRouteOutcome === 'success' => 'success',
                in_array($pendingRouteOutcome, ['partial', 'waiting'], true) => 'waiting',
                default => 'default',
            },
            'sourceNode' => $sourceNode,
            'targetNode' => $targetNode,
            'sourceLabel' => $taskLabelByNode[$sourceNode] ?? $pendingRouteSourceCard,
            'targetLabel' => $taskLabelByNode[$targetNode] ?? $pendingRouteTargetCard,
            'routeLabel' => 'naechster Task: '.$pendingRouteTargetCard,
            'pending' => true,
        ];
        $pendingRouteExists = $routeEvents->contains(fn (array $event): bool => ($event['sourceNode'] ?? '') === $sourceNode
            && ($event['targetNode'] ?? '') === $targetNode
            && ($event['outcome'] ?? '') === $pendingRouteOutcome);

        if ($pendingRouteExists) {
            $routeEvents = $routeEvents
                ->map(fn (array $event): array => (($event['sourceNode'] ?? '') === $sourceNode
                    && ($event['targetNode'] ?? '') === $targetNode
                    && ($event['outcome'] ?? '') === $pendingRouteOutcome)
                        ? array_merge($event, ['pending' => true, 'directionLabel' => $pendingRouteEvent['directionLabel'], 'routeLabel' => $pendingRouteEvent['routeLabel']])
                        : $event)
                ->values();
        } else {
            $routeEvents->push($pendingRouteEvent);
        }
    }

    // Both the definition editor and the test preview consume the same
    // presentation graph. The preview keeps all configured paths and adds the
    // executed/pending runtime path with a distinct tone.
    $routeMap = is_array($routeMap)
        ? $routeMap
        : ($workflow
            ? app(\App\Services\Workflows\WorkflowRouteMapPresenter::class)->present(
                $workflow,
                $workflowRun,
                $workflowRun
                    ? \App\Services\Workflows\WorkflowRouteMapPresenter::MODE_COMBINED
                    : \App\Services\Workflows\WorkflowRouteMapPresenter::MODE_DEFINITION,
            )
            : ['nodes' => [], 'edges' => []]);
    $presentedNodes = collect($routeMap['nodes'] ?? [])->keyBy('id');
    $routeEvents = collect($routeMap['edges'] ?? [])
        ->filter(fn (array $edge): bool => ($edge['reachable'] ?? true) !== false)
        ->sortBy(fn (array $edge): int => ($edge['runtime'] ?? false) ? 1 : 0)
        ->values()
        ->map(function (array $edge) use ($presentedNodes): array {
            $outcome = mb_strtolower(trim((string) ($edge['outcome'] ?? '')));
            $sourceNode = trim((string) ($edge['source'] ?? ''));
            $targetNode = trim((string) ($edge['target'] ?? ''));
            $source = $presentedNodes->get($sourceNode, []);
            $target = $presentedNodes->get($targetNode, []);
            $runtime = (bool) ($edge['runtime'] ?? false);
            $pending = (bool) ($edge['pending'] ?? false);
            $executed = (bool) ($edge['executed'] ?? false);
            $direction = trim((string) ($edge['direction'] ?? 'route')) ?: 'route';
            $targetKind = trim((string) ($target['kind'] ?? ''));

            return [
                'id' => (string) ($edge['id'] ?? 'route-'.sha1($sourceNode.'|'.$targetNode.'|'.$outcome)),
                'at' => (string) ($edge['latest_at'] ?? ''),
                'outcome' => $outcome,
                'outcomeLabel' => match ($outcome) {
                    'success' => 'Erfolg',
                    'failed' => 'Fehler',
                    'partial' => 'Teilergebnis',
                    'timeout' => 'Zeitüberschreitung',
                    'enter' => 'Listeneinstieg',
                    default => $outcome !== '' ? $outcome : 'Route',
                },
                'logicalOutcome' => (string) ($edge['logical_outcome'] ?? ''),
                'routeDisposition' => (string) ($edge['route_disposition'] ?? ''),
                'type' => (string) ($edge['type'] ?? 'route'),
                'direction' => $direction,
                'directionLabel' => match (true) {
                    $pending => 'Aktive Route',
                    $runtime && $executed && $direction === 'back' => 'Laufweg zurück',
                    $runtime && $executed => 'Laufweg',
                    $direction === 'back' => 'Geplanter Rücksprung',
                    $direction === 'loop' => 'Geplante Schleife',
                    default => 'Geplante Route',
                },
                'lineTone' => match (true) {
                    $runtime && ($executed || $pending) => 'runtime',
                    $outcome === 'failed' => 'failed',
                    $outcome === 'timeout' => 'timeout',
                    $outcome === 'success' => 'success',
                    $outcome === 'partial' => 'partial',
                    default => 'default',
                },
                'sourceNode' => $sourceNode,
                'targetNode' => $targetNode,
                'sourceLabel' => (string) ($source['title'] ?? $sourceNode),
                'targetLabel' => (string) ($target['title'] ?? $targetNode),
                'routeLabel' => (string) ($edge['label'] ?? ($target['title'] ?? $targetNode)),
                'configured' => in_array('definition', $edge['origins'] ?? [], true),
                'runtime' => $runtime,
                'runtimeCount' => (int) ($edge['runtime_count'] ?? 0),
                'pending' => $pending,
                'terminal' => $targetKind === 'terminal',
            ];
        });
    // Feature R3: Frueher wurden nur die letzten 16 Ereignisse gezeichnet —
    // dadurch verschwanden bei Rousspruengen genau die Linien, die den bisher
    // zurueckgelegten Weg zeigen. Jetzt bleiben alle erhalten; das Alter steuert
    // ausschliesslich die Deckkraft, nie die Sichtbarkeit.
    $routeEventCount = max(1, $routeEvents->count());
    $routeEventsForJs = $routeEvents
        ->values()
        ->map(function (array $routeEvent, int $eventIndex) use ($routeEventCount, $liveFlow): array {
            // Juengste Linie 1.0, aelteste 0.35 — deutlich blasser, aber sichtbar.
            $routeEvent['ageOpacity'] = round(0.35 + (0.65 * (($eventIndex + 1) / $routeEventCount)), 3);
            $routeEvent['ageIndex'] = $eventIndex + 1;
            $routeEvent['ageTotal'] = $routeEventCount;
            // The shared renderer uses outcome as its display tone. Keep the
            // actual execution outcome alongside it, and distinguish observed
            // or explicitly pending runtime paths from the planned graph.
            if ($liveFlow && ($routeEvent['runtime'] ?? false)
                && ((int) ($routeEvent['runtimeCount'] ?? 0) > 0 || ($routeEvent['pending'] ?? false))) {
                $routeEvent['executionOutcome'] = $routeEvent['outcome'];
                $routeEvent['outcome'] = 'runtime';
            }

            return $routeEvent;
        })
        ->all();
    $mapInstance = trim((string) ($instance ?: ($workflowRun?->id ? 'run-'.$workflowRun->id : 'workflow-'.$workflow?->id)));
    $mapSource = trim((string) ($minimapEventSource ?: $mapInstance));
    $mapId = 'workflow-minimap-'.(\Illuminate\Support\Str::slug($mapInstance) ?: 'preview');
    $activeStep = $stepById->get((int) $activeStepId);
    $activeStepAction = trim((string) ($activeStep?->action_key ?? ''));
    $contextTaskAction = trim((string) data_get($workflowRun?->context_json, 'next_step_action_key', ''));
    $activeTaskAction = $activeTaskKey !== ''
        ? $actionKeyForTask($activeTaskKey, $contextTaskAction !== '' ? $contextTaskAction : $activeStepAction)
        : '';
    $activeRouteAction = $activeTaskAction !== '' ? $activeTaskAction : $activeStepAction;
    $activeRouteNode = $activeRouteAction !== ''
        ? $activeRouteAction.'::'.($activeTaskKey !== '' ? $activeTaskKey : '*')
        : '';
    $selectedRouteStep = $stepById->get((int) $selectedStepId);
    if ($selectedRouteStep && $selectedTaskKey !== '') {
        $activeRouteNode = $selectedRouteStep->action_key.'::'.$selectedTaskKey;
    }
    $feedbackByTask = app(\App\Services\Workflows\WorkflowRunTaskFeedback::class)->tasks($workflow, $workflowRun);
    $taskTone = static function (string $status, bool $active): string {
        return match (true) {
            $active || in_array($status, ['running', 'waiting'], true) => 'border-amber-300 bg-amber-50 text-amber-900 shadow-amber-100',
            $status === 'completed' || $status === 'success' => 'border-emerald-300 bg-emerald-50 text-emerald-900 shadow-emerald-100',
            $status === 'skipped' || $status === 'not_executed' => 'border-slate-200 bg-slate-50 text-slate-500 shadow-slate-100',
            in_array($status, ['failed', 'timeout'], true) => 'border-red-300 bg-red-50 text-red-900 shadow-red-100',
            default => 'border-slate-200 bg-white text-slate-600 shadow-slate-100',
        };
    };
@endphp

<div
    {{ $attributes->merge(['class' => 'space-y-3']) }}
    data-workflow-minimap-instance="{{ $mapInstance }}"
    data-workflow-minimap-source="{{ $mapSource }}"
>
    @if(! $workflow)
        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
            Keine Workflow-Daten fuer diesen Prozess gefunden.
        </div>
    @else
        @if($showHeader)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-slate-900">{{ $workflow->name }}</div>
                    @if($workflowRun)
                        <div class="mt-1 truncate text-xs text-slate-500">Run #{{ $workflowRun->id }} - {{ $workflowRun->status }}</div>
                    @else
                        <div class="mt-1 truncate text-xs text-slate-500">Workflow-Definition · {{ $steps->count() }} Listen</div>
                    @endif
                </div>
                @if($workflowRun)
                    <x-workflows.status-badge :status="$workflowRun->status" />
                @endif
            </div>
        @endif

        <div
            x-data="{
                ...workflowRouteSurface({
                    instance: @js($mapId),
                    initialNode: @js($liveFlow ? '' : $activeRouteNode),
                }),
                instance: @js($mapInstance),
                source: @js($mapSource),
                zoomLevel: @js($initialZoom),
                routeFocusNode() {
                    return @js($liveFlow)
                        ? this.hoveredRouteNode || ''
                        : this.activeRouteNode || this.hoveredRouteNode || this.initialRouteNode || '';
                },
                isRenderable() {
                    return this.$root.offsetParent !== null && ! this.$root.closest('[inert]');
                },
                refreshForEvent(detail = {}) {
                    const requestedInstance = String(detail?.instance || '');
                    const requestedSource = String(detail?.source || '');
                    const targetsInstance = requestedInstance !== '' && requestedInstance === this.instance;
                    const targetsSource = requestedSource !== '' && requestedSource === this.source;
                    if (!targetsInstance && !targetsSource) return;
                    if (! this.isRenderable()) return;
                    this.queueRouteRefresh();
                },
                setZoom(level) {
                    if (!['overview', 'standard', 'detail'].includes(level) || this.zoomLevel === level) return;
                    this.zoomLevel = level;
                    this.$dispatch('workflow-minimap-zoom-changed', {
                        level, instance: this.instance, source: this.source,
                    });
                    this.$nextTick(() => this.queueRouteRefresh());
                },
            }"
            x-ref="routeSurface"
            data-workflow-route-surface
            x-on:scroll.passive.debounce.80ms="queueRouteRefresh()"
            x-on:workflow-minimap-refresh-requested.window="refreshForEvent($event.detail)"
            data-workflow-minimap-scroll-container
            data-workflow-preview-scrollbar
            class="relative overflow-x-auto pb-2"
        >
            <script type="application/json" x-ref="routeMap">@json(['edges' => $routeEventsForJs])</script>
            @if($zoomable)
                <div
                    class="sticky left-0 top-0 z-30 mb-2 flex w-max max-w-full items-center gap-1 rounded-xl border border-slate-200 bg-white/95 p-1 shadow-sm backdrop-blur"
                    role="group"
                    aria-label="Zoomstufe Workflow-Karte"
                    data-workflow-minimap-zoom
                >
                    @foreach($zoomLevels as $zoomKey => $zoomLabel)
                        <button
                            type="button"
                            x-on:click.stop="setZoom(@js($zoomKey))"
                            x-bind:aria-pressed="zoomLevel === @js($zoomKey)"
                            x-bind:class="zoomLevel === @js($zoomKey) ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'"
                            class="inline-flex min-h-11 items-center rounded-lg px-3 text-[11px] font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                            data-workflow-minimap-zoom-level="{{ $zoomKey }}"
                        >{{ $zoomLabel }}</button>
                    @endforeach
                    <button type="button" x-on:click.stop="toggleAllRoutes()" x-bind:aria-pressed="showAllRoutes"
                        class="ml-2 inline-flex min-h-11 items-center rounded-lg px-3 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-blue-500"
                        x-text="showAllRoutes ? 'Hauptpfad' : 'Alle Verbindungen'"></button>
                    <span class="sr-only" aria-live="polite" x-text="`Zoomstufe ${zoomLevel}`"></span>
                </div>
            @endif

            <svg
                class="pointer-events-none absolute left-0 top-0 z-20 overflow-visible"
                x-bind:width="routeOverlay.width"
                x-bind:height="routeOverlay.height"
                x-bind:viewBox="`0 0 ${routeOverlay.width} ${routeOverlay.height}`"
                aria-hidden="true"
            >
                <x-workflows.route-markers :instance="$mapId" />
                <g x-html="routeSvgMarkup"></g>
            </svg>

            <div
                class="ff-route-stage relative z-10 flex min-w-max items-start gap-0"
                data-workflow-minimap-stage
            >
                @foreach($steps as $step)
                    @php
                        $stepRun = $stepRunByStep->get($step->id);
                        $isActiveStep = (int) $activeStepId === (int) $step->id;
                        $stepStatus = (string) ($stepRun?->status ?? 'configured');
                        $tasks = collect($step->task_cards)->values();
                        $resultTasks = $taskResultsByStep->get($step->id, collect());
                        $plannedOnlyStep = $step->type === \App\Models\WorkflowStep::TYPE_PLANNED_ACTION && trim((string) ($stepRun?->external_run_id ?? '')) === '';
                        $stepTone = $taskTone($stepStatus, $isActiveStep);
                        $stepNode = trim((string) $step->action_key).'::*';
                    @endphp

                    <div class="flex items-start">
                        <div
                            class="shrink-0"
                            x-bind:class="zoomLevel === 'overview' ? 'w-36' : (zoomLevel === 'standard' ? 'w-48' : 'w-56')"
                            data-minimap-step-column="{{ $step->action_key }}"
                            data-workflow-step-column="{{ $step->action_key }}"
                        >
                            <div
                                data-minimap-node="{{ $stepNode }}"
                                data-workflow-route-node="{{ $stepNode }}"
                                data-workflow-step-action="{{ $step->action_key }}"
                                data-workflow-minimap-active-step="{{ $isActiveStep ? 'true' : 'false' }}"
                                x-on:mouseenter="setHoveredRouteNode(@js($stepNode))"
                                x-on:mouseleave="setHoveredRouteNode('')"
                                class="flex items-center justify-between gap-2 rounded px-1 py-1"
                                x-bind:class="zoomLevel === 'overview' ? 'mb-1' : 'mb-2'"
                            >
                                <div
                                    class="truncate font-semibold text-slate-800"
                                    x-bind:class="zoomLevel === 'overview' ? 'text-[9px]' : 'text-xs'"
                                >{{ $step->name }}</div>
                                @if($stepRun?->status)
                                    <span x-show="zoomLevel !== 'overview'" class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $stepTone }}">
                                        {{ $stepRun->status }}
                                    </span>
                                @endif
                            </div>

                            <div class="space-y-0">
                                @forelse($tasks as $task)
                                    @php
                                        $taskKey = (string) ($task['key'] ?? '');
                                        $taskResult = $resultTasks->get($taskKey);
                                        $feedback = $feedbackByTask[$step->id][$taskKey] ?? [];
                                        $taskStatus = $plannedOnlyStep
                                            ? 'not_executed'
                                            : (string) ($feedback['status'] ?? data_get($taskResult, 'status', data_get($task, 'status', 'configured')));
                                        $isTaskActive = $isActiveStep && ($activeTaskKey === '' ? (! $liveFlow && $loop->first && in_array($stepStatus, ['running', 'waiting'], true)) : $taskKey === $activeTaskKey);
                                        $isTaskSelected = $selectedStepId === (int) $step->id && $selectedTaskKey === $taskKey;
                                        $tone = $taskTone($taskStatus, $isTaskActive);
                                        $taskNode = trim((string) $step->action_key).'::'.$taskKey;
                                    @endphp

                                    @if(! $loop->first)
                                        <div data-workflow-task-gap aria-hidden="true"
                                            x-bind:class="zoomLevel === 'overview' ? 'h-2' : (zoomLevel === 'standard' ? 'h-3' : 'h-4')"></div>
                                    @endif

                                    <div
                                         data-minimap-node="{{ $taskNode }}"
                                         data-workflow-task-node="{{ $taskNode }}"
                                         data-workflow-step-action="{{ $step->action_key }}"
                                         data-workflow-step-id="{{ $step->id }}"
                                         data-workflow-task-key="{{ $taskKey }}"
                                         data-workflow-task-status="{{ $taskStatus }}"
                                         data-task-failed="{{ ($feedback['failed'] ?? false) ? 'true' : 'false' }}"
                                         data-workflow-minimap-active-target="{{ $isTaskActive ? 'true' : 'false' }}"
                                         data-workflow-minimap-selected-task="{{ $isTaskSelected ? 'true' : 'false' }}"
                                         @if($liveFlow && $isTaskActive)
                                             aria-current="step"
                                         @endif
                                        x-on:mouseenter="setHoveredRouteNode(@js($taskNode))"
                                        x-on:mouseleave="setHoveredRouteNode('')"
                                        @if($selectableTasks)
                                            role="button"
                                            tabindex="0"
                                             x-on:click.stop="setActiveRouteNode(@js($taskNode)); $dispatch('workflow-preview-task-selected', { workflowId: {{ (int) $workflow->id }}, stepId: {{ (int) $step->id }}, taskKey: @js($taskKey), instance, source })"
                                             x-on:keydown.enter.prevent.stop="setActiveRouteNode(@js($taskNode)); $dispatch('workflow-preview-task-selected', { workflowId: {{ (int) $workflow->id }}, stepId: {{ (int) $step->id }}, taskKey: @js($taskKey), instance, source })"
                                             x-on:dblclick.stop="$dispatch('workflow-preview-task-edit-requested', { workflowId: {{ (int) $workflow->id }}, stepId: {{ (int) $step->id }}, taskKey: @js($taskKey), instance, source })"
                                             title="Task auswählen; Doppelklick zum Bearbeiten"
                                         @endif
                                         @if($selectableTasks)
                                             aria-label="{{ $step->name }}: {{ $task['title'] ?? 'Task' }} ({{ $taskStatus }})"
                                             aria-pressed="{{ $isTaskSelected ? 'true' : 'false' }}"
                                             x-on:keydown.space.prevent.stop="setActiveRouteNode(@js($taskNode)); $dispatch('workflow-preview-task-selected', { workflowId: {{ (int) $workflow->id }}, stepId: {{ (int) $step->id }}, taskKey: @js($taskKey), instance, source })"
                                         @endif
                                         class="relative rounded-md border shadow-sm {{ $tone }} {{ $isTaskSelected ? 'ring-2 ring-sky-500 ring-offset-2 ring-offset-white' : '' }} {{ $selectableTasks ? 'min-h-11 cursor-pointer touch-manipulation transition-colors hover:shadow-md focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2' : '' }}"
                                         x-bind:class="zoomLevel === 'overview' ? 'px-1.5 py-1 text-[9px]' : (zoomLevel === 'standard' ? 'px-2 py-1 text-[10px]' : 'px-2 py-1.5 text-[11px]')"
                                     >
                                         @if($isTaskSelected)
                                             <span class="absolute inset-y-1.5 -left-1 w-1 rounded-full bg-sky-500" aria-hidden="true"></span>
                                         @endif
                                        <div class="truncate pr-2 font-semibold">{{ $task['title'] ?? 'Task' }}</div>
                                        <div x-show="zoomLevel !== 'overview'" class="mt-0.5 truncate opacity-70">{{ $taskStatus }}</div>
                                        <x-workflows.task-feedback :feedback="$feedback" />
                                    </div>
                                @empty
                                    <div
                                        data-workflow-minimap-active-target="{{ $isActiveStep ? 'true' : 'false' }}"
                                        class="rounded-md border px-2 py-1.5 text-[11px] shadow-sm {{ $stepTone }}"
                                    >
                                        <div class="truncate font-semibold">{{ $step->type_label }}</div>
                                        <div class="mt-0.5 truncate opacity-70">{{ $stepStatus }}</div>
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        @if(! $loop->last)
                            <div data-workflow-column-gap aria-hidden="true" class="shrink-0"
                                x-bind:class="zoomLevel === 'overview' ? 'w-12' : (zoomLevel === 'standard' ? 'w-14' : 'w-16')"></div>
                        @endif
                    </div>
                @endforeach

                @if($steps->isNotEmpty())
                    <div
                        class="ml-8 flex shrink-0 flex-col gap-2"
                        x-bind:class="zoomLevel === 'overview' ? 'w-28' : (zoomLevel === 'standard' ? 'w-36' : 'w-44')"
                        data-minimap-step-column="terminal"
                        data-workflow-step-column="terminal"
                        aria-label="Workflow-Terminalziele"
                    >
                        <div
                            data-minimap-node="terminal::end"
                            data-workflow-route-node="terminal::end"
                            class="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-2 text-emerald-900 shadow-sm"
                        >
                            <span class="block text-[8px] font-black uppercase tracking-wide text-emerald-700">Ende</span>
                            <span class="mt-0.5 block truncate text-[10px] font-bold">Workflow-Ende</span>
                        </div>
                        <div
                            data-minimap-node="terminal::fail"
                            data-workflow-route-node="terminal::fail"
                            class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-2 text-rose-900 shadow-sm"
                        >
                            <span class="block text-[8px] font-black uppercase tracking-wide text-rose-700">Fehler</span>
                            <span class="mt-0.5 block truncate text-[10px] font-bold">Workflow-Abbruch</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>


    @endif
</div>
