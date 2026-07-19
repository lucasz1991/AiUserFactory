{{-- Marken-/Visualseite der Auth-Strecke (Factory AI) – nur Marke, keine Systeminfos --}}
<div class="relative h-full min-h-screen select-none overflow-hidden bg-gradient-to-br from-primary-base via-[#284a82] to-secondary-base">
    {{-- weiche, schwebende Lichtflecken --}}
    <div class="pointer-events-none absolute inset-0">
        <span class="af-orb absolute -top-24 -left-16 h-80 w-80 rounded-full bg-white/10 blur-3xl"></span>
        <span class="af-orb af-orb--slow absolute top-1/3 -right-24 h-[26rem] w-[26rem] rounded-full bg-secondary-base/30 blur-3xl"></span>
        <span class="af-orb af-orb--rev absolute -bottom-28 left-1/4 h-[24rem] w-[24rem] rounded-full bg-sky-400/30 blur-3xl"></span>
    </div>

    {{-- feines Raster --}}
    <div class="pointer-events-none absolute inset-0 opacity-[0.10]"
         style="background-image:linear-gradient(rgba(255,255,255,.6) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.6) 1px,transparent 1px);background-size:46px 46px;"></div>

    {{-- Markenlockup: Icon + Name --}}
    <div class="relative z-10 flex h-full min-h-screen flex-col items-center justify-center p-12 text-center text-white">
        <div class="flex h-24 w-24 items-center justify-center rounded-[1.4rem] bg-white shadow-2xl shadow-black/25 ring-1 ring-white/40">
            <img src="{{ asset('/site-images/brand/factory-ai-mark.svg') }}" alt="Factory AI" class="h-16 w-16">
        </div>
        <h1 class="mt-8 text-4xl font-bold tracking-tight">Factory&nbsp;AI</h1>
        <p class="mt-2.5 text-13 font-semibold uppercase tracking-[0.35em] text-white/70">User Factory</p>
    </div>

    <style>
        @keyframes afFloat {
            0%, 100% { transform: translateY(0) translateX(0); }
            50%      { transform: translateY(-30px) translateX(12px); }
        }
        .af-orb { animation: afFloat 15s ease-in-out infinite; }
        .af-orb--slow { animation-duration: 21s; }
        .af-orb--rev  { animation-direction: reverse; animation-duration: 18s; }
        @media (prefers-reduced-motion: reduce) { .af-orb { animation: none; } }
    </style>
</div>
