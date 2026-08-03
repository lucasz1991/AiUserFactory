"""FollowFlow brand asset generator.

Einzige Quelle fuer das Markenzeichen: Aus der hier beschriebenen Geometrie
entstehen alle ausgelieferten Artefakte — Vektor (SVG), Raster (PNG/ICO) und
das animierte Icon (GIF). Wer die Marke aendert, aendert sie hier und laesst
das Skript neu laufen; sonst laufen Favicon, PWA-Icons und Logo auseinander.

Voraussetzungen:
    python -m pip install pillow fonttools

Aufruf (aus dem Projektwurzelverzeichnis):
    python scripts/brand/generate-brand-assets.py

Das Zeichen: eine leuchtende KI-Sphaere in Violett, umflossen von zwei
Orbitringen mit Partikeln ("follow the flow"). Das Schriftlogo setzt
"FollowFlow" in Quicksand 700 — als echte Pfade, damit es unabhaengig von
installierten Schriften ueberall identisch aussieht.
"""

from __future__ import annotations

import math
import os
import struct
import sys
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter

# --------------------------------------------------------------------------
# Pfade
# --------------------------------------------------------------------------

ROOT = Path(__file__).resolve().parents[2]
PUBLIC = ROOT / "public"
BRAND = PUBLIC / "site-images" / "brand"
ICONS = PUBLIC / "icons"
LEGACY_FAVICON = PUBLIC / "site-images" / "favicon"
FONT_PATH = ROOT / "resources" / "fonts" / "Quicksand-VariableFont_wght.ttf"

# --------------------------------------------------------------------------
# Palette
# --------------------------------------------------------------------------

BADGE_TOP = (46, 16, 101)        # #2E1065 violet-950
BADGE_MID = (27, 11, 58)         # #1B0B3A
BADGE_BOTTOM = (17, 6, 38)       # #110626

CORE_STOPS = [
    (0.00, (237, 233, 254)),     # #EDE9FE
    (0.18, (196, 181, 253)),     # #C4B5FD
    (0.45, (139, 92, 246)),      # #8B5CF6
    (0.75, (109, 40, 217)),      # #6D28D9
    (1.00, (59, 15, 115)),       # #3B0F73
]

HALO = (124, 58, 237)            # #7C3AED
ORBIT = (167, 139, 250)          # #A78BFA
ORBIT_FRONT = (216, 180, 254)    # #D8B4FE
PARTICLE_LIGHT = (237, 233, 254)  # #EDE9FE
PARTICLE_MID = (196, 181, 253)   # #C4B5FD
PARTICLE_ACCENT = (232, 121, 249)  # #E879F9 fuchsia-Funke

INK = (36, 19, 68)               # #241344 Wortmarke auf hell
INK_SOFT = (124, 107, 168)       # #7C6BA8 Zusatzzeile auf hell
WORD_ACCENT_A = (124, 58, 237)   # #7C3AED
WORD_ACCENT_B = (192, 38, 211)   # #C026D3

# --------------------------------------------------------------------------
# Geometrie (Einheitsquadrat 64 x 64)
# --------------------------------------------------------------------------

BOX = 64.0
CX, CY = 32.0, 32.0


@dataclass(frozen=True)
class Orbit:
    rx: float
    ry: float
    rot: float          # Grad
    width: float
    opacity: float

    def point(self, t: float) -> tuple[float, float, float]:
        """Punkt auf der Bahn. Drittwert < 0 = Rueckseite (hinter der Sphaere)."""
        a = 2 * math.pi * t
        x, y = self.rx * math.cos(a), self.ry * math.sin(a)
        r = math.radians(self.rot)
        c, s = math.cos(r), math.sin(r)
        return CX + x * c - y * s, CY + x * s + y * c, math.sin(a)


@dataclass(frozen=True)
class Particle:
    orbit: int          # Index in ORBITS, -1 = frei schwebend
    t: float            # Phase 0..1
    r: float            # Radius in Einheiten
    color: tuple[int, int, int]
    opacity: float
    fixed: tuple[float, float] | None = None


@dataclass(frozen=True)
class MarkSpec:
    """Ein Zeichen-Variante. `compact` ist die Lesart fuer 16-32 px."""

    sphere_r: float
    orbits: tuple[Orbit, ...]
    particles: tuple[Particle, ...]
    halo_r: float
    meridians: bool


