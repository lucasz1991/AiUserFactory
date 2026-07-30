@props(['class' => null, 'animated' => false])

@if ($animated)
    <x-branding.animated-mark :class="$class" mode="hover" />
@else
    <img
        class="{{ $class }}"
        src="{{ asset('/site-images/brand/followflow-mark.svg') }}"
        alt="FollowFlow">
@endif
