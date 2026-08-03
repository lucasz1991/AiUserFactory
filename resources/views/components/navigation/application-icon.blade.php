@props(['class' => null, 'animated' => false, 'mode' => 'always'])

@if ($animated)
    <x-branding.animated-mark :class="$class" :mode="$mode" />
@else
    <img
        class="{{ $class }}"
        src="{{ asset('/site-images/brand/followflow-mark.svg') }}"
        alt="FollowFlow">
@endif
