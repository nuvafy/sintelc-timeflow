<?php

use App\Models\BiometricSource;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $devices = BiometricSource::with(['client', 'location'])
            ->latest()
            ->limit(5)
            ->get();

        return ['devices' => $devices];
    }
}; ?>

<div class="bg-white shadow rounded-lg h-full">
    <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-900">Últimos biométricos agregados</h3>
        <a href="{{ route('devices') }}" wire:navigate class="text-xs text-indigo-600 hover:text-indigo-800">Ver todos →</a>
    </div>

    <div class="divide-y divide-gray-100">
        @forelse($devices as $device)
        @php $isActive = $device->status === 'active'; @endphp
        <div class="px-5 py-4 flex items-start gap-3">
            {{-- Icono dispositivo --}}
            <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center mt-0.5">
                <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ $device->name ?? $device->serial_number }}</p>
                    <span class="h-2 w-2 rounded-full flex-shrink-0 {{ $isActive ? 'bg-green-400' : 'bg-gray-300' }}"></span>
                </div>
                <p class="text-xs text-gray-500 truncate mt-0.5">{{ $device->client?->name ?? 'Sin empresa' }}</p>
                <p class="text-xs text-gray-400 truncate">{{ $device->location?->name ?? 'Sin ubicación' }}</p>
                <p class="text-xs text-gray-300 font-mono mt-1">{{ $device->created_at->diffForHumans() }}</p>
            </div>
        </div>
        @empty
        <div class="px-5 py-10 text-center text-sm text-gray-400">Sin dispositivos registrados.</div>
        @endforelse
    </div>
</div>
