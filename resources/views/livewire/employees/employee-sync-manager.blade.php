<?php

use App\Models\AttendanceLog;
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

    public ?int  $client_id = null;
    public string $search   = '';
    public string $tab      = 'factorial'; // 'factorial' | 'unresolved'

    public function updatedSearch(): void   { $this->resetPage(); }
    public function updatedClientId(): void { $this->resetPage(); }
    public function updatedTab(): void      { $this->resetPage(); }

    public function with(): array
    {
        $clients = Client::orderBy('name')->get();

        // ── Tab: Sin asignar ────────────────────────────────────────
        if ($this->tab === 'unresolved') {
            if (!$this->client_id) {
                return ['employees' => collect(), 'unresolved' => collect(), 'clients' => $clients, 'unresolvedCount' => 0];
            }

            // Nombres desde device_users del biométrico
            $deviceUsers = collect();
            BiometricSource::where('client_id', $this->client_id)->get()
                ->each(function ($source) use (&$deviceUsers) {
                    foreach ($source->device_users ?? [] as $u) {
                        $deviceUsers[$u['pin']] = $u['name'] ?? null;
                    }
                });

            $unresolved = AttendanceLog::where('client_id', $this->client_id)
                ->whereNull('factorial_employee_id')
                ->when($this->search, fn($q) => $q->where('employee_code', 'like', "%{$this->search}%"))
                ->selectRaw('employee_code, COUNT(*) as total, MAX(occurred_at) as last_seen')
                ->groupBy('employee_code')
                ->orderByDesc('last_seen')
                ->paginate(20);

            $unresolved->getCollection()->transform(function ($row) use ($deviceUsers) {
                $row->device_name = $deviceUsers[$row->employee_code] ?? null;
                return $row;
            });

            return [
                'employees'      => collect(),
                'unresolved'     => $unresolved,
                'unresolvedCount'=> $unresolved->total(),
                'clients'        => $clients,
            ];
        }

        // ── Tab: Empleados Factorial ────────────────────────────────
        if (!$this->client_id) {
            return ['employees' => collect(), 'unresolved' => collect(), 'unresolvedCount' => 0, 'clients' => $clients, 'vendorName' => 'Biométrico', 'mappedEmployeeIds' => collect(), 'biometricIds' => collect()];
        }

        $query = FactorialEmployee::query()
            ->where('client_id', $this->client_id)
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('full_name', 'like', "%{$this->search}%")
                   ->orWhere('email', 'like', "%{$this->search}%")
                   ->orWhere('access_id', 'like', "%{$this->search}%");
            }))
            ->orderBy('full_name');

        // Contador de sin asignar para badge en tab
        $unresolvedCount = AttendanceLog::where('client_id', $this->client_id)
            ->whereNull('factorial_employee_id')
            ->distinct('employee_code')
            ->count('employee_code');

        $provider = BiometricProvider::where('client_id', $this->client_id)->first();
        $vendorName = $provider?->vendor ?? 'Biométrico';

        $biometricIds = BiometricUserSync::where('client_id', $this->client_id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('external_employee_code', 'factorial_employee_id');

        $mappedEmployeeIds = $biometricIds->flip();

        return [
            'employees'        => $query->paginate(20),
            'unresolved'       => collect(),
            'unresolvedCount'  => $unresolvedCount,
            'clients'          => $clients,
            'vendorName'       => $vendorName,
            'mappedEmployeeIds' => $mappedEmployeeIds,
            'biometricIds'      => $biometricIds,
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
    <div class="flex flex-col sm:flex-row gap-3 mb-4">
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

    {{-- Tabs --}}
    <div class="border-b border-gray-200 mb-4">
        <nav class="-mb-px flex gap-6">
            <button
                wire:click="$set('tab', 'factorial')"
                class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $tab === 'factorial' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Empleados en Factorial
            </button>
            <button
                wire:click="$set('tab', 'unresolved')"
                class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 {{ $tab === 'unresolved' ? 'border-amber-500 text-amber-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Sin asignar en biométrico
                @if($unresolvedCount > 0)
                    <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 text-[10px] font-bold leading-none text-white bg-amber-500 rounded-full">
                        {{ $unresolvedCount }}
                    </span>
                @endif
            </button>
        </nav>
    </div>

    {{-- TAB: Empleados Factorial --}}
    @if($tab === 'factorial')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">ID Factorial</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">ID Vendor</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mapeado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($employees as $employee)
                @php
                    $isMapped = isset($biometricIds[$employee->id]);
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $employee->full_name }}</div>
                        <div class="text-xs text-gray-500">{{ $employee->email }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-gray-700 text-center">
                        {{ $employee->factorial_id }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-center">
                        @if(isset($biometricIds[$employee->id]))
                            <span class="text-gray-700">{{ $biometricIds[$employee->id] }}</span>
                        @else
                            <span class="text-red-400 text-xs">Sin asignar</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($isMapped)
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Listo</span>
                        @else
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-700">Pendiente</span>
                        @endif
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
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500">
                        @if(!$this->client_id)
                            Selecciona una empresa para ver sus empleados.
                        @else
                            Sin empleados.
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
    @endif

    {{-- TAB: Sin asignar --}}
    @if($tab === 'unresolved')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        @if(!$client_id)
            <p class="px-6 py-10 text-center text-sm text-gray-500">Selecciona una empresa para ver los PINs sin asignar.</p>
        @else
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-amber-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PIN biométrico</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre en dispositivo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registros</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Última actividad</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($unresolved as $row)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="font-mono text-sm font-semibold text-gray-900">{{ $row->employee_code }}</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($row->device_name)
                            <span class="text-sm text-gray-800">{{ $row->device_name }}</span>
                        @else
                            <span class="text-xs text-gray-400 italic">Sin nombre en dispositivo</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        {{ $row->total }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ \Carbon\Carbon::parse($row->last_seen)->format('d/m/Y H:i') }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800">
                            Sin asignar
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500">
                        ✓ Todos los PINs están asignados a un empleado de Factorial.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($unresolved instanceof \Illuminate\Pagination\LengthAwarePaginator && $unresolved->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $unresolved->links() }}
        </div>
        @endif
        @endif
    </div>
    @endif
</div>
