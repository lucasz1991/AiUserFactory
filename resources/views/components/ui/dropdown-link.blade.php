<a
    {{ $attributes->merge([
        'role' => 'menuitem',
        'class' => 'inline-flex w-full items-center px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500/40',
    ]) }}
>
    {{ $slot }}
</a>
