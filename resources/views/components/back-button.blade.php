<a 
    onclick="window.history.back()"  
    class="shadow transition-all duration-100 inline-flex items-center px-2 py-1 text-sm border border-gray-300 bg-gray-100 text-gray-900 rounded hover:bg-gray-200 cursor-pointer"
            x-data="{ isClicked: false }" 
            @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
            style="transform:scale(1);"
            :style="isClicked ? 'transform:scale(0.7);' : ''"
    >
    ← Zurück
</a>
