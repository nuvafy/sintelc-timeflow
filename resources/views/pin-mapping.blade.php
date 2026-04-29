<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Mapeo de PINs biométricos
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <livewire:devices.pin-mapper :sourceId="request('source')" />
        </div>
    </div>
</x-app-layout>
