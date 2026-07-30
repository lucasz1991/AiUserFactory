<?php

namespace App\Services\Workflows;

use App\Models\WorkflowPortalProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Selektor-Gedaechtnis pro Portal (Baustein B aus dem Copilot-Konzept v2).
 *
 * In den 31 ausgewerteten Produktionssitzungen hat der Copilot dieselbe Domain
 * immer wieder von vorn erkundet: Die Vision-Antwort nennt Elemente, die
 * Beobachtung liefert Selector-Kandidaten, und nach dem Lauf war beides weg.
 * Hier wird festgehalten, was nachweislich getroffen hat — pro Domain und
 * semantischer Rolle ('search_input', 'consent_reject', 'result_link').
 *
 * Bewusste Abgrenzung: Dieser Service beurteilt Selektoren nicht selbst. Ob ein
 * Ausdruck fachlich riskant ist, entscheidet allein
 * WorkflowSelectorSyntaxService::qualityWarningsFor(); hier wird das Urteil nur
 * mitgeschrieben und bei Gleichstand als Tiebreak verwendet.
 */
class WorkflowPortalProfileService
{
    private const MAX_DOMAIN_LENGTH = 191;

    private const MAX_ROLE_LENGTH = 64;

    private const MAX_SOURCE_LENGTH = 80;

    public function __construct(private readonly WorkflowSelectorSyntaxService $selectorSyntax) {}

    /**
     * Haelt fest, dass der Selector fuer diese Rolle funktioniert hat.
     */
    public function remember(string $domain, string $role, string $selector, ?string $source = null): WorkflowPortalProfile
    {
        $normalizedDomain = $this->requireDomain($domain);
        $normalizedRole = $this->requireRole($role);
        $normalizedSelector = $this->requireSelector($selector);

        $profile = WorkflowPortalProfile::query()->firstOrNew(
            [
                'domain' => $normalizedDomain,
                'role' => $normalizedRole,
                'selector_hash' => $this->hashSelector($normalizedSelector),
            ],
            ['hit_count' => 0, 'miss_count' => 0],
        );

        $profile->selector = $normalizedSelector;

        // Bei jedem Treffer neu bewertet: Die Warnregeln wachsen mit den
        // Produktionsbefunden, ein einmal gespeichertes Urteil waere veraltet.
        $profile->has_quality_warnings = $this->selectorSyntax->qualityWarningsFor($normalizedSelector) !== [];
        $profile->hit_count = $profile->hit_count + 1;
        $profile->last_confirmed_at = Carbon::now();

        if (($trimmedSource = trim((string) $source)) !== '') {
            $profile->source = mb_substr($trimmedSource, 0, self::MAX_SOURCE_LENGTH);
        }

        try {
            $profile->save();
        } catch (UniqueConstraintViolationException) {
            // Zwei Laeufe koennen denselben Selector gleichzeitig bestaetigen.
            // Dann hat der andere die Zeile zwischen firstOrNew() und save()
            // angelegt; der Unique-Index faengt das ab. Statt den Treffer zu
            // verlieren, wird die inzwischen vorhandene Zeile geladen und
            // hochgezaehlt.
            $profile = WorkflowPortalProfile::query()
                ->where('domain', $normalizedDomain)
                ->where('role', $normalizedRole)
                ->where('selector_hash', $this->hashSelector($normalizedSelector))
                ->firstOrFail();

            $profile->increment('hit_count');
            $profile->forceFill(['last_confirmed_at' => Carbon::now()])->save();
        }

        return $profile;
    }

    /**
     * Haelt fest, dass der Selector diesmal nicht getroffen hat.
     *
     * Unbekannte Selektoren werden bewusst nicht angelegt: Die Tabelle ist ein
     * Gedaechtnis fuer belegte Treffer und keine Sperrliste. Ein Eintrag ohne
     * je bestaetigten Treffer koennte sonst als 'bester' Kandidat gelten, nur
     * weil er der einzige ist.
     */
    public function recordMiss(string $domain, string $role, string $selector): void
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        $normalizedRole = $this->normalizeRole($role);
        $normalizedSelector = trim($selector);

        if ($normalizedDomain === '' || $normalizedRole === '' || $normalizedSelector === '') {
            return;
        }

