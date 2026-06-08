<button 
    {{ $attributes }}    x-data="{ isClicked: false }" 
    @mousedown="isClicked = true" 
    @mouseup="isClicked = false" 
    @mouseleave="isClicked = false" 
    style="transform:scale(1);" 
    :style="isClicked ? 'transform:scale(0.9);' : ''"
>
    {{ $slot }}   
</button>
