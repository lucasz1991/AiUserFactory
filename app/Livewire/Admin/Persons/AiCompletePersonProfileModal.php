<?php

namespace App\Livewire\Admin\Persons;

use App\Models\Person;
use App\Services\Ai\AiConnectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class AiCompletePersonProfileModal extends Component
{
    public bool $showModal = false;

    public ?int $personId = null;

    public ?Person $person = null;

    public bool $isGenerating = false;

    public array $preview = [];

    public string $profilePrompt = '';

    public array $allowedRootFields = [
        'person_first_name',
        'person_last_name',
        'person_alias',
        'person_date_of_birth',
        'person_gender',
        'person_email',
        'person_phone',
        'person_timezone',
        'person_address_line1',
        'person_address_line2',
        'person_postal_code',
        'person_city',
        'person_state',
        'person_country',
        'person_notes',
    ];

    public array $allowedIdentityFields = [
        'nationality',
        'occupation',
        'relationship_status',
        'physical_appearance',
        'languages',
        'interests',
        'personality_traits',
        'values',
        'daily_routine',
        'background_story',
    ];

    public array $allowedBotFields = [
        'communication_style',
        'writing_style',
        'behavior_guidelines',
    ];

    #[On('open-ai-complete-person-profile')]
    public function open(int $personId): void
    {
        $this->personId = $personId;
        $this->person = Person::query()->findOrFail($personId);
        $this->preview = $this->buildEditablePreview();
        $this->profilePrompt = '';
        $this->showModal = true;
    }

    public function close(): void
    {
        $this->reset([
            'showModal',
            'personId',
            'person',
            'isGenerating',
            'preview',
            'profilePrompt',
        ]);
    }

    public function openImageModal(): void
    {
        if (! $this->person) {
            return;
        }

        $this->dispatch('open-person-image-modal', personId: (int) $this->person->id);
    }

    public function generate(AiConnectionService $ai): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'profilePrompt' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->isGenerating = true;

        try {
            $result = $ai->json(
                prompt: json_encode($this->buildAiContext($validated['profilePrompt'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                system: $this->systemPrompt(),
                options: [
                    'temperature' => 0.7,
                    'max_completion_tokens' => 3000,
                ]
            );

            $this->preview = $this->sanitizeAiResult($result);

            session()->flash('success', 'AI-Vorschlag wurde erstellt. Bitte pruefen und speichern.');
        } catch (Throwable $exception) {
            Log::error('AI person completion failed', [
                'person_id' => $this->personId,
                'message' => $exception->getMessage(),
            ]);

            session()->flash('error', $exception->getMessage());
        } finally {
            $this->isGenerating = false;
        }
    }

    public function save(): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'preview.root.person_first_name' => ['nullable', 'string', 'max:120'],
            'preview.root.person_last_name' => ['nullable', 'string', 'max:120'],
            'preview.root.person_alias' => ['nullable', 'string', 'max:120'],
            'preview.root.person_date_of_birth' => ['nullable', 'date', 'before:today'],
            'preview.root.person_gender' => ['nullable', 'string', 'max:60'],
            'preview.root.person_email' => ['nullable', 'email', 'max:255'],
            'preview.root.person_phone' => ['nullable', 'string', 'max:80'],
            'preview.root.person_timezone' => ['nullable', 'string', 'max:80'],
            'preview.root.person_address_line1' => ['nullable', 'string', 'max:255'],
            'preview.root.person_address_line2' => ['nullable', 'string', 'max:255'],
            'preview.root.person_postal_code' => ['nullable', 'string', 'max:40'],
            'preview.root.person_city' => ['nullable', 'string', 'max:120'],
            'preview.root.person_state' => ['nullable', 'string', 'max:120'],
            'preview.root.person_country' => ['nullable', 'string', 'max:120'],
            'preview.root.person_notes' => ['nullable', 'string', 'max:12000'],

            'preview.identity_profile.nationality' => ['nullable', 'string', 'max:120'],
            'preview.identity_profile.occupation' => ['nullable', 'string', 'max:255'],
            'preview.identity_profile.relationship_status' => ['nullable', 'string', 'max:120'],
            'preview.identity_profile.physical_appearance' => ['nullable', 'string', 'max:6000'],
            'preview.identity_profile.languages' => ['nullable', 'string', 'max:2000'],
            'preview.identity_profile.interests' => ['nullable', 'string', 'max:4000'],
            'preview.identity_profile.personality_traits' => ['nullable', 'string', 'max:4000'],
            'preview.identity_profile.values' => ['nullable', 'string', 'max:4000'],
            'preview.identity_profile.daily_routine' => ['nullable', 'string', 'max:8000'],
            'preview.identity_profile.background_story' => ['nullable', 'string', 'max:20000'],

            'preview.bot_profile.communication_style' => ['nullable', 'string', 'max:4000'],
            'preview.bot_profile.writing_style' => ['nullable', 'string', 'max:4000'],
            'preview.bot_profile.behavior_guidelines' => ['nullable', 'string', 'max:12000'],
        ]);

        $root = Arr::only($validated['preview']['root'] ?? [], $this->allowedRootFields);
        $root['person_date_of_birth'] = $this->nullableString($root['person_date_of_birth'] ?? null);

        $identityProfile = is_array($this->person->identity_profile)
            ? $this->person->identity_profile
            : [];

        $botProfile = is_array($this->person->bot_profile)
            ? $this->person->bot_profile
            : [];

        foreach ($this->allowedIdentityFields as $field) {
            $value = $validated['preview']['identity_profile'][$field] ?? null;

            $identityProfile[$field] = in_array($field, [
                'languages',
                'interests',
                'personality_traits',
                'values',
            ], true)
                ? $this->splitValues((string) $value)
                : $this->nullableString($value);
        }

        foreach ($this->allowedBotFields as $field) {
            $botProfile[$field] = $this->nullableString(
                $validated['preview']['bot_profile'][$field] ?? null
            );
        }

        $this->person->forceFill([
            ...$root,
            'identity_profile' => $identityProfile,
            'bot_profile' => $botProfile,
        ])->save();

        $this->dispatch('refreshPersonDetail');

        session()->flash('success', 'Personendaten wurden mit AI-Daten komplettiert.');

        $this->close();
    }

    protected function buildEditablePreview(): array
    {
        $identityProfile = is_array($this->person->identity_profile)
            ? $this->person->identity_profile
            : [];

        $botProfile = is_array($this->person->bot_profile)
            ? $this->person->bot_profile
            : [];

        return [
            'root' => collect($this->allowedRootFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $field === 'person_date_of_birth'
                        ? ($this->person->person_date_of_birth?->format('Y-m-d') ?? '')
                        : (string) ($this->person->{$field} ?? ''),
                ])
                ->toArray(),

            'identity_profile' => [
                'nationality' => (string) ($identityProfile['nationality'] ?? ''),
                'occupation' => (string) ($identityProfile['occupation'] ?? ''),
                'relationship_status' => (string) ($identityProfile['relationship_status'] ?? ''),
                'physical_appearance' => (string) ($identityProfile['physical_appearance'] ?? ''),
                'languages' => implode(PHP_EOL, $this->normalizeList($identityProfile['languages'] ?? [])),
                'interests' => implode(PHP_EOL, $this->normalizeList($identityProfile['interests'] ?? [])),
                'personality_traits' => implode(PHP_EOL, $this->normalizeList($identityProfile['personality_traits'] ?? [])),
                'values' => implode(PHP_EOL, $this->normalizeList($identityProfile['values'] ?? [])),
                'daily_routine' => (string) ($identityProfile['daily_routine'] ?? ''),
                'background_story' => (string) ($identityProfile['background_story'] ?? ''),
            ],

            'bot_profile' => [
                'communication_style' => (string) ($botProfile['communication_style'] ?? ''),
                'writing_style' => (string) ($botProfile['writing_style'] ?? ''),
                'behavior_guidelines' => (string) ($botProfile['behavior_guidelines'] ?? ''),
            ],
        ];
    }

    protected function buildAiContext(string $profilePrompt = ''): array
    {
        $dateOfBirth = (string) data_get($this->preview, 'root.person_date_of_birth', '');
        $age = $dateOfBirth !== ''
            ? $this->ageLabelFromDate($dateOfBirth)
            : '';

        return [
            'task' => 'Komplettiere und verbessere editierbare Textfelder einer fiktiven Persona realistisch und konsistent.',
            'user_prompt' => trim($profilePrompt),
            'current_age_from_birthdate' => $age,
            'existing_person_data' => $this->preview ?: $this->buildEditablePreview(),
            'simulation_goal' => 'Die Daten werden spaeter fuer eine isolierte interne Persona-Sandbox genutzt. Besonders wichtig sind plausible Tagesrhythmen, Interessen, Content-Themen, Kommunikationsstil und Grenzen fuer interne Feed-/Session-Schritte. Keine reale Plattform-Automation planen.',
            'allowed_fields_only' => [
                'root' => $this->allowedRootFields,
                'identity_profile' => $this->allowedIdentityFields,
                'bot_profile' => $this->allowedBotFields,
            ],
            'strict_exclusions' => [
                'instagram',
                'instagram_username',
                'instagram_password',
                'instagram_profile_url',
                'social_accounts',
                'login_username',
                'password',
                'cookies',
                'session',
                'browser_profile_path',
                'cookie_file_path',
                'avatar_path',
                'profile_image',
                'files',
                'images',
                'uploads',
            ],
        ];
    }

    protected function systemPrompt(): string
    {
        return <<<PROMPT
Du bist ein Datenassistent fuer eine interne Persona-Verwaltung.

Telefonnummern und Adressen muessen offensichtlich realistisch sein.

Du darfst ausschliesslich diese JSON-Struktur zurueckgeben:

{
  "root": {
    "person_first_name": "",
    "person_last_name": "",
    "person_alias": "",
    "person_date_of_birth": "",
    "person_gender": "",
    "person_email": "",
    "person_phone": "",
    "person_timezone": "",
    "person_address_line1": "",
    "person_address_line2": "",
    "person_postal_code": "",
    "person_city": "",
    "person_state": "",
    "person_country": "",
    "person_notes": ""
  },
  "identity_profile": {
    "nationality": "",
    "occupation": "",
    "relationship_status": "",
    "physical_appearance": "",
    "languages": "",
    "interests": "",
    "personality_traits": "",
    "values": "",
    "daily_routine": "",
    "background_story": ""
  },
  "bot_profile": {
    "communication_style": "",
    "writing_style": "",
    "behavior_guidelines": ""
  }
}

Regeln:
- Antworte nur als valides JSON.
- Keine Markdown-Codebloecke.
- Keine Bilder, Dateien, Uploads oder Pfade.
- Keine Instagram-Daten veraendern, erfinden oder ergaenzen.
- Keine Login-, Cookie-, Session- oder Scraper-Daten.
- Bestehende Werte respektieren, ausser der Nutzerprompt verlangt klar eine Anpassung.
- Leere Textfelder sinnvoll ergaenzen.
- Wenn der Nutzer im Prompt Alter oder Altersbereich vorgibt, gib person_date_of_birth als plausibles Datum im Format YYYY-MM-DD zurueck. Erfinde kein exaktes Geburtsdatum, wenn vorhandene Daten oder Nutzerprompt dagegen sprechen.
- Die optische Beschreibung beschreibt nur sichtbare Merkmale der Person in neutraler Sprache.
- Listenfelder als Zeilenliste ausgeben.
- daily_routine muss konkrete, plausible Zeitfenster fuer Arbeit, Freizeit, Schlaf und kurze Online-/Feed-Momente enthalten.
- interests, personality_traits und values muessen genug Material fuer wiederkehrende interne Content-Themen und Sessions liefern.
- communication_style, writing_style und behavior_guidelines duerfen nur interne Sandbox-Interaktionen beschreiben, keine echte Plattform-Automation, keine Logins, keine Cookies und keine Scraper-Schritte.
PROMPT;
    }

    public function previewAgeLabel(): string
    {
        return $this->ageLabelFromDate((string) data_get($this->preview, 'root.person_date_of_birth', ''));
    }

    protected function ageLabelFromDate(string $date): string
    {
        $date = trim($date);

        if ($date === '') {
            return '';
        }

        try {
            return Carbon::parse($date)->age.' Jahre';
        } catch (Throwable) {
            return '';
        }
    }

    protected function sanitizeAiResult(array $result): array
    {
        return [
            'root' => collect($this->allowedRootFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $this->sanitizeText(
                        data_get($result, "root.{$field}", data_get($this->preview, "root.{$field}", ''))
                    ),
                ])
                ->toArray(),

            'identity_profile' => collect($this->allowedIdentityFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $this->sanitizeText(
                        data_get($result, "identity_profile.{$field}", data_get($this->preview, "identity_profile.{$field}", ''))
                    ),
                ])
                ->toArray(),

            'bot_profile' => collect($this->allowedBotFields)
                ->mapWithKeys(fn (string $field) => [
                    $field => $this->sanitizeText(
                        data_get($result, "bot_profile.{$field}", data_get($this->preview, "bot_profile.{$field}", ''))
                    ),
                ])
                ->toArray(),
        ];
    }

    protected function sanitizeText(mixed $value): string
    {
        if (is_array($value)) {
            return implode(PHP_EOL, $this->normalizeList($value));
        }

        return trim((string) $value);
    }

    protected function splitValues(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            preg_split('/[\r\n,;]+/', $value) ?: []
        )));
    }

    protected function normalizeList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        )));
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function render()
    {
        return view('livewire.admin.persons.ai-complete-person-profile-modal');
    }
}
