<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Alpine-Ausdruecke stehen in HTML-Attributen, die von doppelten
 * Anfuehrungszeichen begrenzt werden. Enthaelt der Ausdruck selbst ein
 * doppeltes Anfuehrungszeichen — typischerweise in einem CSS-Attributselektor
 * wie [role="dialog"] — endet das Attribut fuer den HTML-Parser genau dort.
 * Der Rest des JavaScripts landet als sichtbarer Text im Dokument.
 *
 * Im Quelltext sieht das voellig unauffaellig aus; sichtbar wird es erst im
 * gerenderten Markup. Genau deshalb dieser Test.
 */
class BladeAlpineAttributeIntegrityTest extends TestCase
{
    /** Attribute, deren Inhalt als JavaScript ausgewertet wird. */
    private const JS_ATTRIBUTE = '/(?:^|\s)((?:x-[\w.:-]+|@[\w.:-]+|wire:[\w.:-]+))="/';

    #[Test]
    public function kein_alpine_attribut_wird_vorzeitig_beendet(): void
    {
        $funde = [];

        foreach ($this->bladeDateien() as $pfad) {
            $inhalt = file_get_contents($pfad);

            if (! preg_match_all(self::JS_ATTRIBUTE, $inhalt, $treffer, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($treffer[0] as $index => [$roh, $offset]) {
                $name = $treffer[1][$index][0];
                $start = $offset + strlen($roh);
                $ende = strpos($inhalt, '"', $start);

                if ($ende === false) {
                    continue;
                }

                $block = substr($inhalt, $start, $ende - $start);

                // Ein Attribut, das mitten in einer offenen Klammer oder direkt
                // hinter einem angefangenen Attributselektor endet, wurde vom
                // Parser zu frueh abgeschnitten.
                $unbalanciert = substr_count($block, '(') !== substr_count($block, ')')
                    || substr_count($block, '{') !== substr_count($block, '}')
                    || substr_count($block, '[') !== substr_count($block, ']');

                $endetImSelektor = preg_match('/\[[\w-]+=$/', $block) === 1;

                if ($unbalanciert || $endetImSelektor) {
                    $zeile = substr_count(substr($inhalt, 0, $offset), "\n") + 1;
                    $funde[] = sprintf(
                        '%s:%d — %s bricht ab bei: ...%s',
                        str_replace(base_path().DIRECTORY_SEPARATOR, '', $pfad),
                        $zeile,
                        $name,
                        str_replace("\n", ' ', substr($block, -60))
                    );
                }
            }
        }

        $this->assertSame([], $funde, sprintf(
            "Alpine-/Livewire-Attribute enden vorzeitig — das dahinterliegende JavaScript wird als Text im Dokument sichtbar.\n".
            "Ursache ist fast immer ein doppeltes Anfuehrungszeichen im Ausdruck; CSS-Attributselektoren dort unquotiert ".
            "schreiben ([role=dialog] statt [role=\"dialog\"]).\n\n%s",
            implode("\n", $funde)
        ));
    }

    /** @return list<string> */
    private function bladeDateien(): array
    {
        $verzeichnis = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        $dateien = [];

        foreach ($verzeichnis as $datei) {
            if ($datei->isFile() && str_ends_with($datei->getFilename(), '.blade.php')) {
                $dateien[] = $datei->getPathname();
            }
        }

        sort($dateien);

        return $dateien;
    }
}
