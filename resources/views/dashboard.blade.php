<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Dashboard
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Stats --}}
            <livewire:dashboard.attendance-stats />

            {{-- Tabla y dispositivos --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <livewire:dashboard.attendance-table />
                </div>
                <div>
                    <livewire:dashboard.device-status />
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
