<?php

namespace App\Services\Workflows;

use App\Exceptions\WorkflowRunConflictException;
use DateTimeInterface;

/**
 * Three-way context updates preserve independent writes from stale snapshots.
 * Objects merge by key; ordinary lists remain ordered, atomic values.
 */
class WorkflowRunContextStore
{
    public function merge(array $base, array $proposed, array $current): array
    {
        $merged = $this->mergeValue($base, $proposed, $current, 'context_json');

        if (! $this->equivalent($base['task_history'] ?? [], $proposed['task_history'] ?? [])
            && ! $this->equivalent($base['task_history'] ?? [], $current['task_history'] ?? [])
            && ! $this->equivalent($proposed['task_history'] ?? [], $current['task_history'] ?? [])) {
            $merged['task_history_sequence'] = max(
                (int) ($proposed['task_history_sequence'] ?? 0),
                (int) ($current['task_history_sequence'] ?? 0),
                ...array_map(fn (array $entry): int => (int) ($entry['seq'] ?? 0), $merged['task_history']),
            );
        }

        return $merged;
    }

    public function equivalent(mixed $left, mixed $right): bool
    {
        return $this->canonical($left) === $this->canonical($right);
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.uP');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    private function mergeValue(mixed $base, mixed $proposed, mixed $current, string $path): mixed
    {
        if ($this->equivalent($base, $proposed)) {
            return $current;
        }

        if ($this->equivalent($base, $current) || $this->equivalent($proposed, $current)) {
            return $proposed;
        }

        if (in_array($path, ['context_json.task_history', 'context_json.route_history'], true)
            && is_array($base) && is_array($proposed) && is_array($current)) {
            return $this->mergeHistory($base, $proposed, $current, $path);
        }

        if (is_array($base) && is_array($proposed) && is_array($current)
            && ($base === [] || ! array_is_list($base))
            && ($proposed === [] || ! array_is_list($proposed))
            && ($current === [] || ! array_is_list($current))) {
            $merged = $current;
            foreach (array_unique(array_merge(array_keys($base), array_keys($proposed))) as $key) {
                $had = array_key_exists($key, $base);
                $has = array_key_exists($key, $proposed);
                $exists = array_key_exists($key, $current);

                if ($had === $has && (! $had || $this->equivalent($base[$key], $proposed[$key]))) {
                    continue;
                }

                if ($had === $exists && (! $had || $this->equivalent($base[$key], $current[$key]))) {
                    if ($has) {
                        $merged[$key] = $proposed[$key];
                    } else {
                        unset($merged[$key]);
                    }

                    continue;
                }

                if ($has === $exists && (! $has || $this->equivalent($proposed[$key], $current[$key]))) {
                    continue;
                }

                if ($has && $exists && (! $had || is_array($base[$key]))
                    && is_array($proposed[$key]) && is_array($current[$key])) {
                    $merged[$key] = $this->mergeValue($had ? $base[$key] : [], $proposed[$key], $current[$key], $path.'.'.$key);

                    continue;
                }

                // Concurrent history writers advance the sequence with their entries.
                if ($path === 'context_json' && $key === 'task_history_sequence'
                    && isset($merged['task_history'])
                    && ! $this->equivalent($base['task_history'] ?? [], $proposed['task_history'] ?? [])
                    && ! $this->equivalent($base['task_history'] ?? [], $current['task_history'] ?? [])) {
                    $merged[$key] = max((int) $proposed[$key], (int) $current[$key]);

                    continue;
                }

                throw new WorkflowRunConflictException($path.'.'.$key);
            }

            return $merged;
        }

        throw new WorkflowRunConflictException($path);
    }

    private function mergeHistory(array $base, array $proposed, array $current, string $path): array
    {
        // Only verified appends merge. Rewinds, replacements and incompatible
        // bounded snapshots are conflicts rather than silently lost audit entries.
        if (! array_is_list($base) || ! array_is_list($proposed) || ! array_is_list($current)
            || ! $this->equivalent($base, array_slice($proposed, 0, count($base)))
            || ! $this->equivalent($base, array_slice($current, 0, count($base)))) {
            throw new WorkflowRunConflictException($path);
        }

        $entries = array_slice($proposed, count($base));
        $sequence = max(0, ...array_map(fn (mixed $entry): int => is_array($entry) ? (int) ($entry['seq'] ?? 0) : 0, $current));
        foreach ($entries as $entry) {
            if ($path === 'context_json.task_history' && is_array($entry)) {
                $entry['seq'] = ++$sequence;
            }
            $current[] = $entry;
        }

        return array_slice($current, $path === 'context_json.task_history' ? -600 : -300);
    }
}
