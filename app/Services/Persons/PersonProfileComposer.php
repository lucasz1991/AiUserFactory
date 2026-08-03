<?php

namespace App\Services\Persons;

use App\Models\Person;
use App\Services\Ai\AiConnectionService;
use Carbon\Carbon;

/**
 * Gemeinsame Quelle fuer AI-erzeugte Persona-Profile.
 *
 * Bis hierher lebten Feldlisten und Systemprompt ausschliesslich im Livewire-
 * Dialog `AiCompletePersonProfileModal` und waren damit fuer die Personen-Fabrik
 * unerreichbar. Beide Wege lesen jetzt dieselben Konstanten und denselben
 * Prompt; der Dialog verhaelt sich dadurch unveraendert.
 */
class PersonProfileComposer
{
    public const ROOT_FIELDS = [
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

    public const IDENTITY_FIELDS = [
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

    public const BOT_FIELDS = [
        'communication_style',
        'writing_style',
        'behavior_guidelines',
    ];

    public const LIST_FIELDS = ['languages', 'interests', 'personality_traits', 'values'];

    public function __construct(protected AiConnectionService $ai) {}

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
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

    /**
     * Erzeugt ein vollstaendiges Profil fuer eine noch leere Person und schreibt
     * es direkt auf das Modell. Wird von der Personen-Fabrik genutzt.
     *
     * @param  array<string, mixed>  $corridors
     */
    public function composeForNewPerson(Person $person, string $prompt, array $corridors = []): void
    {
        $result = $this->ai->json(
            prompt: json_encode(
                $this->buildContext($prompt, $corridors),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            ),
            system: $this->systemPrompt(),
            options: [
                'temperature' => 0.9,
                'max_completion_tokens' => 3000,
            ],
        );

        $this->applyToPerson($person, is_array($result) ? $result : []);
    }

    /**
     * @param  array<string, mixed>  $corridors
     * @return array<string, mixed>
     */
    public function buildContext(string $prompt, array $corridors = []): array
    {
        return [
            'task' => 'Erzeuge eine vollstaendige, in sich stimmige fiktive Persona.',
            'user_prompt' => trim($prompt),
            'constraints' => array_filter([
                'country' => $corridors['country'] ?? null,
                'language' => $corridors['language'] ?? null,
                'gender' => $corridors['gender'] ?? null,
                'age' => $corridors['age'] ?? null,
            ]),
            'existing_person_data' => $corridors['existing'] ?? [],
            'simulation_goal' => 'Die Daten werden spaeter fuer eine isolierte interne Persona-Sandbox genutzt. Besonders wichtig sind plausible Tagesrhythmen, Interessen, Content-Themen, Kommunikationsstil und Grenzen fuer interne Feed-/Session-Schritte. Keine reale Plattform-Automation planen.',
            'allowed_fields_only' => [
                'root' => self::ROOT_FIELDS,
                'identity_profile' => self::IDENTITY_FIELDS,
                'bot_profile' => self::BOT_FIELDS,
            ],
            'strict_exclusions' => [
                'instagram', 'instagram_username', 'instagram_password', 'instagram_profile_url',
                'social_accounts', 'login_username', 'password', 'cookies', 'session',
                'browser_profile_path', 'cookie_file_path', 'avatar_path', 'profile_image',
                'files', 'images', 'uploads',
            ],
        ];
    }

    /**
     * Uebertraegt ein AI-Ergebnis auf die Person. Es werden ausschliesslich die
     * erlaubten Felder geschrieben — alles andere im Ergebnis wird verworfen.
     *
     * @param  array<string, mixed>  $result
     */
    public function applyToPerson(Person $person, array $result): void
    {
        $root = [];

        foreach (self::ROOT_FIELDS as $field) {
            $value = $this->text(data_get($result, "root.{$field}"));

            if ($value !== '') {
                $root[$field] = $value;
            }
        }

        if (isset($root['person_date_of_birth']) && ! $this->isDate($root['person_date_of_birth'])) {
            unset($root['person_date_of_birth']);
        }

        $identityProfile = is_array($person->identity_profile) ? $person->identity_profile : [];
        $botProfile = is_array($person->bot_profile) ? $person->bot_profile : [];

        foreach (self::IDENTITY_FIELDS as $field) {
            $value = $this->text(data_get($result, "identity_profile.{$field}"));

            if ($value === '') {
                continue;
            }

            $identityProfile[$field] = in_array($field, self::LIST_FIELDS, true)
                ? $this->splitValues($value)
                : $value;
        }

        foreach (self::BOT_FIELDS as $field) {
            $value = $this->text(data_get($result, "bot_profile.{$field}"));

            if ($value !== '') {
                $botProfile[$field] = $value;
            }
        }

        $person->forceFill([
            ...$root,
            'identity_profile' => $identityProfile,
            'bot_profile' => $botProfile,
        ])->save();
    }

    protected function text(mixed $value): string
    {
        if (is_array($value)) {
            return implode(PHP_EOL, array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            )));
        }

        return trim((string) $value);
    }

    /**
     * @return array<int, string>
     */
    protected function splitValues(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            preg_split('/[\r\n,;]+/', $value) ?: [],
        )));
    }

    protected function isDate(string $value): bool
    {
        try {
            return Carbon::parse($value)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }
}
