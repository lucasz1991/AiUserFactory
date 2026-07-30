<?php

namespace Tests\Unit;

use App\Support\Pwa\PwaIcon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Spur W. Der Fallback ist die Zusage, dass eine frisch installierte App und
 * eine Push-Zustellung nie ohne Icon dastehen, auch wenn in `public/icons/`
 * keine Datei liegt.
 */
class PwaIconFallbackTest extends TestCase
{
    public function test_every_declared_icon_produces_a_valid_png_of_the_declared_size(): void
    {
        foreach (PwaIcon::DIMENSIONS as $fileName => $size) {
            $binary = PwaIcon::fallback($fileName);

            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $binary, $fileName);

            $info = getimagesizefromstring($binary);

            $this->assertIsArray($info, $fileName.' ist kein lesbares Bild.');
            $this->assertSame($size, $info[0], $fileName.' hat die falsche Breite.');
            $this->assertSame($size, $info[1], $fileName.' hat die falsche Hoehe.');
            $this->assertSame('image/png', $info['mime'], $fileName);
        }
    }

    public function test_an_unknown_icon_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PwaIcon::fallback('../../.env');
    }

    public function test_supports_only_accepts_declared_names(): void
    {
        $this->assertTrue(PwaIcon::supports('pwa-192.png'));
        $this->assertFalse(PwaIcon::supports('pwa-193.png'));
        $this->assertFalse(PwaIcon::supports('pwa-192.png '));
    }

    /**
     * Das Badge wird vom Betriebssystem monochrom eingefaerbt. Ein
     * deckender Hintergrund wuerde daraus einen weissen Klotz machen, deshalb
     * muss die Flaeche ausserhalb des Zeichens transparent sein.
     */
    public function test_the_push_badge_is_transparent_outside_the_mark(): void
    {
        $image = imagecreatefromstring(PwaIcon::fallback('push-badge-96.png'));

        $this->assertNotFalse($image);

        $corner = imagecolorat($image, 1, 1);
        $alpha = ($corner >> 24) & 0x7F;

        // 127 ist in GD vollstaendig transparent.
        $this->assertSame(127, $alpha, 'Die Ecke des Badges ist nicht transparent.');
    }

    /**
     * Maskable-Icons werden vom Startbildschirm beschnitten. Der Rand muss
     * deshalb Hintergrund sein, nicht Zeichen.
     */
    public function test_the_maskable_icon_is_opaque_to_the_edge(): void
    {
        $image = imagecreatefromstring(PwaIcon::fallback('pwa-maskable-512.png'));

        $this->assertNotFalse($image);

        $corner = imagecolorat($image, 2, 2);
        $colors = imagecolorsforindex($image, $corner);

        $this->assertSame(0, $colors['alpha'], 'Das maskable Icon ist am Rand transparent.');
        $this->assertSame(PwaIcon::BACKGROUND, [$colors['red'], $colors['green'], $colors['blue']]);
    }
}