STANDARD = MarkSpec(
    sphere_r=12.4,
    halo_r=22.0,
    meridians=True,
    orbits=(
        Orbit(rx=23.8, ry=9.0, rot=-24.0, width=1.9, opacity=0.95),
        Orbit(rx=21.2, ry=7.8, rot=32.0, width=1.45, opacity=0.7),
    ),
    particles=(
        Particle(0, 0.04, 1.9, PARTICLE_LIGHT, 1.0),
        Particle(0, 0.27, 1.35, PARTICLE_MID, 0.95),
        Particle(0, 0.52, 2.1, PARTICLE_ACCENT, 1.0),
        Particle(0, 0.71, 1.2, PARTICLE_MID, 0.8),
        Particle(0, 0.90, 1.6, PARTICLE_LIGHT, 0.9),
        Particle(1, 0.13, 1.5, PARTICLE_LIGHT, 0.9),
        Particle(1, 0.41, 1.1, PARTICLE_MID, 0.75),
        Particle(1, 0.66, 1.7, PARTICLE_LIGHT, 0.95),
        Particle(1, 0.86, 1.15, PARTICLE_ACCENT, 0.85),
        Particle(-1, 0.0, 0.85, PARTICLE_MID, 0.55, fixed=(13.5, 15.0)),
        Particle(-1, 0.0, 0.7, PARTICLE_LIGHT, 0.5, fixed=(51.0, 47.5)),
        Particle(-1, 0.0, 0.95, PARTICLE_MID, 0.45, fixed=(46.0, 13.0)),
    ),
)

COMPACT = MarkSpec(
    sphere_r=16.0,
    halo_r=21.0,
    meridians=False,
    orbits=(
        Orbit(rx=27.0, ry=10.6, rot=-24.0, width=3.4, opacity=1.0),
    ),
    particles=(
        Particle(0, 0.06, 3.2, PARTICLE_LIGHT, 1.0),
        Particle(0, 0.56, 3.4, PARTICLE_ACCENT, 1.0),
    ),
)

BADGE_RADIUS = 0.2812  # 18/64, wie das bisherige Zeichen

# Kugelgitter, relativ zum Sphaerenradius
MERIDIAN_RX = 0.42
LATITUDE_RX = 0.94
LATITUDE_RY = 0.30
LATITUDE_DY = 0.16


# --------------------------------------------------------------------------
# Hilfen
# --------------------------------------------------------------------------

def hexc(rgb: tuple[int, int, int]) -> str:
    return "#%02X%02X%02X" % rgb


def lerp(a: float, b: float, t: float) -> float:
    return a + (b - a) * t


def mix(c1: tuple[int, int, int], c2: tuple[int, int, int], t: float) -> tuple[int, int, int]:
    return tuple(int(round(lerp(c1[i], c2[i], t))) for i in range(3))


def core_color(t: float) -> tuple[int, int, int]:
    """Farbe der Sphaere bei normalisiertem Radius t (0 = Zentrum)."""
    t = max(0.0, min(1.0, t))
    for i in range(len(CORE_STOPS) - 1):
        p0, c0 = CORE_STOPS[i]
        p1, c1 = CORE_STOPS[i + 1]
        if t <= p1:
            local = 0.0 if p1 == p0 else (t - p0) / (p1 - p0)
            return mix(c0, c1, local)
    return CORE_STOPS[-1][1]


# --------------------------------------------------------------------------
# SVG
# --------------------------------------------------------------------------

def svg_defs(prefix: str, spec: MarkSpec, badge: bool) -> str:
    parts = []
    if badge:
        parts.append(
            f'<linearGradient id="{prefix}-badge" x1="0" y1="0" x2="64" y2="64" '
            f'gradientUnits="userSpaceOnUse">'
            f'<stop stop-color="{hexc(BADGE_TOP)}"/>'
            f'<stop offset=".55" stop-color="{hexc(BADGE_MID)}"/>'
            f'<stop offset="1" stop-color="{hexc(BADGE_BOTTOM)}"/>'
            f"</linearGradient>"
        )
    stops = "".join(
        f'<stop offset="{p:.2f}" stop-color="{hexc(c)}"/>' for p, c in CORE_STOPS
    )
    sr = spec.sphere_r
    parts.append(
        f'<radialGradient id="{prefix}-core" cx="{CX - sr * 0.42:.2f}" cy="{CY - sr * 0.5:.2f}" '
        f'r="{sr * 1.55:.2f}" gradientUnits="userSpaceOnUse">{stops}</radialGradient>'
    )
    parts.append(
        f'<radialGradient id="{prefix}-halo" cx="{CX}" cy="{CY}" r="{spec.halo_r:.2f}" '
        f'gradientUnits="userSpaceOnUse">'
        f'<stop offset=".25" stop-color="{hexc(HALO)}" stop-opacity=".42"/>'
        f'<stop offset=".62" stop-color="{hexc(HALO)}" stop-opacity=".16"/>'
        f'<stop offset="1" stop-color="{hexc(HALO)}" stop-opacity="0"/>'
        f"</radialGradient>"
    )
    for i, orbit in enumerate(spec.orbits):
        parts.append(
            f'<linearGradient id="{prefix}-arc{i}" x1="{CX - orbit.rx:.2f}" y1="{CY}" '
            f'x2="{CX + orbit.rx:.2f}" y2="{CY}" gradientUnits="userSpaceOnUse">'
            f'<stop stop-color="{hexc(ORBIT)}" stop-opacity="{orbit.opacity * 0.15:.2f}"/>'
            f'<stop offset=".5" stop-color="{hexc(ORBIT_FRONT)}" stop-opacity="{orbit.opacity:.2f}"/>'
            f'<stop offset="1" stop-color="{hexc(ORBIT)}" stop-opacity="{orbit.opacity * 0.3:.2f}"/>'
            f"</linearGradient>"
        )
    return "<defs>" + "".join(parts) + "</defs>"


