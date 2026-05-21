<x-app-layout>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="flex gap-5 items-start">

                {{-- Izquierda: últimos biométricos --}}
                <div class="w-72 flex-shrink-0">
                    <livewire:dashboard.device-status />
                </div>

                {{-- Derecha: 2×2 donuts --}}
                <div class="flex-1 grid grid-cols-2 gap-5">
                    <livewire:dashboard.attendance-stats />
                </div>

            </div>

        </div>
    </div>
</x-app-layout>
