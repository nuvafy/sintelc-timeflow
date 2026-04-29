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

            {{-- Tabla --}}
            <livewire:dashboard.attendance-table />

            {{-- Dispositivos --}}
            <livewire:dashboard.device-status />

        </div>
    </div>
</x-app-layout>