def svg_orbit_ring(orbit: Orbit, prefix: str) -> str:
    """Rueckseite der Bahn — laeuft hinter der Sphaere und tritt zurueck."""
    x0, x1 = CX - orbit.rx, CX + orbit.rx
    return (
        f'<path d="M{x0:.2f} {CY:.2f} A{orbit.rx:.2f} {orbit.ry:.2f} 0 0 1 {x1:.2f} {CY:.2f}" '
        f'fill="none" stroke="{hexc(ORBIT)}" stroke-width="{orbit.width * 0.8:.2f}" '
        f'stroke-linecap="round" stroke-opacity="{orbit.opacity * 0.33:.2f}" '
        f'transform="rotate({orbit.rot:g} {CX} {CY})"/>'
    )


def svg_orbit_front(orbit: Orbit, prefix: str, index: int) -> str:
    """Vorderer Bogen — liegt ueber der Sphaere, verlaeuft zu den Enden hin
    ins Transparente und liest sich dadurch als fliessende Bahn."""
    x0, x1 = CX - orbit.rx, CX + orbit.rx
    return (
        f'<path d="M{x0:.2f} {CY:.2f} A{orbit.rx:.2f} {orbit.ry:.2f} 0 0 0 {x1:.2f} {CY:.2f}" '
        f'fill="none" stroke="url(#{prefix}-arc{index})" stroke-width="{orbit.width:.2f}" '
        f'stroke-linecap="round" '
        f'transform="rotate({orbit.rot:g} {CX} {CY})"/>'
    )


def svg_particles(spec: MarkSpec, front: bool) -> str:
    out = []
    for p in spec.particles:
        if p.fixed is not None:
            if front:
                continue
            x, y, depth = p.fixed[0], p.fixed[1], 0.0
        else:
            x, y, depth = spec.orbits[p.orbit].point(p.t)
            if (depth < 0) == front:
                continue
        scale = 1.0 if depth >= 0 else 0.72
        opacity = p.opacity if depth >= 0 else p.opacity * 0.5
        if depth < 0 and math.hypot(x - CX, y - CY) < spec.sphere_r + p.r:
            continue
        out.append(
            f'<circle cx="{x:.2f}" cy="{y:.2f}" r="{p.r * scale:.2f}" '
            f'fill="{hexc(p.color)}" opacity="{opacity:.2f}"/>'
        )
    return "".join(out)


def svg_sphere(spec: MarkSpec, prefix: str) -> str:
    sr = spec.sphere_r
    parts = [
        f'<circle cx="{CX}" cy="{CY}" r="{sr:.2f}" fill="url(#{prefix}-core)"/>',
    ]
    if spec.meridians:
        # Meridian und Breitenkreis machen die Kugel als Kugel lesbar.
        parts.append(
            f'<ellipse cx="{CX}" cy="{CY}" rx="{sr * MERIDIAN_RX:.2f}" ry="{sr:.2f}" fill="none" '
            f'stroke="{hexc(PARTICLE_LIGHT)}" stroke-width=".7" stroke-opacity=".26"/>'
        )
        parts.append(
            f'<ellipse cx="{CX}" cy="{CY - sr * LATITUDE_DY:.2f}" rx="{sr * LATITUDE_RX:.2f}" '
            f'ry="{sr * LATITUDE_RY:.2f}" fill="none" '
            f'stroke="{hexc(PARTICLE_LIGHT)}" stroke-width=".6" stroke-opacity=".22"/>'
        )
        parts.append(
            f'<ellipse cx="{CX - sr * 0.40:.2f}" cy="{CY - sr * 0.50:.2f}" '
            f'rx="{sr * 0.22:.2f}" ry="{sr * 0.14:.2f}" fill="#FFFFFF" opacity=".62" '
            f'transform="rotate(-30 {CX - sr * 0.40:.2f} {CY - sr * 0.50:.2f})"/>'
        )
    return "".join(parts)


def svg_mark_body(spec: MarkSpec, prefix: str, halo: bool = True) -> str:
    """Zeichen ohne Huelle — Reihenfolge ergibt die Tiefenstaffelung.
    Ohne dunkles Badge entfaellt der Schein, sonst nebelt er helle Flaechen ein."""
    body = [f'<circle cx="{CX}" cy="{CY}" r="{spec.halo_r:.2f}" fill="url(#{prefix}-halo)"/>'] if halo else []
    for orbit in spec.orbits:
        body.append(svg_orbit_ring(orbit, prefix))
    body.append(svg_particles(spec, front=False))
    body.append(svg_sphere(spec, prefix))
    for i, orbit in enumerate(spec.orbits):
        body.append(svg_orbit_front(orbit, prefix, i))
    body.append(svg_particles(spec, front=True))
    return "".join(body)


