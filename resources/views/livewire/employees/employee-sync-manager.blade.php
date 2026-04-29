<?php

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\FactorialEmployee;
use App\Models\BiometricUserSync;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public ?int $client_id = null;
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedClientId(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        if (!$this->client_id) {
            return [
                'employees' => collect(),
                'clients'   => Client::orderBy('name')->get(),
            ];
        }

        $query = FactorialEmployee::with(['biometricUserSyncs'])
            ->where('client_id', $this->client_id)
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('full_name', 'like', "%{$this->search}%")
                   ->orWhere('email', 'like', "%{$this->search}%")
                   ->orWhere('access_id', 'like', "%{$this->search}%");
            }))
            ->orderBy('full_name');

        return [
            'employees' => $query->paginate(20),
            'clients'   => Client::orderBy('name')->get(),
        ];
    }

    public function syncEmployee(int $employeeId): void
    {
        $employee = FactorialEmployee::findOrFail($employeeId);

        if (!$employee->access_id) return;

        $providers = BiometricProvider::where('client_id', $employee->client_id)->get();

        $now = now();

        foreach ($providers as $provider) {
            BiometricUserSync::updateOrCreate(
                [
                    'biometric_provider_id' => $provider->id,
                    'factorial_employee_id' => $employee->id,
                ],
                [
                    'client_id'              => $employee->client_id,
                    'external_employee_code' => (string) $employee->factorial_id,
                    'sync_status'            => 'pending',
                    'last_attempt_at'        => $now,
                ]
            );

            // Encolar comando al dispositivo asociado al proveedor
            $sources = BiometricSource::where('biometric_provider_id', $provider->id)
                ->where('status', 'active')
                ->get();

            foreach ($sources as $source) {
                $maxSeq  = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;
                $pin     = $employee->access_id;
                $name    = mb_substr($employee->full_name, 0, 24);
                $payload = "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPassword=\tCard=\tRole=0";

                DeviceCommand::create([
                    'biometric_source_id' => $source->id,
                    'command_seq'         => $maxSeq + 1,
                    'command_type'        => 'set_user',
                    'payload'             => $payload,
                    'status'              => 'pending',
                ]);
            }
        }
    }
}; ?>

<div>
    {{-- Filtros --}}
    <div class="flex flex-col sm:flex-row gap-3 mb-6">
        <div class="flex-1">
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Buscar por nombre, email o PIN..."
                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
        </div>
        <div class="sm:w-56">
            <select wire:model.live="client_id" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">— Selecciona una empresa —</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Tabla --}}
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID en Factorial</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empresa</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado Factorial</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sync biométrico</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($employees as $employee)
                @php
                    $syncs      = $employee->biometricUserSyncs;
                    $hasPending = $syncs->contains('sync_status', 'pending');
                    $hasFailed  = $syncs->contains('sync_status', 'failed');
                    $hasSynced  = $syncs->contains('sync_status', 'synced');
                    $syncLabel  = match(true) {
                        $hasPending => ['bg-yellow-100 text-yellow-800', 'Pendiente'],
                        $hasFailed  => ['bg-red-100 text-red-800', 'Error'],
                        $hasSynced  => ['bg-green-100 text-green-800', 'Sincronizado'],
                        default     => ['bg-gray-100 text-gray-600', 'Sin sync'],
                    };
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $employee->full_name }}</div>
                        <div class="text-xs text-gray-500">{{ $employee->email }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($employee->access_id)
                            <span class="font-mono text-sm text-gray-800">{{ $employee->access_id }}</span>
                        @else
                            <span class="text-xs text-red-500 font-medium">Sin PIN</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        {{ $employee->company_identifier ?? '—' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($employee->active)
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Activo</span>
                        @elseif($employee->is_terminating)
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 text-orange-800">En salida</span>
                        @else
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">Inactivo</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $syncLabel[0] }}">
                            {{ $syncLabel[1] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500">
                        @if(!$this->client_id)
                            <div class="flex flex-col items-center gap-2 text-gray-400">
                                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <p class="text-sm font-medium text-gray-500">Selecciona una empresa para ver sus empleados</p>
                            </div>
                        @else
                            No se encontraron empleados.
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($client_id && $employees instanceof \Illuminate\Pagination\LengthAwarePaginator && $employees->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $employees->links() }}
        </div>
        @endif
    </div>
</div>
