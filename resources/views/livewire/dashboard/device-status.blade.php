<?php

use App\Models\BiometricSource;
use App\Models\AttendanceLog;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $devices = BiometricSource::with(['client', 'location'])
            ->withCount([
                'attendanceLogs as today_count' => fn($q) => $q->whereDate('occurred_at', today()),
                'attendanceLogs as total_count',
            ])->get();

        return ['devices' => $devices];
    }
}; ?>

<div class="bg-white shadow rounded-lg">
    <div class="px-4 py-4 border-b border-gray-200">
        <h3 class="text-sm font-medium text-gray-900">Dispositivos biométricos</h3>
    </div>

    <div class="divide-y divide-gray-200">
        @forelse($devices as $device)
        @php $isActive = $device->status === 'active'; @endphp
        <div class="px-4 py-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <span class="h-2.5 w-2.5 rounded-full flex-shrink-0 {{ $isActive ? 'bg-green-400' : 'bg-gray-300' }}"></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate">{{ $device->name ?? $device->serial_number }}</p>
                    <p class="text-xs text-gray-500 truncate">{{ $device->client?->name ?? 'Sin empresa' }} · {{ $device->location?->name ?? 'Sin ubicación' }}</p>
                    <p class="text-xs text-gray-400 font-mono">{{ $device->serial_number }}</p>
                </div>
            </div>
            <div class="text-right flex-shrink-0">
                <p class="text-sm font-semibold text-gray-900">{{ $device->today_count }} <span class="text-xs font-normal text-gray-400">hoy</span></p>
                <p class="text-xs text-gray-400">{{ $device->total_count }} total</p>
            </div>
        </div>
        @empty
        <div class="px-4 py-8 text-center text-sm text-gray-500">Sin dispositivos.</div>
        @endforelse
    </div>
</div>
