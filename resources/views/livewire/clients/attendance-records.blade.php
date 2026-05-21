<?php

use App\Models\AttendanceLog;
use App\Models\Client;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Client $client;

    public string $search          = '';
    public string $statusFilter    = '';
    public string $checkTypeFilter = '';
    public string $dateFrom        = '';
    public string $dateTo          = '';

    public function mount(Client $client): void
    {
        $this->client = $client;
        $this->dateFrom = today()->format('Y-m-d');
        $this->dateTo   = today()->format('Y-m-d');
    }

    public function updatedSearch(): void          { $this->resetPage(); }
    public function updatedStatusFilter(): void    { $this->resetPage(); }
    public function updatedCheckTypeFilter(): void { $this->resetPage(); }
    public function updatedDateFrom(): void        { $this->resetPage(); }
    public function updatedDateTo(): void          { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->search          = '';
        $this->statusFilter    = '';
        $this->checkTypeFilter = '';
        $this->dateFrom        = today()->format('Y-m-d');
        $this->dateTo          = today()->format('Y-m-d');
        $this->resetPage();
    }

    public function hasExtraFilters(): bool
    {
        return $this->search !== ''
            || $this->statusFilter !== ''
            || $this->checkTypeFilter !== '';
    }

    public function with(): array
    {
        $query = AttendanceLog::with(['biometricSource', 'factorialEmployee'])
            ->where('client_id', $this->client->id)
            ->when($this->dateFrom, fn($q) => $q->whereDate('occurred_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('occurred_at', '<=', $this->dateTo))
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('employee_code', 'like', "%{$this->search}%")
                   ->orWhereHas('factorialEmployee', fn($e) => $e->where('full_name', 'like', "%{$this->search}%"));
            }))
            ->when($this->statusFilter,    fn($q) => $q->where('sync_status', $this->statusFilter))
            ->when($this->checkTypeFilter, fn($q) => $q->where('check_type', $this->checkTypeFilter))
            ->orderByDesc('occurred_at');

        return [
            'logs'  => $query->paginate(25),
            'total' => AttendanceLog::where('client_id', $this->client->id)
                ->when($this->dateFrom, fn($q) => $q->whereDate('occurred_at', '>=', $this->dateFrom))
                ->when($this->dateTo,   fn($q) => $q->whereDate('occurred_at', '<=', $this->dateTo))
                ->count(),
        ];
    }
}; ?>

<div class="space-y-4">

    {{-- Filtros --}}
    <div class="bg-white shadow rounded-lg px-6 py-4">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            {{-- Rango de fechas --}}
            <div class="col-span-2 sm:col-span-2 flex items-center gap-2">
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Desde</label>
                    <input wire:model.live="dateFrom" type="date"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                </div>
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Hasta</label>
                    <input wire:model.live="dateTo" type="date"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                </div>
            </div>

            {{-- Búsqueda --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">Buscar empleado</label>
                <input wire:model.live.debounce.300ms="search" type="text"
                    placeholder="Nombre o código..."
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
            </div>

            {{-- Estado --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">Estado</label>
                <select wire:model.live="statusFilter"
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Todos</option>
                    <option value="pending">Pendiente</option>
                    <option value="synced">Sincronizado</option>
                    <option value="failed">Fallido</option>
                    <option value="incomplete">Incompleto</option>
                </select>
            </div>
        </div>

        <div class="mt-3 flex items-center justify-between">
            {{-- Tipo de registro --}}
            <div class="flex gap-2">
                @foreach([''=>'Todos', 'check_in'=>'Entrada', 'check_out'=>'Salida', 'break_in'=>'Inicio pausa', 'break_out'=>'Fin pausa'] as $val => $label)
                <button wire:click="$set('checkTypeFilter', '{{ $val }}')"
                    class="px-3 py-1 text-xs rounded-full border transition
                        {{ $checkTypeFilter === $val
                            ? 'bg-indigo-600 text-white border-indigo-600'
                            : 'text-gray-600 border-gray-300 hover:bg-gray-50' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>

            {{-- Total + limpiar --}}
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400">{{ number_format($total) }} registros</span>
                @if($this->hasExtraFilters())
                <button wire:click="clearFilters" class="text-xs text-indigo-600 hover:text-indigo-800">
                    Limpiar filtros
                </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabla --}}
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha / Hora</th>
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
                                <p class="text-xs text-gray-400 font-mono">
                                    Factorial: {{ $log->factorialEmployee->factorial_id }} · Biométrico: {{ $log->employee_code }}
                                </p>
                            @else
                                <p class="text-sm font-mono text-gray-500">{{ $log->employee_code }}</p>
                                <p class="text-xs text-amber-500">Sin resolver</p>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $typeLabels = ['check_in'=>'Entrada','check_out'=>'Salida','break_in'=>'Inicio pausa','break_out'=>'Fin pausa'];
                                $typeColors = ['check_in'=>'bg-green-100 text-green-800','check_out'=>'bg-blue-100 text-blue-800','break_in'=>'bg-yellow-100 text-yellow-800','break_out'=>'bg-purple-100 text-purple-800'];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $typeColors[$log->check_type] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ $typeLabels[$log->check_type] ?? $log->check_type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                            <p>{{ $log->occurred_at->format('d/m/Y') }}</p>
                            <p class="font-mono">{{ $log->occurred_at->format('H:i:s') }}</p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ $log->biometricSource?->name ?? '—' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $statusColors = ['pending'=>'bg-yellow-100 text-yellow-800','synced'=>'bg-green-100 text-green-800','failed'=>'bg-red-100 text-red-800','incomplete'=>'bg-gray-100 text-gray-600'];
                                $statusLabels = ['pending'=>'Pendiente','synced'=>'Sincronizado','failed'=>'Fallido','incomplete'=>'Incompleto'];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full cursor-default {{ $statusColors[$log->sync_status] ?? 'bg-gray-100 text-gray-800' }}"
                                @if($log->sync_error) title="{{ $log->sync_error }}"
                                @elseif($log->sync_note) title="{{ $log->sync_note }}"
                                @endif>
                                {{ $statusLabels[$log->sync_status] ?? $log->sync_status }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-400">
                            No hay registros para el período seleccionado.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $logs->links() }}
        </div>
        @endif
    </div>

</div>
