<button   {!! $attributes->merge(['class' => 'transition-all duration-100 inline-flex items-center p-4 justify-center bg-green-100  text-base font-medium text-center text-green-900 border border-green-300 rounded-full aspect-square hover:bg-green-200 focus:ring-4 focus:ring-green-100 ']) !!} x-data="{ isClicked: false }" 
    @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
    style="transform:scale(1);"
    :style="isClicked ? 'transform:scale(0.9);' : ''">
    <svg class="text-green-400 fill-green-400 h-6 aspect-square" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 648.63399 645.8089"><polygon fill="currentColor" points="391.537 0 0 0 257.097 322.904 0 645.809 391.537 645.809 648.634 322.904 391.537 0"/></svg>
</button>
