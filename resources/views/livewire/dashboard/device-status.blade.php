<?php

use App\Models\BiometricSource;
use App\Models\AttendanceLog;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $devices = BiometricSource::withCount([
            'attendanceLogs as today_count' => fn($q) => $q->whereDate('occurred_at', today()),
            'attendanceLogs as total_count',
        ])->get();

        return ['devices' => $devices];
    }
}; ?>

<div class="bg-white shadow rounded-lg">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-medium text-gray-900">Dispositivos biométricos</h3>
    </div>

    <div class="divide-y divide-gray-200">
        @forelse($devices as $device)
        <div class="px-6 py-4 flex items-center justify-between">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    @php $isActive = $device->status === 'active'; @endphp
                    <span class="h-3 w-3 rounded-full inline-block {{ $isActive ? 'bg-green-400' : 'bg-gray-300' }}"></span>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-900">{{ $device->name }}</p>
                    <p class="text-xs text-gray-500">SN: {{ $device->serial_number }} &middot; {{ $device->site_name }}</p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-sm font-semibold text-gray-900">{{ $device->today_count }} hoy</p>
                <p class="text-xs text-gray-500">{{ $device->total_count }} total</p>
            </div>
        </div>
        @empty
        <div class="px-6 py-10 text-center text-sm text-gray-500">No hay dispositivos registrados.</div>
        @endforelse
    </div>
</div>
