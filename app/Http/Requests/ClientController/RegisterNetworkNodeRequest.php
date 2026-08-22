<?php

namespace App\Http\Requests\ClientController;

use App\Services\ClientController\NodeCredentialAuditService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RegisterNetworkNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Die eigentliche Autorisierung erfolgt atomar ueber das einmalige,
        // gehashte Enrollment-Token im NodeEnrollmentService.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'node_uuid' => ['required', 'string', 'max:36'],
            'version' => ['nullable', 'string', 'max:120'],
            'os' => ['nullable', 'string', 'max:120'],
            'public_ip' => ['nullable', 'ip', 'max:64'],
            'country' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'current_server_domain' => ['nullable', 'url', 'max:191'],
            'last_successful_server_domain' => ['nullable', 'url', 'max:191'],
            'capabilities' => ['nullable', 'array:android,adb,adb_device_discovery,remote_network,screenshots,browser,cloakbrowser,workflow_tasks,workflow_bundle_v1,job_protocol_version,node_execution,appium,server_rebind,auto_update'],
            'capabilities.android' => ['sometimes', 'boolean'],
            'capabilities.adb' => ['sometimes', 'boolean'],
            'capabilities.adb_device_discovery' => ['sometimes', 'boolean'],
            'capabilities.remote_network' => ['sometimes', 'boolean'],
            'capabilities.screenshots' => ['sometimes', 'boolean'],
            'capabilities.browser' => ['sometimes', 'boolean'],
            'capabilities.cloakbrowser' => ['sometimes', 'boolean'],
            'capabilities.workflow_tasks' => ['sometimes', 'boolean'],
            'capabilities.workflow_bundle_v1' => ['sometimes', 'boolean'],
            'capabilities.job_protocol_version' => ['sometimes', 'integer', 'min:1', 'max:2'],
            'capabilities.node_execution' => ['sometimes', 'boolean'],
            'capabilities.appium' => ['sometimes', 'boolean'],
            'capabilities.server_rebind' => ['sometimes', 'boolean'],
            'capabilities.auto_update' => ['sometimes', 'boolean'],

            'bootstrap_api_key' => ['prohibited'],
            'api_key' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = [
                'name',
                'node_uuid',
                'version',
                'os',
                'public_ip',
                'country',
                'city',
                'current_server_domain',
                'last_successful_server_domain',
                'capabilities',
                'bootstrap_api_key',
                'api_key',
            ];

            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add((string) $key, 'Dieses Feld ist fuer das Node-Enrollment nicht erlaubt.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if (Schema::hasTable('node_credential_events')) {
            try {
                app(NodeCredentialAuditService::class)->record(
                    eventType: 'enrollment.rejected',
                    outcome: 'rejected',
                    request: $this,
                    nodeUuid: $this->auditNodeUuid(),
                    metadata: ['reason' => 'request_validation_failed'],
                );
            } catch (Throwable) {
                // Audit-Telemetrie darf die fail-closed Validierungsantwort
                // weder verhindern noch interne Fehlerdetails offenlegen.
            }
        }

        parent::failedValidation($validator);
    }

    private function auditNodeUuid(): ?string
    {
        $nodeUuid = $this->input('node_uuid');

        if (! is_scalar($nodeUuid)) {
            return null;
        }

        $nodeUuid = trim((string) $nodeUuid);

        return $nodeUuid === '' ? null : mb_substr($nodeUuid, 0, 120);
    }
}
