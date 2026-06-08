<?php

namespace App\Livewire;

use App\Models\Person;
use App\Services\Ai\AiConnectionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

class AdminConfig extends Component
{
    public array $identitySuggestions = [];

    public bool $isGeneratingIdentitySuggestions = false;

    public function mount(): void
    {
        $this->identitySuggestions = [];
    }

    public function generateIdentitySuggestions(?AiConnectionService $ai = null): void
    {
        $this->isGeneratingIdentitySuggestions = true;

        try {
            $ai ??= app(AiConnectionService::class);

            $result = $ai->json(
                prompt: json_encode($this->identitySuggestionContext(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                system: $this->identitySuggestionSystemPrompt(),
                options: [
                    'temperature' => 0.85,
                    'max_completion_tokens' => 5000,
                ]
            );

            $suggestions = is_array($result['suggestions'] ?? null)
                ? $result['suggestions']
                : (array_is_list($result) ? $result : []);

            $this->identitySuggestions = collect($suggestions)
                ->filter(fn (mixed $suggestion): bool => is_array($suggestion))
                ->map(fn (array $suggestion): array => $this->sanitizeIdentitySuggestion($suggestion))
                ->take(3)
                ->values()
                ->toArray();

            if (count($this->identitySuggestions) < 3) {
                throw new \RuntimeException('AI hat weniger als drei gueltige Vorschlaege geliefert.');
            }
        } catch (Throwable $exception) {
            Log::error('AI identity suggestions failed', [
                'message' => $exception->getMessage(),
            ]);

            $this->identitySuggestions = array_map(
                fn (): array => $this->makeIdentitySuggestion(),
                range(1, 3)
            );

            session()->flash('error', 'AI-Vorschlaege konnten nicht erstellt werden. Es wurden lokale Fallback-Vorschlaege geladen.');
        } finally {
            $this->isGeneratingIdentitySuggestions = false;
        }
    }

    public function saveIdentitySuggestion(int $index): void
    {
        $suggestion = $this->identitySuggestions[$index] ?? null;

        if (! is_array($suggestion)) {
            session()->flash('error', 'Der ausgewaehlte Vorschlag wurde nicht gefunden.');

            return;
        }

        $suggestion = $this->sanitizeIdentitySuggestion($suggestion);
        $profileKey = (string) Str::uuid();
        $handle = trim(ltrim((string) ($suggestion['instagram_handle'] ?? ''), '@'));
        $slug = $this->uniqueProfileSlug($suggestion);

        $person = Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => $profileKey,
            'profile_label' => $suggestion['profile_label'],
            'person_first_name' => $suggestion['first_name'],
            'person_last_name' => $suggestion['last_name'],
            'person_alias' => $suggestion['alias'],
            'person_date_of_birth' => $suggestion['date_of_birth'],
            'person_gender' => $suggestion['gender'],
            'person_email' => $suggestion['email'],
            'person_phone' => $suggestion['phone'],
            'person_address_line1' => $suggestion['address_line1'],
            'person_address_line2' => $suggestion['address_line2'] ?: null,
            'person_postal_code' => $suggestion['postal_code'],
            'person_state' => $suggestion['state'],
            'person_country' => $suggestion['country'],
            'person_city' => $suggestion['city'],
            'person_timezone' => $suggestion['timezone'],
            'person_notes' => $suggestion['bio'],
            'identity_profile' => [
                'name' => trim($suggestion['first_name'].' '.$suggestion['last_name']),
                'alias' => $suggestion['alias'],
                'nationality' => $suggestion['nationality'],
                'occupation' => $suggestion['occupation'],
                'relationship_status' => $suggestion['relationship_status'],
                'languages' => $suggestion['languages'],
                'interests' => $suggestion['interests'],
                'personality_traits' => $suggestion['personality_traits'],
                'values' => $suggestion['values'],
                'physical_appearance' => $suggestion['physical_appearance'],
                'daily_routine' => $suggestion['daily_routine'],
                'background_story' => $suggestion['background_story'],
            ],
            'bot_profile' => [
                'status' => 'manual',
                'prepared_for_automation' => false,
                'communication_style' => $suggestion['communication_style'],
                'writing_style' => $suggestion['writing_style'],
                'behavior_guidelines' => $suggestion['behavior_guidelines'],
            ],
            'bot_status' => 'manual',
            'social_accounts' => $handle !== '' ? [
                'instagram' => [
                    'platform' => 'instagram',
                    'username' => $handle,
                    'handle' => '@'.$handle,
                    'managed' => false,
                    'login_enabled' => false,
                ],
            ] : [],
            'browser_profile_path' => 'browser-profiles/instagram/'.$slug,
            'cookie_file_path' => 'cookies/'.$slug.'-cookies.json',
            'persistent_profile_enabled' => true,
            'headless_enabled' => true,
            'auto_login_enabled' => false,
            'login_username' => $handle,
            'is_primary' => ! Person::query()->where('platform', 'instagram')->where('is_primary', true)->exists(),
            'is_active' => true,
            'sort_order' => ((int) Person::query()->where('platform', 'instagram')->max('sort_order')) + 1,
            'metadata' => [
                'created_from' => 'ai_identity_suggestion',
                'created_from_suggestion_at' => now()->toIso8601String(),
            ],
        ]);

        unset($this->identitySuggestions[$index]);
        $this->identitySuggestions = array_values($this->identitySuggestions);

        session()->flash('success', 'AI-Vorschlag wurde als Person gespeichert.');
        $this->redirectRoute('persons.show', ['profileId' => $person->profile_key], navigate: true);
    }