        WorkflowPortalProfile::query()
            ->forDomain($normalizedDomain)
            ->forRole($normalizedRole)
            ->where('selector_hash', $this->hashSelector($normalizedSelector))
            ->increment('miss_count');
    }

    /**
     * Der Selector, der fuer diese Rolle am ehesten wieder trifft.
     */
    public function bestFor(string $domain, string $role): ?string
    {
        return $this->rankedSelectorsFor($domain, $role)[0] ?? null;
    }

    /**
     * Alle noch gueltigen Selektoren dieser Rolle, bester zuerst.
     *
     * Reihenfolge: hoechste Trefferquote; bei Gleichstand der warnungsfreie
     * Ausdruck; danach der zuletzt bestaetigte. Verfallene Eintraege fehlen.
     *
     * @return list<string>
     */
    public function rankedSelectorsFor(string $domain, string $role): array
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        $normalizedRole = $this->normalizeRole($role);

        if ($normalizedDomain === '' || $normalizedRole === '') {
            return [];
        }

        return WorkflowPortalProfile::query()
            ->forDomain($normalizedDomain)
            ->forRole($normalizedRole)
            ->get()
            ->reject(fn (WorkflowPortalProfile $profile): bool => $profile->isExpired())
            ->sort(fn (WorkflowPortalProfile $left, WorkflowPortalProfile $right): int => $this->compareCandidates($left, $right))
            ->map(fn (WorkflowPortalProfile $profile): string => $profile->selector)
            ->values()
            ->all();
    }

    /**
     * Entfernt Eintraege, die seit N Tagen nicht mehr bestaetigt wurden.
     *
     * @return int Anzahl der geloeschten Eintraege
     */
    public function forgetStale(int $days = 90): int
    {
        // `0` oder negativ wuerde den Stichtag auf "jetzt" setzen und damit auch
        // sekundenaktuell bestaetigte Eintraege loeschen. Ein Aufraeumlauf soll
        // nie die gesamte Tabelle leeren, deshalb hier eine harte Untergrenze.
        if ($days < 1) {
            throw new InvalidArgumentException('forgetStale erwartet mindestens einen Tag.');
        }

        $cutoff = Carbon::now()->subDays($days);

        return WorkflowPortalProfile::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_confirmed_at', '<', $cutoff)
                    ->orWhere(function ($fallback) use ($cutoff): void {
                        // Nie bestaetigte Eintraege koennen nur aus einem Import
                        // stammen; fuer sie zaehlt das Anlagedatum.
                        $fallback->whereNull('last_confirmed_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();
    }

    /**
     * Host einer URL in der Form, unter der er gespeichert wird.
     */
    public function normalizeDomain(string $urlOrHost): string
    {
        $value = trim($urlOrHost);

        if ($value === '') {
            return '';
        }

        // parse_url ordnet einen Wert ohne Schema komplett dem Pfad zu. Die
        // vorangestellten Schraegstriche erzwingen die Host-Auswertung auch
        // fuer eine Eingabe wie 'www.google.de/suche'.
        $candidate = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value) === 1
            ? $value
            : '//'.ltrim($value, '/');

        $host = parse_url($candidate, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            // Fallback fuer Eingaben, die parse_url ablehnt (etwa ein
            // Unterstrich im Host): alles ab dem ersten Trennzeichen weg.
            $parts = preg_split('#[/?\#]#', ltrim($value, '/')) ?: [''];
            $host = (string) preg_replace('#^.*@#', '', (string) $parts[0]);
            $host = (string) preg_replace('#:\d+$#', '', $host);
        }

        $host = mb_strtolower(trim($host, ". \t\n\r\0\x0B"));

        return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
    }

    public function normalizeRole(string $role): string
    {
        return mb_strtolower(trim($role));
    }

    public function hashSelector(string $selector): string
    {
        return hash('sha256', trim($selector));
    }

    private function compareCandidates(WorkflowPortalProfile $left, WorkflowPortalProfile $right): int
    {
        // Bewusst die geglaettete Quote: Die rohe Quote laesst einen einzigen
        // Treffer (1,0) einen ueber zwanzig Laeufe bewaehrten Selector schlagen.
        if (($byRate = $right->confidenceRate() <=> $left->confidenceRate()) !== 0) {
            return $byRate;
        }

        $leftWarnings = $left->has_quality_warnings ? 1 : 0;
        $rightWarnings = $right->has_quality_warnings ? 1 : 0;

        if (($byWarnings = $leftWarnings <=> $rightWarnings) !== 0) {
            return $byWarnings;
        }

        $byConfirmation = ($right->last_confirmed_at?->getTimestamp() ?? 0) <=> ($left->last_confirmed_at?->getTimestamp() ?? 0);

        return $byConfirmation !== 0 ? $byConfirmation : ($right->getKey() <=> $left->getKey());
    }

    private function requireDomain(string $domain): string
    {
        $normalized = $this->normalizeDomain($domain);

        if ($normalized === '') {
            throw new InvalidArgumentException('Ein Portal-Profil benoetigt eine Domain.');
        }

        if (mb_strlen($normalized) > self::MAX_DOMAIN_LENGTH) {
            throw new InvalidArgumentException('Die Domain ist laenger als '.self::MAX_DOMAIN_LENGTH.' Zeichen.');
        }

        return $normalized;
    }

    private function requireRole(string $role): string
    {
        $normalized = $this->normalizeRole($role);

        if ($normalized === '') {
            throw new InvalidArgumentException('Ein Portal-Profil benoetigt eine Rolle, z. B. search_input.');
        }

        if (mb_strlen($normalized) > self::MAX_ROLE_LENGTH) {
            throw new InvalidArgumentException('Die Rolle ist laenger als '.self::MAX_ROLE_LENGTH.' Zeichen.');
        }

        return $normalized;
    }

    private function requireSelector(string $selector): string
    {
        $normalized = trim($selector);

        if ($normalized === '') {
            throw new InvalidArgumentException('Ein Portal-Profil benoetigt einen Selector.');
        }

        return $normalized;
    }
}
