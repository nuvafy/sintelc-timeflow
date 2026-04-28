<?php

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Models\FactorialLocation;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public bool $showModal = false;
    public bool $editing = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $serial_number = '';
    public string $site_name = '';
    public string $status = 'active';
    public ?int $client_id = null;
    public ?int $biometric_provider_id = null;
    public ?int $factorial_location_id = null;

    // Modal para asignar equipo descubierto
    public bool $showAssignModal = false;
    public ?int $assigningSourceId = null;
    public string $assign_name = '';
    public ?int $assign_client_id = null;
    public ?int $assign_provider_id = null;
    public ?int $assign_location_id = null;

    // Modal confirmación de envío de usuarios
    public bool $showPushModal = false;
    public ?int $pushSourceId = null;
    public int $pushCount = 0;

    public function rules(): array
    {
        return [
            'name'                  => 'nullable|string|max:255',
            'serial_number'         => 'required|string|max:255',
            'site_name'             => 'nullable|string|max:255',
            'status'                => 'required|in:active,inactive',
            'client_id'             => 'nullable|exists:clients,id',
            'biometric_provider_id' => 'nullable|exists:biometric_providers,id',
            'factorial_location_id' => 'nullable|exists:factorial_locations,id',
        ];
    }

    public function with(): array
    {
        return [
            'devices'            => BiometricSource::with(['client', 'location'])
                ->whereNotNull('client_id')
                ->withCount('attendanceLogs')
                ->paginate(10),
            'unassigned'         => BiometricSource::whereNull('client_id')
                ->orderByDesc('last_ping_at')
                ->get(),
            'clients'            => Client::orderBy('name')->get(),
            'locations'          => FactorialLocation::orderBy('name')->get(),
            'providers'          => BiometricProvider::orderBy('name')->get(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->editing = false;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $device = BiometricSource::findOrFail($id);
        $this->editingId              = $device->id;
        $this->name                   = $device->name;
        $this->serial_number          = $device->serial_number;
        $this->site_name              = $device->site_name ?? '';
        $this->status                 = $device->status;
        $this->client_id              = $device->client_id;
        $this->biometric_provider_id  = $device->biometric_provider_id;
        $this->factorial_location_id  = $device->factorial_location_id;
        $this->editing   = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editing) {
            BiometricSource::findOrFail($this->editingId)->update($data);
        } else {
            BiometricSource::create(array_merge($data, ['vendor' => 'ZKTeco']));
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        BiometricSource::findOrFail($id)->delete();
    }

    public function toggleStatus(int $id): void
    {
        $device = BiometricSource::findOrFail($id);
        $device->update(['status' => $device->status === 'active' ? 'inactive' : 'active']);
    }

    // ── Asignar equipo descubierto ─────────────────────────────────

    public function openAssign(int $id): void
    {
        $source = BiometricSource::findOrFail($id);
        $this->assigningSourceId = $source->id;
        $this->assign_name       = $source->serial_number ?? '';
        $this->assign_client_id  = null;
        $this->assign_provider_id = null;
        $this->assign_location_id = null;
        $this->showAssignModal   = true;
    }

    public function saveAssign(): void
    {
        $this->validate([
            'assign_client_id' => 'required|exists:clients,id',
        ]);

        $client = Client::findOrFail($this->assign_client_id);
        $source = BiometricSource::findOrFail($this->assigningSourceId);

        // Auto-crear proveedor biométrico si no existe para este cliente
        $connection = FactorialConnection::where('client_id', $client->id)->first();
        $provider   = BiometricProvider::firstOrCreate(
            ['client_id' => $client->id],
            [
                'factorial_connection_id' => $connection?->id,
                'vendor'                  => 'zkteco',
                'name'                    => 'ZKTeco ' . $client->name,
                'status'                  => 'active',
            ]
        );

        $source->update([
            'name'                  => $source->serial_number,
            'client_id'             => $client->id,
            'biometric_provider_id' => $provider->id,
            'status'                => 'active',
        ]);

        $this->showAssignModal   = false;
        $this->assigningSourceId = null;
    }

    // ── Enviar usuarios al dispositivo ────────────────────────────

    public function openPush(int $id): void
    {
        $source = BiometricSource::findOrFail($id);
        $this->pushSourceId = $source->id;
        $this->pushCount    = FactorialEmployee::where('client_id', $source->client_id)
            ->whereNotNull('access_id')
            ->where('active', true)
            ->count();
        $this->showPushModal = true;
    }

    public function confirmPush(): void
    {
        $source = BiometricSource::findOrFail($this->pushSourceId);

        $employees = FactorialEmployee::where('client_id', $source->client_id)
            ->whereNotNull('access_id')
            ->where('active', true)
            ->get();

        $maxSeq = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;

        $now     = now();
        $inserts = [];

        foreach ($employees as $i => $employee) {
            $seq      = $maxSeq + $i + 1;
            $pin      = $employee->access_id;
            $name     = mb_substr($employee->full_name, 0, 24); // ZKTeco max 24 chars
            $payload  = "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPassword=\tCard=\tRole=0";

            $inserts[] = [
                'biometric_source_id' => $source->id,
                'command_seq'         => $seq,
                'command_type'        => 'set_user',
                'payload'             => $payload,
                'status'              => 'pending',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        if (!empty($inserts)) {
            DeviceCommand::insert($inserts);
        }

        $this->showPushModal = false;
        $this->pushSourceId  = null;
    }

    private function resetForm(): void
    {
        $this->editingId             = null;
        $this->name                  = '';
        $this->serial_number         = '';
        $this->site_name             = '';
        $this->status                = 'active';
        $this->client_id             = null;
        $this->biometric_provider_id = null;
        $this->factorial_location_id = null;
        $this->resetValidation();
    }
}; ?>

<div>
    {{-- ── Equipos descubiertos (sin asignar) ───────────────────────── --}}
    @if($unassigned->isNotEmpty())
    <div class="mb-8 rounded-lg overflow-hidden border border-amber-300 shadow-sm">
        <div class="px-5 py-3 bg-amber-400 flex items-center gap-2">
            <svg class="w-4 h-4 text-amber-900 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <h3 class="text-sm font-semibold text-amber-900">Equipos descubiertos sin asignar ({{ $unassigned->count() }})</h3>
        </div>
        <table class="min-w-full divide-y divide-amber-200">
            <thead class="bg-amber-100">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-amber-800 uppercase tracking-wider">Serial</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-amber-800 uppercase tracking-wider">Último ping</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-amber-800 uppercase tracking-wider">Acción</th>
                </tr>
            </thead>
            <tbody class="bg-amber-50 divide-y divide-amber-200">
                @foreach($unassigned as $device)
                <tr class="hover:bg-amber-100 transition">
                    <td class="px-6 py-3 text-sm font-mono font-medium text-amber-900">
                        {{ $device->serial_number ?? '—' }}
                    </td>
                    <td class="px-6 py-3 text-sm text-amber-700">
                        {{ $device->last_ping_at?->diffForHumans() ?? 'Nunca' }}
                    </td>
                    <td class="px-6 py-3 text-right">
                        <button wire:click="openAssign({{ $device->id }})"
                            class="inline-flex items-center px-3 py-1.5 bg-amber-600 text-white text-xs font-semibold rounded-md hover:bg-amber-700 transition">
                            Asignar empresa
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- ── Header equipos registrados ───────────────────────────────── --}}
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800">Dispositivos biométricos</h2>
        <button wire:click="openCreate" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nuevo dispositivo
        </button>
    </div>

    {{-- ── Tabla dispositivos registrados ───────────────────────────── --}}
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Serial</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ubicación</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registros</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Último ping</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($devices as $device)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $device->name }}</div>
                        <div class="text-xs text-gray-500">{{ $device->vendor }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-mono">{{ $device->serial_number }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $device->client?->name ?? '—' }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $device->location?->name ?? $device->site_name ?? '—' }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $device->attendance_logs_count }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $device->last_ping_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <button wire:click="toggleStatus({{ $device->id }})"
                            class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full cursor-pointer {{ $device->status === 'active' ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                            {{ $device->status === 'active' ? 'Activo' : 'Inactivo' }}
                        </button>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button wire:click="openPush({{ $device->id }})"
                            title="Enviar usuarios de Factorial al dispositivo"
                            class="text-emerald-600 hover:text-emerald-900">
                            <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                            </svg>
                            Enviar usuarios
                        </button>
                        <button wire:click="openEdit({{ $device->id }})" class="text-indigo-600 hover:text-indigo-900">Editar</button>
                        <button wire:click="delete({{ $device->id }})" wire:confirm="¿Eliminar este dispositivo?" class="text-red-600 hover:text-red-900">Eliminar</button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">No hay dispositivos registrados.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($devices->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $devices->links() }}
        </div>
        @endif
    </div>

    {{-- ── Modal: Crear / Editar dispositivo ────────────────────────── --}}
    @if($showModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">{{ $editing ? 'Editar dispositivo' : 'Nuevo dispositivo' }}</h3>
                <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-6 py-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nombre</label>
                    <input wire:model="name" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Número de serie</label>
                    <input wire:model="serial_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('serial_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Sitio / Sucursal</label>
                    <input wire:model="site_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('site_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">
                            Cliente <span class="text-gray-400 font-normal">(opcional)</span>
                        </label>
                        <select wire:model="client_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Sin asignar</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endforeach
                        </select>
                        @error('client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Estado</label>
                        <select wire:model="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="active">Activo</option>
                            <option value="inactive">Inactivo</option>
                        </select>
                    </div>
                </div>
                @if(!$editing && !$client_id)
                <p class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                    Sin cliente asignado, el equipo aparecerá en el panel de dispositivos detectados para asignarlo después.
                </p>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700">Proveedor biométrico</label>
                    <select wire:model="biometric_provider_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Sin asignar</option>
                        @foreach($providers as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Ubicación Factorial</label>
                    <select wire:model="factorial_location_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Sin asignar</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                <button wire:click="$set('showModal', false)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="save" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                    {{ $editing ? 'Guardar cambios' : 'Crear dispositivo' }}
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Modal: Asignar equipo descubierto ────────────────────────── --}}
    @if($showAssignModal)
    @php $assigningSource = $assigningSourceId ? \App\Models\BiometricSource::find($assigningSourceId) : null; @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Asignar equipo a empresa</h3>
                    @if($assigningSource)
                    <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $assigningSource->serial_number }}</p>
                    @endif
                </div>
                <button wire:click="$set('showAssignModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-6 py-5">
                <label class="block text-sm font-medium text-gray-700 mb-1">Empresa</label>
                <select wire:model="assign_client_id" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Seleccionar empresa...</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
                @error('assign_client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-2 text-xs text-gray-400">El proveedor biométrico se crea automáticamente si no existe.</p>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="$set('showAssignModal', false)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="saveAssign" class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-md hover:bg-amber-700">
                    Asignar
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Modal: Confirmar envío de usuarios ───────────────────────── --}}
    @if($showPushModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-5 border-b border-gray-200 flex items-center gap-3">
                <div class="w-9 h-9 bg-emerald-100 rounded-full flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-gray-900">Enviar usuarios al dispositivo</h3>
            </div>

            <div class="px-6 py-4">
                @if($pushCount > 0)
                    <p class="text-sm text-gray-600">
                        Se encolarán <span class="font-semibold text-gray-900">{{ $pushCount }}</span> empleados activos con PIN registrado.
                        El equipo los recibirá en su próxima sincronización.
                    </p>
                @else
                    <p class="text-sm text-gray-600 mb-3">
                        No hay empleados activos con <code class="bg-gray-100 px-1 rounded text-xs">access_id</code> para este cliente.
                    </p>
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-4 py-3">
                        Sincroniza primero los empleados desde Factorial con <code class="text-xs">php artisan factorial:sync-employees</code>.
                    </p>
                @endif
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="$set('showPushModal', false)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Cancelar
                </button>
                @if($pushCount > 0)
                <button wire:click="confirmPush" class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700">
                    Confirmar envío
                </button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
