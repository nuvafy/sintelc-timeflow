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

            {{-- Tabla + Dispositivos (mismo grid que stats: 4 cols, tabla=3, devices=1) --}}
            <div class="grid items-start gap-5" style="grid-template-columns: 3fr 1fr">
                <livewire:dashboard.attendance-table />
                <livewire:dashboard.device-status />
            </div>

        </div>
    </div>
</x-app-layout>
