{{-- Marken-/Visualseite der Auth-Strecke (FollowFlow) – nur Marke, keine Systeminfos --}}
<div class="relative h-full min-h-screen select-none overflow-hidden bg-gradient-to-br from-[#2E1065] via-[#4C1D95] to-[#7C3AED]">
    {{-- weiche, schwebende Lichtflecken --}}
    <div class="pointer-events-none absolute inset-0">
        <span class="af-orb absolute -top-24 -left-16 h-80 w-80 rounded-full bg-white/10 blur-3xl"></span>
        <span class="af-orb af-orb--slow absolute top-1/3 -right-24 h-[26rem] w-[26rem] rounded-full bg-fuchsia-500/25 blur-3xl"></span>
        <span class="af-orb af-orb--rev absolute -bottom-28 left-1/4 h-[24rem] w-[24rem] rounded-full bg-violet-400/30 blur-3xl"></span>
    </div>

    {{-- feines Raster --}}
    <div class="pointer-events-none absolute inset-0 opacity-[0.10]"
         style="background-image:linear-gradient(rgba(255,255,255,.6) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.6) 1px,transparent 1px);background-size:46px 46px;"></div>

    {{-- Markenlockup: animiertes Zeichen + Name --}}
    <div class="relative z-10 flex h-full min-h-screen flex-col items-center justify-center p-12 text-center text-white">
        <x-branding.animated-mark
            mode="always"
            class="h-28 w-28 rounded-[1.6rem] shadow-2xl shadow-black/40 ring-1 ring-white/25"
        />
        {{-- Das echte helle Schriftlogo, nicht gesetzter Text: das Zeichen
             steht bereits gross darueber, deshalb die Fassung ohne Badge. --}}
        <h1 class="mt-9">
            <img
                src="{{ asset('/site-images/brand/followflow-wordmark-light.svg') }}"
                alt="FollowFlow — AI User Factory"
                class="h-20 w-auto"
                width="248"
                height="72"
            >
        </h1>
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