def svg_mark(spec: MarkSpec = STANDARD, badge: bool = True, prefix: str = "ff",
             title: str = "FollowFlow") -> str:
    radius = BADGE_RADIUS * BOX
    badge_rect = (
        f'<rect x="0" y="0" width="64" height="64" rx="{radius:.2f}" fill="url(#{prefix}-badge)"/>'
        f'<rect x=".5" y=".5" width="63" height="63" rx="{radius - 0.5:.2f}" fill="none" '
        f'stroke="{hexc(PARTICLE_MID)}" stroke-opacity=".16"/>'
        if badge else ""
    )
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" '
        f'role="img" aria-labelledby="{prefix}-title">'
        f'<title id="{prefix}-title">{title}</title>'
        + svg_defs(prefix, spec, badge)
        + badge_rect
        + svg_mark_body(spec, prefix, halo=badge)
        + "</svg>"
    )


# --------------------------------------------------------------------------
# Schriftlogo: Quicksand 700 als echte Pfade
# --------------------------------------------------------------------------

def load_static_bold():
    from fontTools.ttLib import TTFont
    from fontTools.varLib.instancer import instantiateVariableFont

    font = TTFont(str(FONT_PATH))
    if "fvar" in font:
        font = instantiateVariableFont(font, {"wght": 700}, inplace=True, updateFontNames=False)
    return font


def text_paths(font, text: str, tracking: float = 0.0):
    """(Pfad, x-Versatz) je Zeichen in Fonteinheiten plus Gesamtbreite."""
    from fontTools.pens.svgPathPen import SVGPathPen

    cmap = font.getBestCmap()
    glyphset = font.getGlyphSet()
    hmtx = font["hmtx"]
    out = []
    x = 0.0
    for ch in text:
        name = cmap.get(ord(ch))
        if name is None:
            x += font["head"].unitsPerEm * 0.3
            continue
        pen = SVGPathPen(glyphset)
        glyphset[name].draw(pen)
        out.append((pen.getCommands(), x))
        x += hmtx[name][0] + tracking
    return out, x - tracking


def svg_wordmark_group(font, text: str, size: float, x: float, baseline: float,
                       fill: str, tracking_em: float = 0.0, opacity: float = 1.0) -> tuple[str, float]:
    upem = font["head"].unitsPerEm
    scale = size / upem
    paths, width = text_paths(font, text, tracking=tracking_em * upem)
    # Der Versatz steht innerhalb der bereits skalierten Gruppe und bleibt
    # deshalb in Fonteinheiten — sonst skaliert er ein zweites Mal.
    inner = "".join(
        f'<path transform="translate({off:.1f} 0)" d="{d}"/>' for d, off in paths
    )
    op = f' opacity="{opacity:g}"' if opacity < 1 else ""
    group = (
        f'<g transform="translate({x:.2f} {baseline:.2f}) scale({scale:.5f} -{scale:.5f})" '
        f'fill="{fill}"{op}>{inner}</g>'
    )
    return group, width * scale


def svg_wordmark(dark: bool = False) -> str:
    """Nur die Schrift, ohne Zeichen — fuer Flaechen, auf denen das Zeichen
    bereits gross steht und ein zweites Badge doppelt waere."""
    font = load_static_bold()
    word_size = 46.0
    baseline = 46.0
    pad = 2.0
    prefix = "ffwd" if dark else "ffw"

    follow, w_follow = svg_wordmark_group(
        font, "Follow", word_size, pad, baseline,
        "#FFFFFF" if dark else hexc(INK), tracking_em=-0.012,
    )
    flow, w_flow = svg_wordmark_group(
        font, "Flow", word_size, pad + w_follow, baseline,
        f"url(#{prefix}-word)", tracking_em=-0.012,
    )
    tag, w_tag = svg_wordmark_group(
        font, "AI USER FACTORY", 12.0, pad + 1.5, baseline + 20.0,
        "#FFFFFF" if dark else hexc(INK_SOFT), tracking_em=0.30,
        opacity=0.7 if dark else 1.0,
    )

    width = math.ceil(max(pad + w_follow + w_flow, pad + w_tag) + pad + 2)
    height = 72
    gradient = (
        f'<linearGradient id="{prefix}-word" x1="{pad + w_follow:.1f}" y1="{baseline - word_size:.1f}" '
        f'x2="{pad + w_follow + w_flow:.1f}" y2="{baseline:.1f}" gradientUnits="userSpaceOnUse">'
        f'<stop stop-color="{hexc(WORD_ACCENT_A) if not dark else "#C4B5FD"}"/>'
        f'<stop offset="1" stop-color="{hexc(WORD_ACCENT_B) if not dark else "#F0ABFC"}"/>'
        f"</linearGradient>"
    )

    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" '
        f'width="{width}" height="{height}" role="img" aria-labelledby="{prefix}-t">'
        f'<title id="{prefix}-t">FollowFlow — AI User Factory</title>'
        f"<defs>{gradient}</defs>" + follow + flow + tag +
        "</svg>"
    )


