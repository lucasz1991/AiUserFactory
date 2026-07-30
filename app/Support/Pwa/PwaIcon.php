<?php

namespace App\Support\Pwa;

use InvalidArgumentException;

/**
 * Portiert aus RailTime. Der Zweck ist derselbe: Manifest, PWA-Head und
 * Push-Payload verweisen auf feste Icon-Namen, und diese Namen muessen
 * **immer** ein gueltiges PNG liefern. Liegt in `public/icons/` eine echte
 * Grafik, wird sie ausgeliefert; fehlt sie, erzeugt diese Klasse ein
 * gueltiges PNG aus dem Marken-Zeichen — sonst zeigt eine frisch installierte
 * App ein leeres Icon oder der Push gar keins.
 *
 * Das Zeichen ist an `public/favicon.svg` angelehnt: zwei aufsteigende Saeulen
 * ueber einer Grundlinie, auf dunklem Navy.
 */
final class PwaIcon
{
    /**
     * All icons referenced by the manifest, PWA head or push payload.
     *
     * @var array<string, int>
     */
    public const DIMENSIONS = [
        'pwa-192.png' => 192,
        'pwa-512.png' => 512,
        'pwa-maskable-512.png' => 512,
        'apple-touch-icon-180.png' => 180,
        'push-badge-96.png' => 96,
    ];

    /** Navy aus `public/favicon.svg` (#081b2d). */
    private const BACKGROUND = [8, 27, 45];

    /** Teal (#5eead4). */
    private const BAR_FRONT = [94, 234, 212];

    /** Amber (#f59e0b). */
    private const BAR_BACK = [245, 158, 11];

    /** Helles Grau der Grundlinie (#e2e8f0). */
    private const BASELINE = [226, 232, 240];

    public static function supports(string $fileName): bool
    {
        return array_key_exists($fileName, self::DIMENSIONS);
    }

    public static function fallback(string $fileName): string
    {
        if (! self::supports($fileName)) {
            throw new InvalidArgumentException('Unsupported PWA icon.');
        }

        $size = self::DIMENSIONS[$fileName];
        $isBadge = $fileName === 'push-badge-96.png';

        // Maskable-Icons werden vom Betriebssystem beschnitten. Das Zeichen
        // muss deshalb in der inneren Sicherheitszone (~80 % Durchmesser)
        // liegen, nicht randfuellend.
        $scale = $fileName === 'pwa-maskable-512.png' ? 0.62 : 1.0;
        $rawImage = '';

        for ($y = 0; $y < $size; $y++) {
            $rawImage .= "\x00";

            for ($x = 0; $x < $size; $x++) {
                [$red, $green, $blue, $alpha] = self::pixel(
                    $x / $size,
                    $y / $size,
                    $isBadge,
                    $scale,
                );
                $rawImage .= pack('C4', $red, $green, $blue, $alpha);
            }
        }

        $header = pack('N2C5', $size, $size, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', $header)
            .self::chunk('IDAT', gzcompress($rawImage, 9))
            .self::chunk('IEND', '');
    }

    /**
     * @return array{int, int, int, int}
     */
    private static function pixel(float $x, float $y, bool $isBadge, float $scale): array
    {
        // Zeichen um die Bildmitte skalieren, Hintergrund bleibt randfuellend.
        $markX = 0.5 + ($x - 0.5) / $scale;
        $markY = 0.5 + ($y - 0.5) / $scale;
        $part = self::markPart($markX, $markY);

        if ($isBadge) {
            // Badges werden monochrom eingefaerbt: nur Deckkraft zaehlt.
            return $part === null
                ? [255, 255, 255, 0]
                : [255, 255, 255, 255];
        }

        return match ($part) {
            'front' => [...self::BAR_FRONT, 255],
            'back' => [...self::BAR_BACK, 255],
            'baseline' => [...self::BASELINE, 255],
            default => [...self::BACKGROUND, 255],
        };
    }

    /**
     * Normalisierte Geometrie aus `public/favicon.svg` (viewBox 64x64).
     */
    private static function markPart(float $x, float $y): ?string
    {
        if ($x >= 0.22 && $x <= 0.81 && $y >= 0.800 && $y <= 0.845) {
            return 'baseline';
        }

        if ($x >= 0.250 && $x < 0.547 && $y >= 0.420 && $y < 0.790) {
            return 'front';
        }

        if ($x >= 0.547 && $x <= 0.785 && $y >= 0.280 && $y < 0.790) {
            return 'back';
        }

        return null;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data));
    }
}
