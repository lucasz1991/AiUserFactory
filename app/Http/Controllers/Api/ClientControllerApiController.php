<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\MonitorWorkflowStepRunJob;
use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkJobProgressEvent;
use App\Models\NetworkNode;
use App\Models\NodeHeartbeat;
use App\Models\NodeRebindLog;
use App\Models\NodeServerBinding;
use App\Models\Setting;
use App\Models\WorkflowStepRun;
use App\Services\ClientController\ClientControllerReleaseService;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientControllerApiController extends Controller
{
    public function registerNode(Request $request): JsonResponse
    {
        $bootstrapFromRequest = trim((string) $request->header('X-BOOTSTRAP-API-KEY', $request->input('bootstrap_api_key', $request->input('api_key', ''))));
        $expectedBootstrap = trim((string) data_get(Setting::getValue('client_controller', 'security'), 'bootstrap_api_key', 'followflow-default-node-key-change-me'));

        if ($expectedBootstrap === '' || ! hash_equals($expectedBootstrap, $bootstrapFromRequest)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bootstrap API key.',
            ], 401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'node_uuid' => ['required', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:120'],
            'os' => ['nullable', 'string', 'max:120'],
            'public_ip' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'current_server_domain' => ['nullable', 'string', 'max:255'],
            'last_successful_server_domain' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'array'],
        ]);

        $node = NetworkNode::query()->firstOrNew([
            'node_uuid' => $validated['node_uuid'],
        ]);

        if (! $node->exists) {
            $node->api_key = Str::random(60);
            $node->node_secret = Str::random(60);
        }

        $node->fill([
            'name' => $node->exists ? $node->name : $validated['name'],
            'version' => $validated['version'] ?? $node->version,
            'os' => $validated['os'] ?? $node->os,
            'public_ip' => $validated['public_ip'] ?? $request->ip() ?? $node->public_ip,
            'country' => $validated['country'] ?? $node->country,
            'city' => $validated['city'] ?? $node->city,
            'current_server_domain' => $validated['current_server_domain'] ?? $node->current_server_domain,
            'last_successful_server_domain' => $validated['last_successful_server_domain'] ?? $node->last_successful_server_domain,
            'capabilities_json' => $validated['capabilities'] ?? $node->capabilities_json,
            'is_online' => true,
            'last_seen_at' => now(),
        ]);

        $node->save();
        $this->reconcileNodeUpdate($node);

        NodeServerBinding::query()->firstOrCreate(
            [
                'network_node_id' => $node->id,
                'server_domain' => $node->current_server_domain ?: config('app.url'),
                'status' => 'bound',
            ],
            [
                'bound_at' => now(),
                'last_successful_contact_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'node' => [
                'id' => $node->id,
                'name' => $node->name,
                'node_uuid' => $node->node_uuid,
                'api_key' => $node->api_key,
                'allow_server_rebind' => (bool) $node->allow_server_rebind,
                'current_server_domain' => $node->current_server_domain,
                'last_successful_server_domain' => $node->last_successful_server_domain,
            ],
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $node = $this->resolveNodeFromApiKey($request);

        if (! $node) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized node.',
            ], 401);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'payload' => ['nullable', 'array'],
            'capabilities' => ['nullable', 'array'],
            'public_ip' => ['nullable', 'string', 'max:64'],
            'version' => ['nullable', 'string', 'max:120'],
            'os' => ['nullable', 'string', 'max:120'],
            'current_server_domain' => ['nullable', 'string', 'max:255'],
            'last_successful_server_domain' => ['nullable', 'string', 'max:255'],
        ]);

        NodeHeartbeat::query()->create([
            'network_node_id' => $node->id,
            'status' => $validated['status'] ?? 'online',
            'payload_json' => $validated['payload'] ?? [],
            'received_at' => now(),
        ]);

        $capabilities = is_array($validated['capabilities'] ?? null)
            ? $validated['capabilities']
            : (is_array($node->capabilities_json) ? $node->capabilities_json : []);
        $metrics = data_get($validated, 'payload.metrics');

        if (is_array($metrics) && $metrics !== []) {
            $cpuLoadPercent = data_get($metrics, 'cpuLoadPercent', data_get($metrics, 'cpu_load_percent'));
            $capabilities['runtime_metrics'] = array_replace(
                is_array($capabilities['runtime_metrics'] ?? null) ? $capabilities['runtime_metrics'] : [],
                [
                    'cpu_load_percent' => is_numeric($cpuLoadPercent) ? round((float) $cpuLoadPercent, 2) : null,
                    'reported_at' => data_get($metrics, 'reportedAt', data_get($metrics, 'reported_at', now()->toIso8601String())),
                ],
            );
        }

        $node->forceFill([
            'is_online' => true,
            'last_seen_at' => now(),
            'public_ip' => $validated['public_ip'] ?? $request->ip() ?? $node->public_ip,
            'version' => $validated['version'] ?? $node->version,
            'os' => $validated['os'] ?? $node->os,
            'current_server_domain' => $validated['current_server_domain'] ?? $node->current_server_domain,
            'last_successful_server_domain' => $validated['last_successful_server_domain'] ?? $node->last_successful_server_domain,
            'capabilities_json' => $capabilities,
        ])->save();
        $this->reconcileNodeUpdate($node);

        NodeServerBinding::query()
            ->where('network_node_id', $node->id)
            ->where('server_domain', $node->current_server_domain ?: config('app.url'))
            ->latest('id')
            ->first()?->update([
                'last_successful_contact_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function syncDevices(Request $request): JsonResponse
    {
        $node = $this->resolveNodeFromApiKey($request);

        if (! $node) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized node.',
            ], 401);
        }

        $validated = $request->validate([
            'devices' => ['required', 'array'],
            'devices.*.name' => ['required', 'string', 'max:255'],
            'devices.*.platform' => ['required', 'string', 'max:50'],
            'devices.*.device_uuid' => ['required', 'string', 'max:191'],
            'devices.*.adb_serial' => ['nullable', 'string', 'max:191'],
            'devices.*.status' => ['nullable', 'string', 'in:offline,online,busy,error'],
            'devices.*.settings_json' => ['nullable', 'array'],
        ]);

        $synced = 0;
        $deviceUuids = [];

        foreach ($validated['devices'] as $devicePayload) {
            $device = Device::query()->updateOrCreate(
                [
                    'device_uuid' => $devicePayload['device_uuid'],
                ],
                [
                    'network_node_id' => $node->id,
                    'name' => $devicePayload['name'],
                    'platform' => $devicePayload['platform'],
                    'adb_serial' => $devicePayload['adb_serial'] ?? null,
                    'status' => $devicePayload['status'] ?? 'online',
                    'last_seen_at' => now(),
                    'settings_json' => $devicePayload['settings_json'] ?? [],
                ]
            );

            $deviceUuids[] = $device->device_uuid;
            $synced++;
        }

        if ($deviceUuids !== []) {
            Device::query()
                ->where('network_node_id', $node->id)
                ->whereNotIn('device_uuid', $deviceUuids)
                ->update([
                    'status' => 'offline',
                ]);
        }

        return response()->json([
            'success' => true,
            'synced_count' => $synced,
        ]);
    }

    public function pullJobs(Request $request): JsonResponse
    {
        $node = $this->resolveNodeFromApiKey($request);

        if (! $node) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized node.',
            ], 401);
        }

        $validated = $request->validate([
            'protocol_version' => ['nullable', 'integer', 'min:1', 'max:2'],
            'resume_job_uuids' => ['nullable', 'array', 'max:10'],
            'resume_job_uuids.*' => ['string', 'max:120'],
        ]);
        $protocolVersion = (int) ($validated['protocol_version'] ?? 1);
        $resumeJobUuids = $validated['resume_job_uuids'] ?? [];

        $jobs = DB::transaction(function () use ($node, $protocolVersion, $resumeJobUuids) {
            $jobs = collect();

            if ($protocolVersion >= 2 && $resumeJobUuids !== []) {
                $resumeJob = NetworkJob::query()
                    ->with('device')
                    ->where('network_node_id', $node->id)
                    ->whereIn('job_uuid', $resumeJobUuids)
                    ->whereIn('status', ['dispatched', 'stop_requested', 'unreachable'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($resumeJob) {
                    $jobs = collect([$resumeJob]);
                }
            }

            if ($protocolVersion >= 2 && NetworkJob::query()
                ->where('network_node_id', $node->id)
                ->whereIn('type', ['workflow_task', 'workflow_run'])
                ->whereIn('status', ['dispatched', 'stop_requested'])
                ->where('lease_expires_at', '>', now())
                ->when($jobs->isNotEmpty(), fn ($query) => $query->whereKeyNot($jobs->first()->id))
                ->exists()) {
                return collect();
            }

            if ($jobs->isEmpty()) {
                $jobs = NetworkJob::query()
                    ->with('device')
                    ->where('network_node_id', $node->id)
                    ->where('status', 'pending')
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now())
                            ->orWhereIn('type', ['workflow_task', 'workflow_run']);
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit(1)
                    ->get();
            }

            foreach ($jobs as $job) {
                $leaseToken = $protocolVersion >= 2 ? Str::random(64) : null;
                $job->forceFill([
                    'status' => $job->status === 'stop_requested' ? 'stop_requested' : 'dispatched',
                    'dispatched_at' => now(),
                    'lease_token_hash' => $leaseToken ? hash('sha256', $leaseToken) : null,
                    'lease_expires_at' => $leaseToken ? now()->addSeconds(120) : null,
                    'attempt_count' => ((int) $job->attempt_count) + 1,
                ])->save();
                $job->setAttribute('issued_lease_token', $leaseToken);

                if ($job->type === 'node_update') {
                    $node->update([
                        'update_status' => 'installing',
                        'update_error' => null,
                    ]);
                }
            }

            return $jobs;
        });

        return response()->json([
            'success' => true,
            'jobs' => $jobs->map(fn (NetworkJob $job): array => [
                'job_uuid' => $job->job_uuid,
                'type' => $job->type,
                'payload' => $job->payload_json,
                'payload_version' => max(1, (int) $job->payload_version),
                'signature' => $job->signature,
                'lease_token' => $job->getAttribute('issued_lease_token'),
                'lease_expires_at' => optional($job->lease_expires_at)?->toIso8601String(),
                'expires_at' => optional($job->expires_at)?->toIso8601String(),
                'device_uuid' => $job->device?->device_uuid,
                'execution_scope' => $job->device_id ? 'device' : 'node',
                'control' => $this->jobControlResponse($job),
            ])->values(),
        ]);
    }

    public function reportJobResult(Request $request): JsonResponse
    {
        $node = $this->resolveNodeFromApiKey($request);

        if (! $node) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized node.',
            ], 401);
        }

        $validated = $request->validate([
            'job_uuid' => ['required', 'string', 'max:120'],
            'status' => ['required', 'string', 'in:success,failed,cancelled,timed_out'],
            'result' => ['nullable', 'array'],
            'error_message' => ['nullable', 'string'],
            'lease_token' => ['nullable', 'string', 'max:255'],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        $job = NetworkJob::query()
            ->where('job_uuid', $validated['job_uuid'])
            ->where('network_node_id', $node->id)
            ->first();

        if (! $job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found for this node.',
            ], 404);
        }

        if (! $this->validLease($request, $job)) {
            return response()->json(['success' => false, 'message' => 'Invalid or missing job lease.'], 409);
        }

        if (in_array($job->status, ['success', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            return response()->json([
                'success' => true,
                'ignored' => true,
                'acknowledged_sequence' => (int) $job->last_sequence,
            ]);
        }

        $requestedSequence = (int) ($validated['sequence'] ?? 0);

        if ($requestedSequence > 0 && $requestedSequence <= (int) $job->last_sequence) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'acknowledged_sequence' => (int) $job->last_sequence,
                'control' => $this->jobControlResponse($job),
            ]);
        }

        $sequence = $requestedSequence > 0 ? $requestedSequence : ((int) $job->last_sequence) + 1;

        $result = $this->attachStoredLivePreview($job, $validated['result'] ?? []);
        $workflowService = app(WorkflowExecutionService::class);

        foreach ([
            'remoteWebmailSessionPayload' => 'encryptedSessionPayload',
            'remoteBrowserSessionPayload' => 'encryptedBrowserSessionPayload',
        ] as $plainKey => $encryptedKey) {
            $plainPayload = $result[$plainKey] ?? null;

            if (is_string($plainPayload) && $plainPayload !== '') {
                $result[$encryptedKey] = Crypt::encryptString($plainPayload);
            }

            unset($result[$plainKey]);
        }

        if ($job->type === 'workflow_run'
            && ! $workflowService->isAuthoritativeClientWorkflowResult($result, $validated['status'])) {
            $job->forceFill([
                'status' => $job->status === 'unreachable' ? 'dispatched' : $job->status,
                'result_json' => $result,
                'last_progress_at' => now(),
                'last_sequence' => $sequence,
                'lease_expires_at' => $job->lease_token_hash ? now()->addSeconds(120) : null,
                'unreachable_at' => null,
                'control_acknowledged_at' => $job->control_command ? now() : $job->control_acknowledged_at,
            ])->save();

            NetworkJobProgressEvent::query()->firstOrCreate(
                ['network_job_id' => $job->id, 'sequence' => $sequence],
                [
                    'kind' => 'progress',
                    'payload_json' => $this->compactProgressEvent($result),
                    'screenshot_relative_path' => data_get($result, 'livePreviewRelativePath'),
                    'received_at' => now(),
                ],
            );

            $workflowService->applyClientWorkflowProgress($job, $result);

            $node->forceFill([
                'is_online' => true,
                'last_seen_at' => now(),
            ])->save();

            return response()->json([
                'success' => true,
                'partial' => true,
                'acknowledged_sequence' => $sequence,
                'control' => $this->jobControlResponse($job),
            ]);
        }

        $job->forceFill([
            'status' => $validated['status'],
            'result_json' => $result,
            'error_message' => $validated['error_message'] ?? null,
            'completed_at' => now(),
            'last_progress_at' => now(),
            'last_sequence' => $sequence,
            'lease_expires_at' => null,
            'control_acknowledged_at' => $job->control_command ? now() : $job->control_acknowledged_at,
        ])->save();

        NetworkJobProgressEvent::query()->firstOrCreate(
            ['network_job_id' => $job->id, 'sequence' => $sequence],
            ['kind' => 'result', 'payload_json' => $result, 'received_at' => now()],
        );

        if ($job->type === 'node_update') {
            if ($validated['status'] === 'success') {
                $node->update([
                    'update_status' => 'awaiting_restart',
                    'update_error' => null,
                ]);
                $this->reconcileNodeUpdate($node->fresh());
            } else {
                $node->update([
                    'update_status' => 'failed',
                    'update_error' => $validated['error_message'] ?? data_get($result, 'statusMessage', 'Update fehlgeschlagen.'),
                ]);
            }
        }

        $stepRun = WorkflowStepRun::query()
            ->where('external_run_type', 'client-controller-workflow-task')
            ->where('external_run_id', $job->job_uuid)
            ->first();

        if ($stepRun) {
            $this->syncWorkflowStepSnapshot($stepRun, $result);
            MonitorWorkflowStepRunJob::dispatch($stepRun->id);
        }

        if ($job->type === 'workflow_run') {
            $workflowService->completeClientWorkflowRun(
                $job,
                $result,
                $validated['status'],
                $validated['error_message'] ?? null,
            );
        }

        return response()->json([
            'success' => true,
            'acknowledged_sequence' => $sequence,
        ]);
    }

    public function reportJobProgress(Request $request): JsonResponse
    {
        $node = $this->resolveNodeFromApiKey($request);

        if (! $node) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized node.',
            ], 401);
        }

        $validated = $request->validate([
            'job_uuid' => ['required', 'string', 'max:120'],
            'progress' => ['required', 'string', 'max:5242880'],
            'screenshot' => ['nullable', 'file', 'mimes:png', 'max:10240'],
            'lease_token' => ['nullable', 'string', 'max:255'],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        $progress = json_decode($validated['progress'], true);

        if (! is_array($progress)) {
            return response()->json([
                'success' => false,
                'message' => 'Progress must be a JSON object.',
            ], 422);
        }

        $job = NetworkJob::query()
            ->where('job_uuid', $validated['job_uuid'])
            ->where('network_node_id', $node->id)
            ->first();

        if (! $job) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found for this node.',
            ], 404);
        }

        if (! $this->validLease($request, $job)) {
            return response()->json(['success' => false, 'message' => 'Invalid or missing job lease.'], 409);
        }

        if (! in_array($job->status, ['dispatched', 'stop_requested', 'unreachable'], true)) {
            return response()->json([
                'success' => true,
                'ignored' => true,
                'message' => 'Job is no longer running.',
                'acknowledged_sequence' => (int) $job->last_sequence,
                'control' => $this->jobControlResponse($job),
            ]);
        }

        $requestedSequence = (int) ($validated['sequence'] ?? 0);

        if ($requestedSequence > 0 && $requestedSequence <= (int) $job->last_sequence) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'acknowledged_sequence' => (int) $job->last_sequence,
            ]);
        }

        $sequence = $requestedSequence > 0 ? $requestedSequence : ((int) $job->last_sequence) + 1;
        $existingEvent = NetworkJobProgressEvent::query()
            ->where('network_job_id', $job->id)
            ->where('sequence', $sequence)
            ->first();

        if ($existingEvent) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'acknowledged_sequence' => $sequence,
                'control' => $this->jobControlResponse($job),
            ]);
        }

        if ($request->hasFile('screenshot')) {
            $relativePath = $this->livePreviewRelativePath($job);
            $request->file('screenshot')->storeAs(
                dirname($relativePath),
                basename($relativePath),
                'public',
            );
        }

        $progress = $this->attachStoredLivePreview($job, $progress);
        $progress['clientControllerReportedAt'] = now()->toIso8601String();

        $controlAcknowledged = $job->control_command !== null && (
            (string) ($progress['controlAcknowledged'] ?? '') === (string) $job->control_command
            || in_array((string) ($progress['state'] ?? ''), ['stopping', 'cancelled', 'timed_out'], true)
        );

        $job->forceFill([
            'status' => $job->status === 'unreachable' ? 'dispatched' : $job->status,
            'result_json' => $progress,
            'last_progress_at' => now(),
            'last_sequence' => $sequence,
            'lease_expires_at' => $job->lease_token_hash ? now()->addSeconds(120) : null,
            'unreachable_at' => null,
            'control_acknowledged_at' => $controlAcknowledged ? now() : $job->control_acknowledged_at,
        ])->save();

        NetworkJobProgressEvent::query()->create([
            'network_job_id' => $job->id,
            'sequence' => $sequence,
            'kind' => 'progress',
            'payload_json' => $this->compactProgressEvent($progress),
            'screenshot_relative_path' => data_get($progress, 'livePreviewRelativePath'),
            'received_at' => now(),
        ]);

        $stepRun = WorkflowStepRun::query()
            ->where('external_run_type', 'client-controller-workflow-task')
            ->where('external_run_id', $job->job_uuid)
            ->first();

        if ($stepRun) {
            $this->syncWorkflowStepSnapshot($stepRun, $progress);
        }

        if ($job->type === 'workflow_run') {
            app(WorkflowExecutionService::class)->applyClientWorkflowProgress($job, $progress);
        }

        $node->forceFill([
            'is_online' => true,
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'acknowledged_sequence' => $sequence,
            'control' => $this->jobControlResponse($job),
        ]);
    }

    public function rebind(Request $request): JsonResponse
    {
        $node = $this->resolveNodeFromApiKey($request);

        if (! $node) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized node.',
            ], 401);
        }

        $validated = $request->validate([
            'new_server_domain' => ['required', 'string', 'max:255'],
            'expires_at' => ['required', 'date'],
            'signature' => ['required', 'string'],
            'requested_by' => ['nullable', 'string', 'max:255'],
            'force' => ['nullable', 'boolean'],
        ]);

        if (! $node->allow_server_rebind && ! ($validated['force'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Server rebind is disabled for this node.',
            ], 422);
        }

        if (now()->greaterThan($validated['expires_at'])) {
            NodeRebindLog::query()->create([
                'network_node_id' => $node->id,
                'old_server_domain' => $node->current_server_domain,
                'new_server_domain' => $validated['new_server_domain'],
                'status' => 'failed',
                'requested_by' => $validated['requested_by'] ?? 'system',
                'requested_at' => now(),
                'completed_at' => now(),
                'error_message' => 'Rebind request expired.',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Rebind request expired.',
            ], 422);
        }

        NodeRebindLog::query()->create([
            'network_node_id' => $node->id,
            'old_server_domain' => $node->current_server_domain,
            'new_server_domain' => $validated['new_server_domain'],
            'status' => 'completed',
            'requested_by' => $validated['requested_by'] ?? 'system',
            'requested_at' => now(),
            'completed_at' => now(),
        ]);

        $node->update([
            'last_successful_server_domain' => $node->current_server_domain,
            'current_server_domain' => $validated['new_server_domain'],
        ]);

        NodeServerBinding::query()->create([
            'network_node_id' => $node->id,
            'server_domain' => $validated['new_server_domain'],
            'status' => 'bound',
            'bound_at' => now(),
            'last_successful_contact_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'current_server_domain' => $node->current_server_domain,
            'last_successful_server_domain' => $node->last_successful_server_domain,
        ]);
    }

    protected function resolveNodeFromApiKey(Request $request): ?NetworkNode
    {
        $apiKey = trim((string) $request->header('X-NODE-API-KEY', $request->input('api_key', '')));

        if ($apiKey === '') {
            return null;
        }

        return NetworkNode::query()
            ->where('api_key', $apiKey)
            ->first();
    }

    protected function validLease(Request $request, NetworkJob $job): bool
    {
        $expectedHash = trim((string) $job->lease_token_hash);

        if ($expectedHash === '') {
            return true;
        }

        $leaseToken = trim((string) $request->input('lease_token', $request->header('X-JOB-LEASE-TOKEN', '')));

        return $leaseToken !== '' && hash_equals($expectedHash, hash('sha256', $leaseToken));
    }

    protected function jobControlResponse(NetworkJob $job): ?array
    {
        if ($job->control_command === null || $job->control_acknowledged_at !== null) {
            return null;
        }

        return [
            'command' => $job->control_command,
            'sequence' => (int) $job->control_sequence,
            'payload' => is_array($job->control_payload_json) ? $job->control_payload_json : [],
            'requested_at' => optional($job->control_requested_at)?->toIso8601String(),
            'deadline_at' => optional($job->control_deadline_at)?->toIso8601String(),
        ];
    }

    protected function syncWorkflowStepSnapshot(WorkflowStepRun $stepRun, array $snapshot): void
    {
        if (! in_array($stepRun->status, ['running', 'waiting'], true)) {
            return;
        }

        $events = collect(data_get($snapshot, 'events', []))
            ->filter(fn (mixed $event): bool => is_array($event))
            ->values()
            ->all();

        $stepRun->forceFill([
            'status' => 'waiting',
            'result_json' => $snapshot,
            'logs_json' => $events,
        ])->save();
    }

    protected function compactProgressEvent(array $progress): array
    {
        $events = is_array($progress['events'] ?? null) ? $progress['events'] : [];
        $latestEvent = $events !== [] ? end($events) : null;

        return array_filter([
            'state' => $progress['state'] ?? null,
            'stage' => $progress['stage'] ?? null,
            'message' => $progress['message'] ?? $progress['statusMessage'] ?? null,
            'currentStepId' => $progress['currentStepId'] ?? $progress['workflowStepId'] ?? null,
            'currentStepRunId' => $progress['currentStepRunId'] ?? $progress['workflowStepRunId'] ?? null,
            'currentTaskKey' => $progress['currentTaskKey'] ?? data_get($latestEvent, 'taskKey'),
            'latestEvent' => is_array($latestEvent) ? $latestEvent : null,
            'reportedAt' => $progress['clientControllerReportedAt'] ?? $progress['at'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function attachStoredLivePreview(NetworkJob $job, array $payload): array
    {
        $relativePath = $this->livePreviewRelativePath($job);

        if (! Storage::disk('public')->exists($relativePath)) {
            return $payload;
        }

        $payload['livePreviewRelativePath'] = $relativePath;

        if (isset($payload['browserWindows']) && is_array($payload['browserWindows'])) {
            foreach ($payload['browserWindows'] as &$window) {
                if (is_array($window)) {
                    $window['livePreviewRelativePath'] = $relativePath;
                }
            }
            unset($window);
        }

        return $payload;
    }

    protected function livePreviewRelativePath(NetworkJob $job): string
    {
        return 'workflow-task-runs/client-controller/'.$job->job_uuid.'/live.png';
    }

    protected function reconcileNodeUpdate(NetworkNode $node): void
    {
        $target = app(ClientControllerReleaseService::class)->normalizeVersion((string) $node->update_target_version);
        $installed = app(ClientControllerReleaseService::class)->normalizeVersion((string) $node->version);

        if ($target === '' || $installed === '' || version_compare($installed, $target, '<')) {
            return;
        }

        $node->update([
            'update_status' => 'installed',
            'update_installed_at' => now(),
            'update_error' => null,
        ]);

        $node->jobs()
            ->where('type', 'node_update')
            ->whereIn('status', ['pending', 'dispatched'])
            ->get()
            ->filter(fn (NetworkJob $job): bool => app(ClientControllerReleaseService::class)->normalizeVersion((string) data_get($job->payload_json, 'target_version')) === $target)
            ->each(fn (NetworkJob $job) => $job->update([
                'status' => 'success',
                'completed_at' => now(),
                'result_json' => [
                    'installed_version' => $installed,
                    'confirmed_by' => 'node_heartbeat',
                ],
                'error_message' => null,
            ]));
    }
}