def svg_logo(dark: bool = False) -> str:
    """Lockup: Zeichen + Wortmarke + Zusatzzeile."""
    font = load_static_bold()
    mark_size = 76.0
    pad_x = 6.0
    gap = 22.0
    word_size = 46.0
    baseline = 60.0
    prefix = "ffl" if not dark else "ffld"

    word_x = pad_x + mark_size + gap
    follow, w_follow = svg_wordmark_group(
        font, "Follow", word_size, word_x, baseline,
        "#FFFFFF" if dark else hexc(INK), tracking_em=-0.012,
    )
    flow, w_flow = svg_wordmark_group(
        font, "Flow", word_size, word_x + w_follow, baseline,
        f"url(#{prefix}-word)", tracking_em=-0.012,
    )
    tag, w_tag = svg_wordmark_group(
        font, "AI USER FACTORY", 12.0, word_x + 1.5, baseline + 20.0,
        "#FFFFFF" if dark else hexc(INK_SOFT), tracking_em=0.30,
        opacity=0.62 if dark else 1.0,
    )

    width = math.ceil(max(word_x + w_follow + w_flow, word_x + w_tag) + pad_x + 4)
    height = 96

    mark_scale = mark_size / BOX
    mark_y = (height - mark_size) / 2
    body = (
        f'<g transform="translate({pad_x:.2f} {mark_y:.2f}) scale({mark_scale:.5f})">'
        f'<rect x="0" y="0" width="64" height="64" rx="{BADGE_RADIUS * BOX:.2f}" '
        f'fill="url(#{prefix}-badge)"/>'
        f'<rect x=".5" y=".5" width="63" height="63" rx="{BADGE_RADIUS * BOX - 0.5:.2f}" fill="none" '
        f'stroke="{hexc(PARTICLE_MID)}" stroke-opacity=".16"/>'
        + svg_mark_body(STANDARD, prefix)
        + "</g>"
    )

    word_gradient = (
        f'<linearGradient id="{prefix}-word" x1="{word_x + w_follow:.1f}" y1="{baseline - word_size:.1f}" '
        f'x2="{word_x + w_follow + w_flow:.1f}" y2="{baseline:.1f}" gradientUnits="userSpaceOnUse">'
        f'<stop stop-color="{hexc(WORD_ACCENT_A)}"/>'
        f'<stop offset="1" stop-color="{hexc(WORD_ACCENT_B)}"/>'
        f"</linearGradient>"
    )
    defs = svg_defs(prefix, STANDARD, badge=True).replace("</defs>", word_gradient + "</defs>")

    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" '
        f'width="{width}" height="{height}" role="img" aria-labelledby="{prefix}-t {prefix}-d">'
        f'<title id="{prefix}-t">FollowFlow</title>'
        f'<desc id="{prefix}-d">FollowFlow — AI User Factory</desc>'
        + defs + body + follow + flow + tag +
        "</svg>"
    )


# --------------------------------------------------------------------------
# Raster (Pillow)
# --------------------------------------------------------------------------

SS = 4  # Supersampling


def _core_field(box_px: int, spec: MarkSpec) -> Image.Image:
    """Sphaerenverlauf, exakt nach derselben Formel wie der SVG-Gradient:
    Farbe = f(Abstand zum Lichtpunkt / (r * 1.55))."""
    n = 96
    img = Image.new("RGB", (n, n))
    px = img.load()
    sr = spec.sphere_r
    fx, fy = CX - sr * 0.42, CY - sr * 0.5
    radius = sr * 1.55
    for j in range(n):
        uy = CY - sr + (j + 0.5) / n * 2 * sr
        for i in range(n):
            ux = CX - sr + (i + 0.5) / n * 2 * sr
            px[i, j] = core_color(math.hypot(ux - fx, uy - fy) / radius)
    return img.resize((box_px, box_px), Image.BICUBIC)


def _badge_gradient(size: int) -> Image.Image:
    n = 64
    img = Image.new("RGB", (n, n))
    px = img.load()
    for y in range(n):
        for x in range(n):
            t = (x / (n - 1) + y / (n - 1)) / 2
            if t <= 0.55:
                px[x, y] = mix(BADGE_TOP, BADGE_MID, t / 0.55)
            else:
                px[x, y] = mix(BADGE_MID, BADGE_BOTTOM, (t - 0.55) / 0.45)
    return img.resize((size, size), Image.BICUBIC)


def _halo_layer(size: int, spec: MarkSpec, strength: float) -> Image.Image:
    n = 96
    alpha = Image.new("L", (n, n))
    px = alpha.load()
    for y in range(n):
        for x in range(n):
            dx = (x + 0.5) / n - 0.5
            dy = (y + 0.5) / n - 0.5
            t = math.hypot(dx, dy) * 2.0
            if t >= 1.0:
                px[x, y] = 0
            elif t <= 0.25:
                px[x, y] = int(255 * 0.42 * strength)
            elif t <= 0.62:
                px[x, y] = int(255 * lerp(0.42, 0.16, (t - 0.25) / 0.37) * strength)
            else:
                px[x, y] = int(255 * lerp(0.16, 0.0, (t - 0.62) / 0.38) * strength)
    layer = Image.new("RGBA", (size, size), HALO + (0,))
    box = int(spec.halo_r * 2 / BOX * size)
    a = alpha.resize((box, box), Image.BICUBIC)
    full = Image.new("L", (size, size), 0)
    off = int((size - box) / 2)
    full.paste(a, (off, off))
    layer.putalpha(full)
    return layer


