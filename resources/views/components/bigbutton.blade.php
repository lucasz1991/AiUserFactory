<a   {!! $attributes->merge(['class' => 'transition-all duration-100 inline-flex items-center justify-center px-5 py-3 text-base font-medium text-center text-gray-900 border border-gray-300 rounded-lg bg-white hover:bg-gray-200 focus:ring-4 focus:ring-gray-100 ']) !!} x-data="{ isClicked: false }" 
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    {{ $slot }}          
</a> 