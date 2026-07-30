<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ein pro Domain und semantischer Rolle gemerkter Selector.
 *
 * Baustein B aus dem Copilot-Konzept v2: Bisher verwarf der Copilot nach jedem
 * Lauf, welcher Selector tatsaechlich getroffen hat, und begann die naechste
 * Sitzung wieder bei null. Diese Tabelle ist das Gedaechtnis dafuer.
 *
 * @property string $domain
 * @property string $role
 * @property string $selector
 * @property string $selector_hash
 * @property bool $has_quality_warnings
 * @property int $hit_count
 * @property int $miss_count
 * @property Carbon|null $last_confirmed_at
 * @property string|null $source
 */
class WorkflowPortalProfile extends Model
{
    protected $fillable = [
        'domain',
        'role',
        'selector',
        'selector_hash',
        'has_quality_warnings',
        'hit_count',
        'miss_count',
        'last_confirmed_at',
        'source',
    ];

    protected $casts = [
        'has_quality_warnings' => 'boolean',
        'hit_count' => 'integer',
        'miss_count' => 'integer',
        'last_confirmed_at' => 'datetime',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDomain(Builder $query, string $domain): Builder
    {
        return $query->where('domain', $domain);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    /**
     * Anteil der bestaetigten Treffer an allen Versuchen.
     */
    public function successRate(): float
    {
        $attempts = $this->hit_count + $this->miss_count;

        return $attempts > 0 ? $this->hit_count / $attempts : 0.0;
    }

    /**
     * Trefferquote mit Laplace-Glaettung — die Groesse fuer die Rangfolge.
     *
     * Die rohe Quote ist bei wenigen Versuchen wertlos: Ein einziger Treffer
     * ergibt 1,0 und wuerde einen ueber zwanzig Laeufe bewaehrten Selector mit
     * 20/1 (0,952) verdraengen. Die Glaettung zieht junge Eintraege zur Mitte und
     * laesst sie erst nach mehreren Bestaetigungen aufsteigen.
     */
    public function confidenceRate(): float
    {
        return ($this->hit_count + 1) / ($this->hit_count + $this->miss_count + 2);
    }

    /**
     * Zweimal in Folge danebengelegen und schlechter als je bestaetigt: Der
     * Eintrag beschreibt die Seite nicht mehr und wird nicht weiter empfohlen.
     * Er bleibt trotzdem stehen, damit ein spaeterer Treffer ihn rehabilitieren
     * kann, statt ihn als neuen Eintrag ohne Historie anzulegen.
     */
    public function isExpired(): bool
    {
        return $this->miss_count >= 2 && $this->miss_count > $this->hit_count;
    }
}