def _stroke_polyline(draw: ImageDraw.ImageDraw, pts, color, width_px: float) -> None:
    """Polylinie mit runden Gelenken. ImageDraw ersetzt Pixel statt zu mischen —
    deshalb gehoert jeder halbtransparente Zug auf eine eigene Ebene."""
    w = max(1, int(round(width_px)))
    draw.line(pts, fill=color, width=w, joint="curve")
    if w > 2:
        r = w / 2
        for x, y in pts[:: max(1, len(pts) // 60)] + [pts[-1]]:
            draw.ellipse([x - r, y - r, x + r, y + r], fill=color)


def _orbit_stroke(draw: ImageDraw.ImageDraw, orbit: Orbit, unit: float, front: bool,
                  monochrome: bool) -> None:
    """Bahn als Segmentkette. Vorn laeuft die Deckkraft wie der SVG-Gradient
    von den Enden zur Mitte hoch, hinten bleibt sie flach und zurueckgenommen."""
    if not front:
        pts = [
            (x * unit, y * unit)
            for x, y, _ in (orbit.point(0.5 + 0.5 * i / 96) for i in range(97))
        ]
        rgb = (255, 255, 255) if monochrome else ORBIT
        _stroke_polyline(draw, pts, rgb + (int(255 * orbit.opacity * 0.33),), orbit.width * 0.8 * unit)
        return

    steps = 96
    for i in range(steps):
        ta, tb = 0.5 * i / steps, 0.5 * (i + 1) / steps
        xa, ya, _ = orbit.point(ta)
        xb, yb, _ = orbit.point(tb)
        # Gradientposition wie im SVG: normalisierte x-Lage im lokalen Rahmen.
        g = (1 + math.cos(2 * math.pi * (ta + tb) / 2)) / 2
        if g < 0.5:
            k = g / 0.5
            alpha, rgb = lerp(0.15, 1.0, k), mix(ORBIT, ORBIT_FRONT, k)
        else:
            k = (g - 0.5) / 0.5
            alpha, rgb = lerp(1.0, 0.30, k), mix(ORBIT_FRONT, ORBIT, k)
        if monochrome:
            rgb = (255, 255, 255)
        _stroke_polyline(
            draw, [(xa * unit, ya * unit), (xb * unit, yb * unit)],
            rgb + (int(255 * alpha * orbit.opacity),), orbit.width * unit,
        )


def _ellipse_polyline(cx: float, cy: float, rx: float, ry: float, unit: float, steps: int = 96):
    return [
        ((cx + rx * math.cos(2 * math.pi * i / steps)) * unit,
         (cy + ry * math.sin(2 * math.pi * i / steps)) * unit)
        for i in range(steps + 1)
    ]


def render_mark(size: int, spec: MarkSpec = STANDARD, badge: bool = True, phase: float = 0.0,
                monochrome: bool = False, inset: float = 1.0, pulse: float = 1.0) -> Image.Image:
    """Zeichen als RGBA-Bild. `inset` < 1 haelt Maskable-Sicherheitszonen frei."""
    s = size * SS
    canvas = Image.new("RGBA", (s, s), (0, 0, 0, 0))

    if badge and not monochrome:
        grad = _badge_gradient(s)
        mask = Image.new("L", (s, s), 0)
        ImageDraw.Draw(mask).rounded_rectangle(
            [0, 0, s - 1, s - 1], radius=int(BADGE_RADIUS * s), fill=255
        )
        canvas.paste(grad, (0, 0), mask)

    # Zeichenflaeche (fuer maskable verkleinert)
    inner = int(s * inset)
    art = Image.new("RGBA", (inner, inner), (0, 0, 0, 0))
    unit = inner / BOX

    def P(x: float, y: float) -> tuple[float, float]:
        return (x * unit, y * unit)

    def blank() -> tuple[Image.Image, ImageDraw.ImageDraw]:
        img = Image.new("RGBA", (inner, inner), (0, 0, 0, 0))
        return img, ImageDraw.Draw(img)

    def particles(layer_draw: ImageDraw.ImageDraw, front: bool) -> None:
        for p in spec.particles:
            if p.fixed is not None:
                if front:
                    continue
                x, y, depth = p.fixed[0], p.fixed[1], 0.0
            else:
                x, y, depth = spec.orbits[p.orbit].point((p.t + phase) % 1.0)
                if (depth < 0) == front:
                    continue
            if depth < 0 and math.hypot(x - CX, y - CY) < spec.sphere_r + p.r:
                continue
            scale = 1.0 if depth >= 0 else 0.72
            alpha = p.opacity if depth >= 0 else p.opacity * 0.5
            rgb = (255, 255, 255) if monochrome else p.color
            r = p.r * scale * unit
            px, py = P(x, y)
            layer_draw.ellipse([px - r, py - r, px + r, py + r], fill=rgb + (int(255 * alpha),))

    if badge and not monochrome:
        art.alpha_composite(_halo_layer(inner, spec, pulse))

    back, back_draw = blank()
    for orbit in spec.orbits:
        _orbit_stroke(back_draw, orbit, unit, front=False, monochrome=monochrome)
    particles(back_draw, front=False)
    art.alpha_composite(back)

    sr_u = spec.sphere_r
    sr = sr_u * unit
    cx, cy = P(CX, CY)
    sphere, sphere_draw = blank()
    if monochrome:
        sphere_draw.ellipse([cx - sr, cy - sr, cx + sr, cy + sr], fill=(255, 255, 255, 255))
    else:
        box = max(2, int(round(sr * 2)))
        core = _core_field(box, spec).convert("RGBA")
        cmask = Image.new("L", (box * 4, box * 4), 0)
        ImageDraw.Draw(cmask).ellipse([0, 0, box * 4 - 1, box * 4 - 1], fill=255)
        core.putalpha(cmask.resize((box, box), Image.LANCZOS))
        sphere.alpha_composite(core, (int(round(cx - sr)), int(round(cy - sr))))

        if spec.meridians:
            grid, grid_draw = blank()
            _stroke_polyline(
                grid_draw,
                _ellipse_polyline(CX, CY, sr_u * MERIDIAN_RX, sr_u, unit),
                PARTICLE_LIGHT + (int(255 * 0.26),), 0.7 * unit,
            )
            _stroke_polyline(
                grid_draw,
                _ellipse_polyline(CX, CY - sr_u * LATITUDE_DY, sr_u * LATITUDE_RX,
                                  sr_u * LATITUDE_RY, unit),
                PARTICLE_LIGHT + (int(255 * 0.22),), 0.6 * unit,
            )
            clip = Image.new("L", (inner, inner), 0)
            ImageDraw.Draw(clip).ellipse([cx - sr, cy - sr, cx + sr, cy + sr], fill=255)
            grid.putalpha(Image.composite(grid.getchannel("A"), Image.new("L", (inner, inner), 0), clip))
            sphere.alpha_composite(grid)

            hx, hy = P(CX - sr_u * 0.40, CY - sr_u * 0.50)
            hr_x, hr_y = sr_u * 0.22 * unit, sr_u * 0.14 * unit
            gloss, gloss_draw = blank()
            gloss_draw.ellipse([hx - hr_x, hy - hr_y, hx + hr_x, hy + hr_y],
                               fill=(255, 255, 255, 158))
            gloss = gloss.rotate(30, center=(hx, hy), resample=Image.BICUBIC)
            gloss = gloss.filter(ImageFilter.GaussianBlur(radius=max(1.0, unit * 0.45)))
            sphere.alpha_composite(gloss)
    art.alpha_composite(sphere)

    front, front_draw = blank()
    for orbit in spec.orbits:
        _orbit_stroke(front_draw, orbit, unit, front=True, monochrome=monochrome)
    particles(front_draw, front=True)
    art.alpha_composite(front)

    off = int((s - inner) / 2)
    canvas.alpha_composite(art, (off, off))
    return canvas.resize((size, size), Image.LANCZOS)


def render_wordmark_png(height: int, dark: bool) -> Image.Image:
    """Rasterlogo aus denselben Pfaden — fuer Mail und Fremdsysteme ohne SVG."""
    import io

    from PIL import ImageFont

    buffer = io.BytesIO()
    load_static_bold().save(buffer)

    def sized(px: int):
        buffer.seek(0)
        return ImageFont.truetype(buffer, px)

    scale = height / 96
    mark_px = int(76 * scale)
    img = Image.new("RGBA", (int(560 * scale), height), (0, 0, 0, 0))
    img.alpha_composite(render_mark(mark_px), (int(6 * scale), int((height - mark_px) / 2)))

    draw = ImageDraw.Draw(img)
    word_font = sized(int(46 * scale))
    tag_font = sized(int(12 * scale))
    x = int((6 + 76 + 22) * scale)
    baseline = int(60 * scale)

    follow = "Follow"
    draw.text((x, baseline), follow, font=word_font, anchor="ls",
              fill=(255, 255, 255, 255) if dark else INK + (255,))
    x2 = x + int(draw.textlength(follow, font=word_font))
    draw.text((x2, baseline), "Flow", font=word_font, anchor="ls", fill=WORD_ACCENT_A + (255,))

    tx = x + int(1.5 * scale)
    for i, ch in enumerate("AI USER FACTORY"):
        draw.text((tx, baseline + int(20 * scale)), ch, font=tag_font, anchor="ls",
                  fill=(255, 255, 255, 160) if dark else INK_SOFT + (255,))
        tx += int(draw.textlength(ch, font=tag_font) + 12 * 0.30 * scale)

    right = max(x2 + int(draw.textlength("Flow", font=word_font)), tx) + int(6 * scale)
    return img.crop((0, 0, right, height))


# --------------------------------------------------------------------------
# ICO / GIF
# --------------------------------------------------------------------------

def write_ico(path: Path, sizes=(16, 32, 48, 64)) -> None:
    """Pillow verwirft ICO-Groessen, die groesser als das Basisbild sind —
    das groesste Bild muss deshalb das Basisbild sein."""
    ordered = sorted(sizes, reverse=True)
    images = [render_mark(size, spec=COMPACT if size <= 32 else STANDARD) for size in ordered]
    images[0].save(
        path, format="ICO",
        sizes=[(size, size) for size in ordered],
        append_images=images[1:],
    )


def build_gif(path: Path, size: int = 128, frames: int = 30, duration: int = 66) -> None:
    """Animiertes Icon: Partikel fliessen, Halo atmet. Rechteckig — die runde
    Ecke setzt das Layout per CSS, weil GIF nur 1-Bit-Transparenz kennt."""
    seq = []
    for i in range(frames):
        phase = i / frames
        pulse = 0.82 + 0.18 * math.sin(2 * math.pi * phase * 2)
        frame = render_mark(size, phase=phase, pulse=pulse)
        flat = Image.new("RGB", (size, size), BADGE_BOTTOM)
        flat.paste(frame, (0, 0), frame)
        seq.append(flat)

    # Eine gemeinsame Palette fuer alle Bilder — sonst flackert der Verlauf.
    master = Image.new("RGB", (size * 4, size))
    for i, idx in enumerate((0, frames // 4, frames // 2, 3 * frames // 4)):
        master.paste(seq[idx], (i * size, 0))
    palette = master.quantize(colors=220, method=Image.MEDIANCUT)

    # Ohne Dithering: die Flaechen sind Verlaeufe, gestreute Pixel wuerden hier
    # nur Rauschen erzeugen und die Datei mehr als verdoppeln.
    quantized = [f.quantize(palette=palette, dither=Image.NONE) for f in seq]
    quantized[0].save(
        path, save_all=True, append_images=quantized[1:], loop=0,
        duration=duration, disposal=1, optimize=True,
    )


# --------------------------------------------------------------------------
# Ausgabe
# --------------------------------------------------------------------------

def write(path: Path, data: str | bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    mode = "wb" if isinstance(data, bytes) else "w"
    with open(path, mode, **({} if isinstance(data, bytes) else {"encoding": "utf-8", "newline": "\n"})) as fh:
        fh.write(data)
    print(f"  {path.relative_to(ROOT)}")


def main() -> int:
    if not FONT_PATH.exists():
        print(f"Quicksand nicht gefunden: {FONT_PATH}", file=sys.stderr)
        return 1

    print("SVG")
    write(BRAND / "followflow-mark.svg", svg_mark())
    write(BRAND / "followflow-mark-plain.svg", svg_mark(badge=False, prefix="ffp"))
    write(BRAND / "followflow-logo.svg", svg_logo(dark=False))
    write(BRAND / "followflow-logo-light.svg", svg_logo(dark=True))
    write(BRAND / "followflow-wordmark.svg", svg_wordmark(dark=False))
    write(BRAND / "followflow-wordmark-light.svg", svg_wordmark(dark=True))
    write(PUBLIC / "favicon.svg", svg_mark(spec=COMPACT, prefix="fav"))

    print("PNG")
    png_targets = {
        ICONS / "pwa-192.png": (192, STANDARD, 1.0),
        ICONS / "pwa-512.png": (512, STANDARD, 1.0),
        ICONS / "pwa-maskable-512.png": (512, STANDARD, 0.62),
        ICONS / "apple-touch-icon-180.png": (180, STANDARD, 1.0),
        LEGACY_FAVICON / "android-chrome-192x192.png": (192, STANDARD, 1.0),
        LEGACY_FAVICON / "android-chrome-512x512.png": (512, STANDARD, 1.0),
        LEGACY_FAVICON / "apple-touch-icon.png": (180, STANDARD, 1.0),
        LEGACY_FAVICON / "favicon-32x32.png": (32, COMPACT, 1.0),
        LEGACY_FAVICON / "favicon-16x16.png": (16, COMPACT, 1.0),
    }
    for target, (size, spec, inset) in png_targets.items():
        target.parent.mkdir(parents=True, exist_ok=True)
        render_mark(size, spec=spec, inset=inset).save(target, format="PNG", optimize=True)
        print(f"  {target.relative_to(ROOT)}")

    badge = render_mark(96, spec=COMPACT, badge=False, monochrome=True)
    badge.save(ICONS / "push-badge-96.png", format="PNG", optimize=True)
    print(f"  {(ICONS / 'push-badge-96.png').relative_to(ROOT)}")

    for target, dark in ((BRAND / "followflow-logo.png", False),
                         (BRAND / "followflow-logo-light.png", True)):
        render_wordmark_png(192, dark=dark).save(target, format="PNG", optimize=True)
        print(f"  {target.relative_to(ROOT)}")

    print("ICO")
    write_ico(PUBLIC / "favicon.ico")
    print(f"  public/favicon.ico")
    write_ico(LEGACY_FAVICON / "favicon.ico")
    print(f"  public/site-images/favicon/favicon.ico")

    print("GIF")
    build_gif(BRAND / "followflow-mark-animated.gif")
    print(f"  public/site-images/brand/followflow-mark-animated.gif")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
