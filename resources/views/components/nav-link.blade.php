@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center max-md:px-3 max-md:py-2 max-md:flex max-md:text-lg max-md:w-full max-md:rounded-lg px-1 pt-1 max-md:py-1 max-md:bg-gray-100 md:border-b-2 md:border-primary-500 text-sm font-medium leading-5 text-gray-900 focus:outline-none focus:border-primary-700 transition duration-150 ease-in-out inline-flex items-center'
            : 'inline-flex items-center max-md:px-3 max-md:py-2 max-md:flex max-md:text-lg max-md:w-full max-md:rounded-lg px-1 pt-1 max-md:py-1 max-md:hover:bg-gray-100 md:border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 hover:text-gray-700 md:hover:border-gray-300 focus:outline-none focus:text-gray-700 focus:border-gray-300 transition duration-150 ease-in-out inline-flex items-center';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} >
    {{ $slot }}
</a>
