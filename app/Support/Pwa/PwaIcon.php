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
 * Das Zeichen ist die kompakte Lesart der FollowFlow-Marke aus
 * `public/favicon.svg`: eine leuchtende Sphaere auf dunklem Violett, umlaufen
 * von einer Bahn mit zwei Partikeln. Die ausgelieferten Dateien entstehen aus
 * `scripts/brand/generate-brand-assets.py`; dieser Notpfad bildet dieselbe
 * Geometrie vereinfacht nach.
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

    /** Tiefes Violett des Badges (#1B0B3A). */
    public const BACKGROUND = [27, 11, 58];

    /**
     * Verlauf der Sphaere vom Lichtpunkt nach aussen.
     *
     * @var array<int, array{float, array{int, int, int}}>
     */
    private const CORE_STOPS = [
        [0.00, [237, 233, 254]],
        [0.18, [196, 181, 253]],
        [0.45, [139, 92, 246]],
        [0.75, [109, 40, 217]],
        [1.00, [59, 15, 115]],
    ];

    /** Helles Violett der Bahn (#D8B4FE). */
    private const ORBIT = [216, 180, 254];

    /** Partikel (#EDE9FE). */
    private const PARTICLE = [237, 233, 254];

    /** Normalisierte Geometrie, abgeleitet aus `public/favicon.svg` (64er Raster). */
    private const SPHERE_RADIUS = 0.250;
    private const ORBIT_RX = 0.422;
    private const ORBIT_RY = 0.166;
    private const ORBIT_ROTATION = -24.0;
    private const ORBIT_HALF_WIDTH = 0.027;
    private const ORBIT_OPACITY = 0.85;

    /** Partikel als [Bahnphase, Radius]. */
    private const PARTICLES = [
        [0.06, 0.050],
        [0.56, 0.053],
    ];

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
        $pixel = 1.0 / $size;
        $rawImage = '';

        for ($y = 0; $y < $size; $y++) {
            $rawImage .= "\x00";

            for ($x = 0; $x < $size; $x++) {
                [$red, $green, $blue, $alpha] = self::pixel(
                    ($x + 0.5) / $size,
                    ($y + 0.5) / $size,
                    $isBadge,
                    $scale,
                    $pixel,
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
    private static function pixel(float $x, float $y, bool $isBadge, float $scale, float $pixel): array
    {
        // Zeichen um die Bildmitte skalieren, Hintergrund bleibt randfuellend.
        $markX = 0.5 + ($x - 0.5) / $scale;
        $markY = 0.5 + ($y - 0.5) / $scale;
        $unit = $pixel / $scale;

        $sphere = self::sphereCoverage($markX, $markY, $unit);
        $orbit = self::orbitCoverage($markX, $markY, $unit) * self::ORBIT_OPACITY;
        $particle = self::particleCoverage($markX, $markY, $unit);

        if ($isBadge) {
            // Badges werden monochrom eingefaerbt: nur Deckkraft zaehlt.
            $alpha = max($sphere, $orbit, $particle);

            return [255, 255, 255, (int) round(255 * $alpha)];
        }

        $color = self::BACKGROUND;
        $color = self::blend($color, self::ORBIT, $orbit);
        $color = self::blend($color, self::coreColor(self::coreDistance($markX, $markY)), $sphere);
        $color = self::blend($color, self::PARTICLE, $particle);

        return [$color[0], $color[1], $color[2], 255];
    }

    /**
     * Weiche Kante: innerhalb eines Pixels vom Rand wird anteilig gemischt,
     * sonst saehe das Zeichen bei 96 px ausgefranst aus.
     *
     * @param  array{int, int, int}  $base
     * @param  array{int, int, int}  $over
     * @return array{int, int, int}
     */
    private static function blend(array $base, array $over, float $amount): array
    {
        if ($amount <= 0.0) {
            return $base;
        }

        $amount = min(1.0, $amount);

        return [
            (int) round($base[0] + ($over[0] - $base[0]) * $amount),
            (int) round($base[1] + ($over[1] - $base[1]) * $amount),
            (int) round($base[2] + ($over[2] - $base[2]) * $amount),
        ];
    }

    private static function coverage(float $distance, float $unit): float
    {
        if ($unit <= 0.0) {
            return $distance <= 0.0 ? 1.0 : 0.0;
        }

        return max(0.0, min(1.0, 0.5 - $distance / $unit));
    }

    private static function sphereCoverage(float $x, float $y, float $unit): float
    {
        $distance = hypot($x - 0.5, $y - 0.5) - self::SPHERE_RADIUS;

        return self::coverage($distance, $unit);
    }

    /**
     * Abstand zur gedrehten Ellipse, erster Ordnung ueber |F| / |grad F|.
     */
    private static function orbitCoverage(float $x, float $y, float $unit): float
    {
        [$localX, $localY] = self::toOrbitSpace($x, $y);

        $rx2 = self::ORBIT_RX ** 2;
        $ry2 = self::ORBIT_RY ** 2;
        $value = ($localX ** 2) / $rx2 + ($localY ** 2) / $ry2 - 1.0;
        $gradient = hypot(2 * $localX / $rx2, 2 * $localY / $ry2);

        if ($gradient <= 0.0) {
            return 0.0;
        }

        $distance = abs($value / $gradient) - self::ORBIT_HALF_WIDTH;

        return self::coverage($distance, $unit);
    }

    private static function particleCoverage(float $x, float $y, float $unit): float
    {
        $best = 0.0;

        foreach (self::PARTICLES as [$phase, $radius]) {
            $angle = 2 * M_PI * $phase;
            $localX = self::ORBIT_RX * cos($angle);
            $localY = self::ORBIT_RY * sin($angle);
            $rotation = deg2rad(self::ORBIT_ROTATION);
            $centerX = 0.5 + $localX * cos($rotation) - $localY * sin($rotation);
            $centerY = 0.5 + $localX * sin($rotation) + $localY * cos($rotation);

            $best = max($best, self::coverage(hypot($x - $centerX, $y - $centerY) - $radius, $unit));
        }

        return $best;
    }

    /**
     * @return array{float, float}
     */
    private static function toOrbitSpace(float $x, float $y): array
    {
        $rotation = deg2rad(-self::ORBIT_ROTATION);
        $dx = $x - 0.5;
        $dy = $y - 0.5;

        return [
            $dx * cos($rotation) - $dy * sin($rotation),
            $dx * sin($rotation) + $dy * cos($rotation),
        ];
    }

    /** Normalisierter Abstand zum Lichtpunkt der Sphaere. */
    private static function coreDistance(float $x, float $y): float
    {
        $focusX = 0.5 - self::SPHERE_RADIUS * 0.42;
        $focusY = 0.5 - self::SPHERE_RADIUS * 0.50;

        return hypot($x - $focusX, $y - $focusY) / (self::SPHERE_RADIUS * 1.55);
    }

    /**
     * @return array{int, int, int}
     */
    private static function coreColor(float $position): array
    {
        $position = max(0.0, min(1.0, $position));
        $stops = self::CORE_STOPS;

        for ($i = 0, $last = count($stops) - 1; $i < $last; $i++) {
            [$from, $fromColor] = $stops[$i];
            [$to, $toColor] = $stops[$i + 1];

            if ($position <= $to) {
                $local = $to <= $from ? 0.0 : ($position - $from) / ($to - $from);

                return self::blend($fromColor, $toColor, $local);
            }
        }

        return $stops[count($stops) - 1][1];
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data));
    }
}
