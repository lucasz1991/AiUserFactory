@props([])

<div x-data="{ open: false }" >
    <div class="mb-6 bg-gray-100 px-4 py-2 text-gray-700 rounded-lg focus:outline-none">
        <button 
            @click="open = !open" 
            class="w-full flex justify-between items-center"
        >
            <span class="font-semibold"> {{ $trigger }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" :class="{'rotate-180': open}" class="w-5 h-5 transition-transform transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div 
                x-show="open" x-cloak x-collapse.duration.400ms
                class="overflow-hidden"
            >
            <div class="p-3 mt-6 pt-6 border-t border-gray-300">
                {{ $content }}
            </div>
        </div>
    </div>
</div>
