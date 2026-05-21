<?php

use App\Models\AttendanceLog;
use App\Jobs\SyncAttendanceToFactorial;
use Livewire\Volt\Component;

new class extends Component {
    public int $todayTotal = 0;
    public int $pendingSync = 0;
    public int $failedSync = 0;
    public int $syncedToday = 0;

    public function mount(): void
    {
        $this->loadStats();
    }

    public function loadStats(): void
    {
        $this->todayTotal  = AttendanceLog::whereDate('occurred_at', today())->count();
        $this->pendingSync = AttendanceLog::where('sync_status', 'pending')->count();
        $this->failedSync  = AttendanceLog::where('sync_status', 'failed')->count();
        $this->syncedToday = AttendanceLog::whereDate('processed_at', today())->where('sync_status', 'synced')->count();
    }

    public function dismissFailed(): void
    {
        AttendanceLog::where('sync_status', 'failed')->delete();
        $this->loadStats();
    }

    public function retryFailed(): void
    {
        $delay = 0;

        AttendanceLog::where('sync_status', 'failed')
            ->chunkById(50, function ($logs) use (&$delay) {
                $ids = $logs->pluck('id');

                AttendanceLog::whereIn('id', $ids)->update([
                    'sync_status' => 'pending',
                    'sync_error'  => null,
                ]);

                foreach ($ids as $id) {
                    SyncAttendanceToFactorial::dispatch($id)->delay(now()->addSeconds($delay));
                    $delay += 3;
                }
            });

        $this->loadStats();
    }
}; ?>

@php
    $r             = 54;
    $circumference = 2 * M_PI * $r;
    $startOffset   = $circumference / 4;
    $donutTotal    = $syncedToday + $pendingSync + $failedSync;

    if ($donutTotal > 0) {
        $syncedLen  = ($syncedToday / $donutTotal) * $circumference;
        $pendingLen = ($pendingSync / $donutTotal) * $circumference;
        $failedLen  = ($failedSync  / $donutTotal) * $circumference;
    } else {
        $syncedLen = $pendingLen = $failedLen = 0;
    }

    $offset1 = $startOffset;
    $offset2 = $startOffset - $syncedLen;
    $offset3 = $startOffset - $syncedLen - $pendingLen;
@endphp

<div class="bg-white shadow rounded-lg p-5">
    <div class="flex items-center gap-8">

        {{-- Dona SVG --}}
        <div class="flex-shrink-0">
            <svg width="130" height="130" viewBox="0 0 120 120">
                @if($donutTotal === 0)
                    <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="14"/>
                @else
                    <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#f3f4f6" stroke-width="14"/>

                    @if($syncedLen > 0)
                    <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#22c55e" stroke-width="14"
                        stroke-dasharray="{{ number_format($syncedLen, 2) }} {{ number_format($circumference, 2) }}"
                        stroke-dashoffset="{{ number_format($offset1, 2) }}"
                        stroke-linecap="butt"/>
                    @endif

                    @if($pendingLen > 0)
                    <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#eab308" stroke-width="14"
                        stroke-dasharray="{{ number_format($pendingLen, 2) }} {{ number_format($circumference, 2) }}"
                        stroke-dashoffset="{{ number_format($offset2, 2) }}"
                        stroke-linecap="butt"/>
                    @endif

                    @if($failedLen > 0)
                    <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#ef4444" stroke-width="14"
                        stroke-dasharray="{{ number_format($failedLen, 2) }} {{ number_format($circumference, 2) }}"
                        stroke-dashoffset="{{ number_format($offset3, 2) }}"
                        stroke-linecap="butt"/>
                    @endif
                @endif

                <text x="60" y="56" text-anchor="middle" font-size="22" font-weight="bold" fill="#111827">{{ $todayTotal }}</text>
                <text x="60" y="71" text-anchor="middle" font-size="9" fill="#6b7280">registros hoy</text>
            </svg>
        </div>

        {{-- Leyenda --}}
        <div class="flex-1 space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-green-500 flex-shrink-0"></span>
                    <span class="text-sm text-gray-600">Sincronizados hoy</span>
                </div>
                <span class="text-sm font-semibold text-gray-900">{{ $syncedToday }}</span>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#eab308;"></span>
                    <span class="text-sm text-gray-600">Pendientes de sync</span>
                </div>
                <span class="text-sm font-semibold text-gray-900">{{ $pendingSync }}</span>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-red-500 flex-shrink-0"></span>
                    <span class="text-sm text-gray-600">Errores de sync</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-semibold {{ $failedSync > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $failedSync }}</span>
                    @if($failedSync > 0)
                    <div class="flex gap-1">
                        <button wire:click="retryFailed" wire:loading.attr="disabled"
                            class="px-2 py-0.5 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700 disabled:opacity-50 transition">
                            <span wire:loading.remove wire:target="retryFailed">Reintentar</span>
                            <span wire:loading wire:target="retryFailed">...</span>
                        </button>
                        <button wire:click="dismissFailed" wire:confirm="¿Descartar los {{ $failedSync }} errores?" wire:loading.attr="disabled"
                            class="px-2 py-0.5 text-xs font-medium text-gray-600 border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 transition">
                            Descartar
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>
