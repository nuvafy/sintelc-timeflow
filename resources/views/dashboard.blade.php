<x-app-layout>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Stats --}}
            <livewire:dashboard.attendance-stats />

            {{-- Dispositivos --}}
            <livewire:dashboard.device-status />

        </div>
    </div>
</x-app-layout>
