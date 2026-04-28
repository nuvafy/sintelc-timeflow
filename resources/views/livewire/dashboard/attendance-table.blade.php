<?php

use App\Models\AttendanceLog;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $checkTypeFilter = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedCheckTypeFilter(): void { $this->resetPage(); }

    public function with(): array
    {
        $logs = AttendanceLog::with('biometricSource')
            ->when($this->search, fn($q) => $q->where('employee_code', 'like', "%{$this->search}%"))
            ->when($this->statusFilter, fn($q) => $q->where('sync_status', $this->statusFilter))
            ->when($this->checkTypeFilter, fn($q) => $q->where('check_type', $this->checkTypeFilter))
            ->orderByDesc('occurred_at')
            ->paginate(15);

        return ['logs' => $logs];
    }
}; ?>

<div class="bg-white shadow rounded-lg">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-medium text-gray-900">Registros de asistencia</h3>

        <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Buscar por código empleado..."
                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            <select wire:model.live="statusFilter" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todos los estados</option>
                <option value="pending">Pendiente</option>
                <option value="synced">Sincronizado</option>
                <option value="failed">Fallido</option>
            </select>
            <select wire:model.live="checkTypeFilter" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todos los tipos</option>
                <option value="check_in">Entrada</option>
                <option value="check_out">Salida</option>
                <option value="break_start">Inicio pausa</option>
                <option value="break_end">Fin pausa</option>
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
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        {{ $log->employee_code }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        @php
                            $typeLabels = ['check_in' => 'Entrada', 'check_out' => 'Salida', 'break_start' => 'Inicio pausa', 'break_end' => 'Fin pausa'];
                            $typeColors = ['check_in' => 'bg-green-100 text-green-800', 'check_out' => 'bg-blue-100 text-blue-800', 'break_start' => 'bg-yellow-100 text-yellow-800', 'break_end' => 'bg-purple-100 text-purple-800'];
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
                            $statusColors = ['pending' => 'bg-yellow-100 text-yellow-800', 'synced' => 'bg-green-100 text-green-800', 'failed' => 'bg-red-100 text-red-800'];
                            $statusLabels = ['pending' => 'Pendiente', 'synced' => 'Sincronizado', 'failed' => 'Fallido'];
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

    @if($logs->hasPages())
    <div class="px-6 py-4 border-t border-gray-200">
        {{ $logs->links() }}
    </div>
    @endif
</div>
