@props([
    /** always = laeuft dauerhaft, hover = bewegt sich erst bei Maus/Fokus */
    'mode' => 'hover',
    'alt' => 'FollowFlow',
])

{{--
    Animiertes Markenzeichen. Das statische SVG traegt die Darstellung, das GIF
    liegt deckungsgleich darueber und wird nur eingeblendet. Damit bleibt das
    Zeichen scharf, wenn Bewegung unerwuenscht oder das GIF noch nicht geladen
    ist, und `prefers-reduced-motion` schaltet die Bewegung vollstaendig ab.
--}}

@once
    <style>
        .ff-anim-mark { position: relative; display: block; line-height: 0; }
        .ff-anim-mark > img { display: block; width: 100%; height: 100%; object-fit: contain; }
        .ff-anim-mark__motion {
            position: absolute;
            inset: 0;
            opacity: 0;
            border-radius: 28%;
            transition: opacity .3s ease;
        }
        .ff-anim-mark--always .ff-anim-mark__motion { opacity: 1; }
        .ff-anim-mark--hover:hover .ff-anim-mark__motion,
        .ff-anim-mark--hover:focus-visible .ff-anim-mark__motion,
        a:hover .ff-anim-mark--hover .ff-anim-mark__motion,
        a:focus-visible .ff-anim-mark--hover .ff-anim-mark__motion { opacity: 1; }
        @media (prefers-reduced-motion: reduce) {
            .ff-anim-mark__motion { display: none; }
        }
    </style>
@endonce

<span {{ $attributes->class(['ff-anim-mark', 'ff-anim-mark--'.$mode]) }}>
    <img
        src="{{ asset('/site-images/brand/followflow-mark.svg') }}"
        alt="{{ $alt }}"
        class="ff-anim-mark__static"
        width="64"
        height="64"
    >
    <img
        src="{{ asset('/site-images/brand/followflow-mark-animated.gif') }}"
        alt=""
        aria-hidden="true"
        class="ff-anim-mark__motion"
        width="128"
        height="128"
        decoding="async"
    >
</span>
