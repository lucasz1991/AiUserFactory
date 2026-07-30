@props(['class' => null])

@php($base = $class ?: 'h-auto w-full max-w-56')

{{-- Zwei Fassungen: dunkle Wortmarke auf hellem Grund, helle im Dunkelmodus. --}}
<img
    class="{{ $base }} dark:hidden"
    src="{{ asset('/site-images/brand/followflow-logo.svg') }}"
    alt="FollowFlow — AI User Factory">
<img
    class="{{ $base }} hidden dark:block"
    src="{{ asset('/site-images/brand/followflow-logo-light.svg') }}"
    alt=""
    aria-hidden="true">
