{{-- Marken-/Visualseite der Auth-Strecke (Factory AI) --}}
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

    {{-- Inhalt --}}
    <div class="relative z-10 flex h-full min-h-screen flex-col justify-between p-12 text-white xl:p-16">
        <div class="flex items-center gap-2.5 text-xs font-semibold uppercase tracking-[0.22em] text-white/75">
            <span class="h-2 w-2 rounded-full bg-secondary-base shadow-[0_0_12px_2px_rgba(51,160,67,0.7)]"></span>
            Factory AI · User Factory
        </div>

        <div class="max-w-xl">
            <h1 class="text-3xl font-bold leading-tight xl:text-[2.7rem]">
                Deine autonome<br>User&nbsp;Factory.
            </h1>
            <p class="mt-5 max-w-md text-base text-white/80 xl:text-lg">
                Personenprofile, Browser-Sessions, Workflows und Automationen &mdash;
                zentral gesteuert, vollstaendig kontrolliert.
            </p>

            <div class="mt-9 flex flex-wrap gap-2.5">
                @foreach (['Personenprofile', 'Browser-Sessions', 'Workflow-Studio', 'Automationen'] as $chip)
                    <span class="rounded-full border border-white/20 bg-white/10 px-4 py-1.5 text-13 font-medium backdrop-blur-sm">
                        {{ $chip }}
                    </span>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-2 text-sm text-white/55">
            <span class="mdi mdi-shield-check-outline text-base"></span>
            Sichere Verwaltung &middot; Rollenbasierter Zugriff
        </div>
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
