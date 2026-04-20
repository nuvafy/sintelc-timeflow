<?php

use App\Models\AttendanceLog;
use Livewire\Volt\Component;

new class extends Component {
    public int $todayTotal = 0;
    public int $pendingSync = 0;
    public int $failedSync = 0;
    public int $syncedToday = 0;

    public function mount(): void
    {
        $this->todayTotal  = AttendanceLog::whereDate('occurred_at', today())->count();
        $this->pendingSync = AttendanceLog::where('sync_status', 'pending')->count();
        $this->failedSync  = AttendanceLog::where('sync_status', 'failed')->count();
        $this->syncedToday = AttendanceLog::whereDate('occurred_at', today())->where('sync_status', 'synced')->count();
    }
}; ?>

<div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
    <div class="bg-white overflow-hidden shadow rounded-lg p-5">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-indigo-500 rounded-md p-3">
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <div class="ml-5">
                <p class="text-sm font-medium text-gray-500">Registros hoy</p>
                <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $todayTotal }}</p>
            </div>
        </div>
    </div>

    <div class="bg-white overflow-hidden shadow rounded-lg p-5">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div class="ml-5">
                <p class="text-sm font-medium text-gray-500">Sincronizados hoy</p>
                <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $syncedToday }}</p>
            </div>
        </div>
    </div>

    <div class="bg-white overflow-hidden shadow rounded-lg p-5">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3">
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="ml-5">
                <p class="text-sm font-medium text-gray-500">Pendientes de sync</p>
                <p class="mt-1 text-3xl font-semibold text-gray-900">{{ $pendingSync }}</p>
            </div>
        </div>
    </div>

    <div class="bg-white overflow-hidden shadow rounded-lg p-5">
        <div class="flex items-center">
            <div class="flex-shrink-0 bg-red-500 rounded-md p-3">
                <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="ml-5">
                <p class="text-sm font-medium text-gray-500">Errores de sync</p>
                <p class="mt-1 text-3xl font-semibold {{ $failedSync > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $failedSync }}</p>
            </div>
        </div>
    </div>
</div>