    public function render()
    {
        return view('livewire.admin-config')->layout('layouts.master');
    }

    private function identitySuggestionContext(): array
    {
        return [
            'task' => 'Erstelle genau drei fiktive, realistisch wirkende Testpersonen fuer eine interne Personen-Factory.',
            'locale' => 'Deutschland',
            'constraints' => [
                'Alle Personen muessen fiktiv sein.',
                'E-Mail-Adressen muessen die reservierte Domain example.com verwenden.',
                'Telefonnummern muessen erkennbare Platzhalter sein, z. B. +49 30 0000 xxxx.',
                'Keine echten Social-Media-Konten, keine echten Prominenten, keine realen Personen imitieren.',
                'Instagram-Handles sind nur fiktive Platzhalter.',
                'Geburtsdaten fuer Erwachsene zwischen 21 und 45 Jahren.',
            ],
            'required_json_shape' => $this->identitySuggestionShape(),
        ];
    }

    private function identitySuggestionSystemPrompt(): string
    {
        return <<<'PROMPT'
Du bist ein Datenassistent fuer eine interne Testprofil-Verwaltung.

Erzeuge ausschliesslich fiktive Personen. Keine echten Personen, keine Prominenten, keine Imitationen, keine echten Konten.
Antworte nur als valides JSON, ohne Markdown.

Gib genau diese Struktur zurueck:
{
  "suggestions": [
    {
      "first_name": "",
      "last_name": "",
      "alias": "",
      "date_of_birth": "YYYY-MM-DD",
      "gender": "",
      "email": "",
      "phone": "",
      "address_line1": "",
      "address_line2": "",
      "postal_code": "",
      "city": "",
      "state": "",
      "country": "Deutschland",
      "timezone": "Europe/Berlin",
      "instagram_handle": "",
      "profile_label": "",
      "bio": "",
      "nationality": "",
      "occupation": "",
      "relationship_status": "",
      "languages": [],
      "interests": [],
      "personality_traits": [],
      "values": [],
      "physical_appearance": "",
      "daily_routine": "",
      "background_story": "",
      "communication_style": "",
      "writing_style": "",
      "behavior_guidelines": ""
    }
  ]
}
PROMPT;
    }

    private function identitySuggestionShape(): array
    {
        return [
            'suggestions' => [
                [
                    'first_name' => 'string',
                    'last_name' => 'string',
                    'alias' => 'string',
                    'date_of_birth' => 'YYYY-MM-DD',
                    'gender' => 'female|male|diverse',
                    'email' => 'reserved example.com address',
                    'phone' => 'placeholder phone',
                    'address_line1' => 'fictional German address',
                    'address_line2' => 'optional string',
                    'postal_code' => 'German postal code',
                    'city' => 'German city',
                    'state' => 'German state',
                    'country' => 'Deutschland',
                    'timezone' => 'Europe/Berlin',
                    'instagram_handle' => 'fictional placeholder handle',
                    'profile_label' => 'display label',
                    'bio' => 'short bio idea',
                    'nationality' => 'string',
                    'occupation' => 'string',
                    'relationship_status' => 'string',
                    'languages' => ['string'],
                    'interests' => ['string'],
                    'personality_traits' => ['string'],
                    'values' => ['string'],
                    'physical_appearance' => 'neutral visual description',
                    'daily_routine' => 'string',
                    'background_story' => 'string',
                    'communication_style' => 'string',
                    'writing_style' => 'string',
                    'behavior_guidelines' => 'string',
                ],
            ],
        ];
    }

