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

    // Estatus biométricos
    public int $devOnline   = 0;
    public int $devRecent   = 0;
    public int $devOffline  = 0;
    public int $devInactive = 0;

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

        // Estatus biométricos — una sola query con CASE
        $devStats = BiometricSource::selectRaw("
            SUM(CASE WHEN status='active' AND last_ping_at >= ? THEN 1 ELSE 0 END) as online,
            SUM(CASE WHEN status='active' AND last_ping_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as recent,
            SUM(CASE WHEN status='active' AND (last_ping_at IS NULL OR last_ping_at < ?) THEN 1 ELSE 0 END) as offline,
            SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) as inactive
        ", [
            now()->subHours(24),
            now()->subDays(7), now()->subHours(24),
            now()->subDays(7),
        ])->first();

        $this->devOnline   = (int) ($devStats->online   ?? 0);
        $this->devRecent   = (int) ($devStats->recent   ?? 0);
        $this->devOffline  = (int) ($devStats->offline  ?? 0);
        $this->devInactive = (int) ($devStats->inactive ?? 0);
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
    $clientColors = ['#6366f1','#0ea5e9','#f59e0b','#10b981','#ec4899','#8b5cf6','#14b8a6'];
    $clientTotal  = array_sum(array_column($byClient, 'total'));
    $connTotal    = $activeConnections + $inactiveConnections;
    $devTotal     = $devOnline + $devRecent + $devOffline + $devInactive;
@endphp

<div class="grid grid-cols-2 gap-5">

    {{-- Card 1: Sincronización --}}
    <div class="bg-white shadow rounded-lg p-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Sincronización</p>
        <div class="flex items-center gap-8">
            @php $pct = $todayTotal > 0 ? round(($syncedToday / $todayTotal) * 100) : 0; @endphp
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: ['Sincronizados', 'Pendientes', 'Errores'],
                                datasets: [{
                                    data: [{{ $syncedToday }}, {{ $pendingSync }}, {{ $failedSync }}],
                                    backgroundColor: ['#22c55e', '#eab308', '#ef4444'],
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $todayTotal }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">registros hoy</span>
                    <span class="text-[9px] text-green-500 mt-0.5">{{ $pct }}% sync</span>
                </div>
            </div>

            <div class="flex-1 space-y-4" style="padding-left:6px;">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#22c55e;"></span>
                        <span class="text-sm text-gray-600">Sincronizados</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $syncedToday }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#eab308;"></span>
                        <span class="text-sm text-gray-600">Pendientes</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $pendingSync }}</span>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#ef4444;"></span>
                            <span class="text-sm text-gray-600">Errores</span>
                        </div>
                        <span class="text-sm font-semibold {{ $failedSync > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $failedSync }}</span>
                    </div>
                    @if($failedSync > 0)
                    <div class="flex items-center gap-3 mt-1 pl-5">
                        <button wire:click="retryFailed" wire:loading.attr="disabled"
                            class="text-xs text-red-500 hover:text-red-700 disabled:opacity-40 transition">
                            <span wire:loading.remove wire:target="retryFailed">Reintentar</span>
                            <span wire:loading wire:target="retryFailed">...</span>
                        </button>
                        <button wire:click="dismissFailed" wire:confirm="¿Descartar los {{ $failedSync }} errores?" wire:loading.attr="disabled"
                            class="text-xs text-gray-400 hover:text-gray-600 disabled:opacity-40 transition">
                            Descartar
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Card 2: Conexiones Factorial --}}
    <div class="bg-white shadow rounded-lg p-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Conexiones Factorial</p>
        <div class="flex items-center gap-8">
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: ['Activas', 'Inactivas'],
                                datasets: [{
                                    data: [{{ $activeConnections }}, {{ $inactiveConnections }}],
                                    backgroundColor: ['#22c55e', '#d1d5db'],
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $activeConnections }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">activas</span>
                </div>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                @foreach($connections as $conn)
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full flex-shrink-0"
                        style="background-color:{{ $conn['active'] ? '#22c55e' : '#d1d5db' }};"></span>
                    <span class="text-sm text-gray-600">{{ mb_substr(ucwords(mb_strtolower($conn['name'])), 0, 24) }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Card 3: Dispositivos por empresa --}}
    <div class="bg-white shadow rounded-lg p-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Dispositivos por empresa</p>
        <div class="flex items-center gap-8">
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: {{ Js::from(array_column($byClient, 'name')) }},
                                datasets: [{
                                    data: {{ Js::from(array_column($byClient, 'total')) }},
                                    backgroundColor: {{ Js::from(array_slice($clientColors, 0, count($byClient))) }},
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $clientTotal }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">dispositivos</span>
                </div>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                @forelse($byClient as $i => $c)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0"
                            style="background-color:{{ $clientColors[$i % count($clientColors)] }};"></span>
                        <span class="text-sm text-gray-600">{{ mb_substr(ucwords(mb_strtolower($c['name'])), 0, 24) }}</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $c['total'] }}</span>
                </div>
                @empty
                <p class="text-sm text-gray-400">Sin dispositivos</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Card 4: Estatus de los biométricos --}}
    <div class="bg-white shadow rounded-lg p-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Estatus biométricos</p>
        <div class="flex items-center gap-8">
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: ['En línea', 'Reciente', 'Sin señal', 'Deshabilitado'],
                                datasets: [{
                                    data: [{{ $devOnline }}, {{ $devRecent }}, {{ $devOffline }}, {{ $devInactive }}],
                                    backgroundColor: ['#22c55e', '#eab308', '#ef4444', '#d1d5db'],
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $devTotal }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">dispositivos</span>
                </div>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#22c55e;"></span>
                        <span class="text-sm text-gray-600">En línea</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devOnline }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#eab308;"></span>
                        <span class="text-sm text-gray-600">Reciente</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devRecent }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#ef4444;"></span>
                        <span class="text-sm text-gray-600">Sin señal</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devOffline }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#d1d5db;"></span>
                        <span class="text-sm text-gray-600">Deshabilitado</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devInactive }}</span>
                </div>
            </div>
        </div>
    </div>

</div>
