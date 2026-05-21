<?php

use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Jobs\SyncAttendanceToFactorial;
use Livewire\Volt\Component;

new class extends Component {
    public int $todayTotal = 0;
    public int $pendingSync = 0;
    public int $failedSync = 0;
    public int $syncedToday = 0;
    public array $byClient = [];
    public array $connections = [];
    public int $activeConnections = 0;
    public int $inactiveConnections = 0;

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

        $counts = BiometricSource::selectRaw('client_id, count(*) as total')
            ->whereNotNull('client_id')
            ->groupBy('client_id')
            ->pluck('total', 'client_id');

        $clients = Client::whereIn('id', $counts->keys())->pluck('name', 'id');

        $this->byClient = $counts->map(fn($total, $clientId) => [
            'name'  => $clients[$clientId] ?? 'Sin cliente',
            'total' => $total,
        ])->values()->toArray();

        $allConnections = FactorialConnection::with('client')->get();
        $this->activeConnections   = $allConnections->whereNotNull('access_token')->count();
        $this->inactiveConnections = $allConnections->whereNull('access_token')->count();

        $this->connections = $allConnections->map(fn($c) => [
            'name'   => $c->client?->name ?? $c->name,
            'active' => !is_null($c->access_token),
        ])->values()->toArray();
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
    // ── Dona 1: estado de sync ────────────────────────────────────
    $r             = 54;
    $circumference = 2 * M_PI * $r;
    $startOffset   = $circumference / 4;
    $donutTotal    = $syncedToday + $pendingSync + $failedSync;

    if ($donutTotal > 0) {
        $syncedLen  = ($syncedToday / $donutTotal) * $circumference;
        $pendingLen = ($pendingSync / $donutTotal) * $circumference;
        // Last visible segment fills exact remainder to avoid floating-point gap
        if ($failedSync > 0) {
            $failedLen = $circumference - $syncedLen - $pendingLen;
        } elseif ($pendingSync > 0) {
            $pendingLen = $circumference - $syncedLen;
            $failedLen  = 0;
        } else {
            $syncedLen = $circumference;
            $pendingLen = $failedLen = 0;
        }
    } else {
        $syncedLen = $pendingLen = $failedLen = 0;
    }

    $offset1 = $startOffset;
    $offset2 = $startOffset - $syncedLen;
    $offset3 = $startOffset - $syncedLen - $pendingLen;

    // ── Dona 3: conexiones ───────────────────────────────────────
    $connTotal   = $activeConnections + $inactiveConnections;
    if ($connTotal > 0) {
        $activeLen   = ($activeConnections / $connTotal) * $circumference;
        // Last segment fills exact remainder
        $inactiveLen = $inactiveConnections > 0 ? $circumference - $activeLen : 0;
    } else {
        $activeLen = $inactiveLen = 0;
    }
    $connOffset1 = $startOffset;
    $connOffset2 = $startOffset - $activeLen;

    // ── Dona 2: por empresa ───────────────────────────────────────
    $clientColors = ['#6366f1','#0ea5e9','#f59e0b','#10b981','#ec4899','#8b5cf6','#14b8a6'];
    $clientTotal  = array_sum(array_column($byClient, 'total'));
    $clientOffset = $startOffset;
    $clientRemain = $circumference;

    $clientSegments = [];
    $lastClientIdx  = count($byClient) - 1;
    foreach ($byClient as $i => $c) {
        if ($i === $lastClientIdx) {
            // Last segment fills exact remainder to avoid floating-point gap
            $len = $clientRemain;
        } else {
            $len = $clientTotal > 0 ? ($c['total'] / $clientTotal) * $circumference : 0;
            $clientRemain -= $len;
        }
        $clientSegments[] = [
            'name'   => $c['name'],
            'total'  => $c['total'],
            'color'  => $clientColors[$i % count($clientColors)],
            'len'    => $len,
            'offset' => $clientOffset,
        ];
        $clientOffset -= $len;
    }
@endphp

<div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">

    {{-- Dona 1: estado de sync --}}
    <div class="bg-white shadow rounded-lg p-5">
        <div class="flex items-center gap-8">
            <div class="flex-shrink-0">
                <svg width="130" height="130" viewBox="0 0 120 120">
                    @if($donutTotal === 0)
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="14"/>
                    @elseif($pendingSync === 0 && $failedSync === 0)
                        {{-- Todos sincronizados: círculo completo verde --}}
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#22c55e" stroke-width="14"/>
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

            <div class="flex-1 space-y-4" style="padding-left:6px;">
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

    {{-- Dona 2: por empresa --}}
    <div class="bg-white shadow rounded-lg p-5">
        <div class="flex items-center gap-8">
            <div class="flex-shrink-0">
                <svg width="130" height="130" viewBox="0 0 120 120">
                    @if($clientTotal === 0)
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="14"/>
                    @elseif(count($clientSegments) === 1)
                        {{-- Un solo cliente: círculo completo con su color --}}
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="{{ $clientSegments[0]['color'] }}" stroke-width="14"/>
                    @else
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#f3f4f6" stroke-width="14"/>
                        @foreach($clientSegments as $seg)
                            @if($seg['len'] > 0)
                            <circle cx="60" cy="60" r="{{ $r }}" fill="none"
                                stroke="{{ $seg['color'] }}" stroke-width="14"
                                stroke-dasharray="{{ number_format($seg['len'], 2) }} {{ number_format($circumference, 2) }}"
                                stroke-dashoffset="{{ number_format($seg['offset'], 2) }}"
                                stroke-linecap="butt"/>
                            @endif
                        @endforeach
                    @endif

                    <text x="60" y="56" text-anchor="middle" font-size="22" font-weight="bold" fill="#111827">{{ $clientTotal }}</text>
                    <text x="60" y="71" text-anchor="middle" font-size="9" fill="#6b7280">dispositivos</text>
                </svg>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                @forelse($clientSegments as $seg)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:{{ $seg['color'] }};"></span>
                        <span class="text-sm text-gray-600">{{ strtoupper(mb_substr($seg['name'], 0, 12)) }}</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $seg['total'] }}</span>
                </div>
                @empty
                <p class="text-sm text-gray-400">Sin dispositivos</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Dona 3: conexiones Factorial --}}
    <div class="bg-white shadow rounded-lg p-5">
        <div class="flex items-center gap-8">
            <div class="flex-shrink-0">
                <svg width="130" height="130" viewBox="0 0 120 120">
                    @if($connTotal === 0)
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="14"/>
                    @elseif($inactiveConnections === 0)
                        {{-- Todas activas: círculo completo verde sin gaps --}}
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#22c55e" stroke-width="14"/>
                    @else
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#f3f4f6" stroke-width="14"/>

                        @if($activeLen > 0)
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#22c55e" stroke-width="14"
                            stroke-dasharray="{{ number_format($activeLen, 2) }} {{ number_format($circumference, 2) }}"
                            stroke-dashoffset="{{ number_format($connOffset1, 2) }}"
                            stroke-linecap="butt"/>
                        @endif

                        @if($inactiveLen > 0)
                        <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="#d1d5db" stroke-width="14"
                            stroke-dasharray="{{ number_format($inactiveLen, 2) }} {{ number_format($circumference, 2) }}"
                            stroke-dashoffset="{{ number_format($connOffset2, 2) }}"
                            stroke-linecap="butt"/>
                        @endif
                    @endif

                    <text x="60" y="56" text-anchor="middle" font-size="22" font-weight="bold" fill="#111827">{{ $activeConnections }}</text>
                    <text x="60" y="71" text-anchor="middle" font-size="9" fill="#6b7280">de {{ $connTotal }} activas</text>
                </svg>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                @foreach($connections as $conn)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0"
                            style="background-color:{{ $conn['active'] ? '#22c55e' : '#d1d5db' }};"></span>
                        <span class="text-sm text-gray-600">{{ strtoupper(mb_substr($conn['name'], 0, 12)) }}</span>
                    </div>
                    <span class="text-xs {{ $conn['active'] ? 'text-green-600' : 'text-gray-400' }}">
                        {{ $conn['active'] ? 'Activa' : 'Inactiva' }}
                    </span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

</div>