    private function sanitizeIdentitySuggestion(array $suggestion): array
    {
        $firstName = $this->cleanText($suggestion['first_name'] ?? 'Alex') ?: 'Alex';
        $lastName = $this->cleanText($suggestion['last_name'] ?? 'Muster') ?: 'Muster';
        $handle = Str::slug($this->cleanText($suggestion['instagram_handle'] ?? $firstName.'.'.$lastName), '.');
        $handle = $handle !== '' ? $handle : 'testprofil'.random_int(1000, 9999);
        $dateOfBirth = $this->cleanDate($suggestion['date_of_birth'] ?? null);

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'alias' => $this->cleanText($suggestion['alias'] ?? $firstName) ?: $firstName,
            'date_of_birth' => $dateOfBirth,
            'gender' => $this->cleanText($suggestion['gender'] ?? 'diverse') ?: 'diverse',
            'email' => $this->safeExampleEmail($suggestion['email'] ?? null, $handle),
            'phone' => $this->safePlaceholderPhone($suggestion['phone'] ?? null),
            'address_line1' => $this->cleanText($suggestion['address_line1'] ?? 'Musterstrasse 1') ?: 'Musterstrasse 1',
            'address_line2' => $this->cleanText($suggestion['address_line2'] ?? ''),
            'postal_code' => $this->cleanText($suggestion['postal_code'] ?? '10115') ?: '10115',
            'city' => $this->cleanText($suggestion['city'] ?? 'Berlin') ?: 'Berlin',
            'state' => $this->cleanText($suggestion['state'] ?? 'Berlin') ?: 'Berlin',
            'country' => $this->cleanText($suggestion['country'] ?? 'Deutschland') ?: 'Deutschland',
            'timezone' => $this->cleanText($suggestion['timezone'] ?? 'Europe/Berlin') ?: 'Europe/Berlin',
            'instagram_handle' => $handle,
            'profile_label' => $this->cleanText($suggestion['profile_label'] ?? trim($firstName.' '.$lastName)) ?: trim($firstName.' '.$lastName),
            'bio' => $this->cleanText($suggestion['bio'] ?? ''),
            'nationality' => $this->cleanText($suggestion['nationality'] ?? 'deutsch') ?: 'deutsch',
            'occupation' => $this->cleanText($suggestion['occupation'] ?? ''),
            'relationship_status' => $this->cleanText($suggestion['relationship_status'] ?? ''),
            'languages' => $this->normalizeList($suggestion['languages'] ?? ['Deutsch']),
            'interests' => $this->normalizeList($suggestion['interests'] ?? []),
            'personality_traits' => $this->normalizeList($suggestion['personality_traits'] ?? []),
            'values' => $this->normalizeList($suggestion['values'] ?? []),
            'physical_appearance' => $this->cleanText($suggestion['physical_appearance'] ?? ''),
            'daily_routine' => $this->cleanText($suggestion['daily_routine'] ?? ''),
            'background_story' => $this->cleanText($suggestion['background_story'] ?? ''),
            'communication_style' => $this->cleanText($suggestion['communication_style'] ?? ''),
            'writing_style' => $this->cleanText($suggestion['writing_style'] ?? ''),
            'behavior_guidelines' => $this->cleanText($suggestion['behavior_guidelines'] ?? ''),
        ];
    }

    private function makeIdentitySuggestion(): array
    {
        $gender = $this->pick(['female', 'male', 'diverse']);
        $firstName = $this->pick($gender === 'female'
            ? ['Anna', 'Clara', 'Elisa', 'Hannah', 'Jana', 'Laura', 'Leonie', 'Mara', 'Nina', 'Sophie']
            : ($gender === 'male'
                ? ['Alexander', 'Daniel', 'Felix', 'Jonas', 'Leon', 'Lukas', 'Matthias', 'Nico', 'Paul', 'Tim']
                : ['Alex', 'Charlie', 'Jona', 'Kim', 'Luca', 'Mika', 'Noel', 'Robin', 'Sam', 'Toni'])
        );
        $lastName = $this->pick(['Albrecht', 'Bergmann', 'Brandt', 'Falk', 'Hartmann', 'Keller', 'Koenig', 'Lorenz', 'Neumann', 'Richter']);
        $handle = Str::slug($firstName.'.'.$lastName, '.').random_int(10, 99);
        $location = $this->pick([
            ['postal_code' => '10115', 'city' => 'Berlin', 'state' => 'Berlin'],
            ['postal_code' => '20095', 'city' => 'Hamburg', 'state' => 'Hamburg'],
            ['postal_code' => '50667', 'city' => 'Koeln', 'state' => 'Nordrhein-Westfalen'],
            ['postal_code' => '80331', 'city' => 'Muenchen', 'state' => 'Bayern'],
        ]);

        return $this->sanitizeIdentitySuggestion([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'alias' => $firstName,
            'date_of_birth' => now()->subYears(random_int(21, 42))->subDays(random_int(0, 364))->format('Y-m-d'),
            'gender' => $gender,
            'email' => $handle.'@example.com',
            'phone' => '+49 30 0000 '.random_int(1000, 9999),
            'address_line1' => $this->pick(['Ahornweg', 'Am Stadtpark', 'Feldstrasse', 'Lindenallee']).' '.random_int(1, 128),
            ...$location,
            'country' => 'Deutschland',
            'timezone' => 'Europe/Berlin',
            'instagram_handle' => $handle,
            'profile_label' => $firstName.' '.$lastName,
            'bio' => 'Fiktives Testprofil mit Alltag, Interessen und persoenlichem Stil.',
            'nationality' => 'deutsch',
            'occupation' => $this->pick(['Mediengestaltung', 'Projektassistenz', 'Fotografie', 'Studium', 'Training']),
            'relationship_status' => $this->pick(['ledig', 'in einer Beziehung', 'keine Angabe']),
            'languages' => ['Deutsch', 'Englisch'],
            'interests' => ['Fotografie', 'Reisen', 'Musik'],
            'personality_traits' => ['offen', 'aufmerksam', 'kreativ'],
            'values' => ['Zuverlaessigkeit', 'Neugier', 'Respekt'],
            'physical_appearance' => 'Natuerliche, unauffaellige Erscheinung mit alltagstauglichem Stil.',
            'daily_routine' => 'Arbeitet tagsueber, ist abends oft kreativ oder sportlich unterwegs.',
            'background_story' => 'Fiktive Testpersona fuer interne Entwicklungs- und Analysezwecke.',
            'communication_style' => 'Freundlich, direkt und alltagsnah.',
            'writing_style' => 'Kurze, natuerliche Saetze mit persoenlichem Ton.',
            'behavior_guidelines' => 'Bleibt konsistent und erfindet keine echten Kontodaten.',
        ]);
    }

    private function uniqueProfileSlug(array $suggestion): string
    {
        $base = Str::slug($suggestion['instagram_handle'] ?: $suggestion['profile_label']) ?: 'ai-person';
        $slug = $base;
        $counter = 2;

        while (Person::query()->where('browser_profile_path', 'browser-profiles/instagram/'.$slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function cleanDate(mixed $value): string
    {
        $value = $this->cleanText($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? $value
            : now()->subYears(28)->format('Y-m-d');
    }

    private function cleanText(mixed $value): string
    {
        return trim((string) $value);
    }

    private function safeExampleEmail(mixed $email, string $handle): string
    {
        $email = strtolower(trim((string) $email));

        return preg_match('/^[a-z0-9._+-]+@example\.com$/', $email)
            ? $email
            : (Str::slug($handle, '.') ?: 'testprofil').'.'.random_int(100, 999).'@example.com';
    }

    private function safePlaceholderPhone(mixed $phone): string
    {
        $phone = trim((string) $phone);

        return preg_match('/^\+49\s?30\s?0000\s?\d{4}$/', $phone)
            ? $phone
            : '+49 30 0000 '.random_int(1000, 9999);
    }

    private function normalizeList(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[\r\n,;]+/', $values) ?: [];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $value): string => $this->cleanText($value),
            $values
        )));
    }

    private function pick(array $values): mixed
    {
        return $values[array_rand($values)];
    }
}
