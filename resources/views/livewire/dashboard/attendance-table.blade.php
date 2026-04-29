<?php

use App\Models\AttendanceLog;
use App\Models\Client;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $checkTypeFilter = '';
    public string $clientFilter = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedCheckTypeFilter(): void { $this->resetPage(); }
    public function updatedClientFilter(): void { $this->resetPage(); }

    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->statusFilter !== ''
            || $this->checkTypeFilter !== ''
            || $this->clientFilter !== '';
    }

    public function with(): array
    {
        $query = AttendanceLog::with(['biometricSource', 'factorialEmployee'])
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('employee_code', 'like', "%{$this->search}%")
                   ->orWhereHas('factorialEmployee', fn($e) => $e->where('full_name', 'like', "%{$this->search}%"));
            }))
            ->when($this->statusFilter, fn($q) => $q->where('sync_status', $this->statusFilter))
            ->when($this->checkTypeFilter, fn($q) => $q->where('check_type', $this->checkTypeFilter))
            ->when($this->clientFilter, fn($q) => $q->where('client_id', $this->clientFilter))
            ->orderByDesc('occurred_at');

        // Sin filtros: últimos 5 registros más recientes
        if (!$this->hasFilters()) {
            $logs = $query->limit(5)->get();
            return [
                'logs'    => $logs,
                'clients' => Client::orderBy('name')->get(),
                'paged'   => false,
            ];
        }

        return [
            'logs'    => $query->paginate(15),
            'clients' => Client::orderBy('name')->get(),
            'paged'   => true,
        ];
    }
}; ?>

<div class="bg-white shadow rounded-lg">
    <div class="px-6 py-4 border-b border-gray-200">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-medium text-gray-900">Registros de asistencia</h3>
                @if(!$this->hasFilters())
                    <p class="text-xs text-gray-400 mt-0.5">Últimas 5 entradas — selecciona una empresa para ver todos</p>
                @endif
            </div>
            @if($this->hasFilters())
            <button wire:click="$set('search', ''); $set('statusFilter', ''); $set('checkTypeFilter', ''); $set('clientFilter', '')"
                class="text-xs text-indigo-600 hover:text-indigo-800">
                Limpiar filtros
            </button>
            @endif
        </div>

        <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
            {{-- Empresa --}}
            <select wire:model.live="clientFilter" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todas las empresas</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </select>

            {{-- Búsqueda --}}
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Nombre o código..."
                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
            />

            {{-- Estado --}}
            <select wire:model.live="statusFilter" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todos los estados</option>
                <option value="pending">Pendiente</option>
                <option value="resolved">Resuelto</option>
                <option value="synced">Sincronizado</option>
                <option value="failed">Fallido</option>
            </select>

            {{-- Tipo --}}
            <select wire:model.live="checkTypeFilter" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todos los tipos</option>
                <option value="check_in">Entrada</option>
                <option value="check_out">Salida</option>
                <option value="break_out">Inicio pausa</option>
                <option value="break_in">Fin pausa</option>
            </select>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha y hora</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($log->factorialEmployee)
                            <p class="text-sm font-medium text-gray-900">{{ $log->factorialEmployee->full_name }}</p>
                            <p class="text-xs text-gray-400 font-mono">PIN {{ $log->employee_code }}</p>
                        @else
                            <p class="text-sm font-medium text-gray-500 font-mono">{{ $log->employee_code }}</p>
                            <p class="text-xs text-amber-500">Sin resolver</p>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        @php
                            $typeLabels = ['check_in' => 'Entrada', 'check_out' => 'Salida', 'break_out' => 'Inicio pausa', 'break_in' => 'Fin pausa'];
                            $typeColors = ['check_in' => 'bg-green-100 text-green-800', 'check_out' => 'bg-blue-100 text-blue-800', 'break_out' => 'bg-yellow-100 text-yellow-800', 'break_in' => 'bg-purple-100 text-purple-800'];
                        @endphp
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $typeColors[$log->check_type] ?? 'bg-gray-100 text-gray-800' }}">
                            {{ $typeLabels[$log->check_type] ?? $log->check_type }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $log->occurred_at->format('d/m/Y H:i:s') }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $log->biometricSource?->name ?? '—' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @php
                            $statusColors = ['pending' => 'bg-yellow-100 text-yellow-800', 'resolved' => 'bg-indigo-100 text-indigo-700', 'synced' => 'bg-green-100 text-green-800', 'failed' => 'bg-red-100 text-red-800'];
                            $statusLabels = ['pending' => 'Pendiente', 'resolved' => 'Resuelto', 'synced' => 'Sincronizado', 'failed' => 'Fallido'];
                        @endphp
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusColors[$log->sync_status] ?? 'bg-gray-100 text-gray-800' }}">
                            {{ $statusLabels[$log->sync_status] ?? $log->sync_status }}
                        </span>
                        @if($log->sync_error)
                            <p class="text-xs text-red-500 mt-1 truncate max-w-xs" title="{{ $log->sync_error }}">{{ Str::limit($log->sync_error, 40) }}</p>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500">No hay registros.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($paged && $logs->hasPages())
    <div class="px-6 py-4 border-t border-gray-200">
        {{ $logs->links() }}
    </div>
    @endif
</div>
