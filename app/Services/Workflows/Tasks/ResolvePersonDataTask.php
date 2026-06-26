<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;

class ResolvePersonDataTask
{
    public function handle(mixed $subject = null, array $context = []): array
    {
        $person = $this->resolvePerson($subject, $context);

        if (! $person) {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Keine Person fuer den Workflow-Task gefunden.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Person-Daten wurden ermittelt.',
            'person' => [
                'id' => $person->id,
                'profileKey' => $person->profile_key,
                'displayName' => $person->display_name,
                'firstName' => $person->person_first_name,
                'lastName' => $person->person_last_name,
                'alias' => $person->person_alias,
                'email' => $person->person_email,
                'phone' => $person->person_phone,
                'country' => $person->person_country,
                'city' => $person->person_city,
                'timezone' => $person->person_timezone,
                'loginUsername' => $person->login_username,
            ],
        ];
    }

    protected function resolvePerson(mixed $subject, array $context): ?Person
    {
        if ($subject instanceof Person) {
            return $subject;
        }

        if (is_array($subject) && (($subject['person'] ?? null) instanceof Person)) {
            return $subject['person'];
        }

        $personId = is_array($subject)
            ? ($subject['person_id'] ?? $subject['personId'] ?? null)
            : null;

        $personId ??= $context['person_id'] ?? $context['personId'] ?? null;

        return $personId ? Person::query()->find((int) $personId) : null;
    }
}
