<?php

use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Models\FactorialLocation;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination, WithFileUploads;

    public bool $showModal = false;
    public bool $editing = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $serial_number = '';
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

    // Modal CSV import
    public bool   $showCsvModal = false;
    public ?int   $csvSourceId  = null;
    public $csvFile             = null;
    public string $importError  = '';
    public ?array $csvResult    = null;

    public function rules(): array
    {
        return [
            'name'                  => 'nullable|string|max:255',
            'serial_number'         => 'required|string|max:255',
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
            'assign_name'      => 'required|string|max:255',
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
            'name'                  => $this->assign_name,
            'client_id'             => $client->id,
            'biometric_provider_id' => $provider->id,
            'status'                => 'active',
        ]);

        $this->showAssignModal   = false;
        $this->assigningSourceId = null;
    }

    // ── CSV Import ────────────────────────────────────────────────

    public function openCsvModal(int $id): void
    {
        $this->csvSourceId  = $id;
        $this->csvFile      = null;
        $this->importError  = '';
        $this->csvResult    = null;
        $this->showCsvModal = true;
    }

    public function closeCsvModal(): void
    {
        $this->showCsvModal = false;
        $this->csvSourceId  = null;
        $this->csvFile      = null;
        $this->importError  = '';
        $this->csvResult    = null;
    }

    public function uploadCsv(): void
    {
        $this->importError = '';
        $this->csvResult   = null;

        $source = $this->csvSourceId ? BiometricSource::find($this->csvSourceId) : null;
        if (!$source) { $this->importError = 'Dispositivo no encontrado.'; return; }

        try {
            $this->validate(['csvFile' => 'required|file|max:2048']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->importError = collect($e->errors())->flatten()->first() ?? 'Archivo inválido.';
            return;
        }

        if (!in_array(strtolower($this->csvFile->getClientOriginalExtension()), ['csv', 'txt'])) {
            $this->importError = 'Solo se aceptan archivos .csv o .txt';
            return;
        }

        $path = $this->csvFile->getRealPath();
        $rows = [];

        if (($handle = fopen($path, 'r')) === false) {
            $this->importError = 'No se pudo leer el archivo.';
            return;
        }

        $header = null;
        while (($line = fgetcsv($handle, 1000, ',')) !== false) {
            if (!$header) {
                $header = array_map('strtolower', array_map('trim', $line));
                continue;
            }
            if (count($line) < count($header)) continue;
            $row  = array_combine($header, array_slice($line, 0, count($header)));
            $pin  = trim($row['pin'] ?? '');
            $name = trim($row['nombre'] ?? $row['name'] ?? '');
            if ($pin === '') continue;
            $rows[] = [
                'pin'  => mb_convert_encoding($pin,  'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252'),
                'name' => mb_convert_encoding($name, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252'),
            ];
        }
        fclose($handle);

        if (empty($rows)) {
            $this->importError = 'Sin registros válidos. Columnas requeridas: pin, nombre.';
            return;
        }

        // Guardar device_users en este dispositivo
        $source->update([
            'device_users' => array_map(
                fn($r) => ['pin' => $r['pin'], 'name' => $r['name'], 'card' => '', 'role' => '0'],
                $rows
            ),
        ]);

        // Auto-match 100% contra empleados Factorial
        $employees       = FactorialEmployee::where('client_id', $source->client_id)->get();
        $existingMappings = BiometricUserSync::where('client_id', $source->client_id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');
        $provider = BiometricProvider::where('client_id', $source->client_id)->first();
        $now      = now();
        $autoMapped = 0;
        $pending    = 0;

        foreach ($rows as $row) {
            if (isset($existingMappings[$row['pin']])) continue;

            $bestScore = 0; $bestEmpId = null;
            foreach ($employees as $emp) {
                similar_text(mb_strtolower($row['name']), mb_strtolower($emp->full_name), $pct);
                if ($pct > $bestScore) { $bestScore = $pct; $bestEmpId = $emp->id; }
            }

            if ($bestScore >= 100 && $bestEmpId && $provider) {
                BiometricUserSync::updateOrCreate(
                    ['biometric_provider_id' => $provider->id, 'factorial_employee_id' => $bestEmpId],
                    ['client_id' => $source->client_id, 'external_employee_code' => $row['pin'], 'sync_status' => 'pending', 'last_attempt_at' => $now]
                );
                AttendanceLog::where('client_id', $source->client_id)
                    ->where('employee_code', $row['pin'])
                    ->whereNull('factorial_employee_id')
                    ->update(['factorial_employee_id' => $bestEmpId, 'sync_status' => 'resolved']);
                $autoMapped++;
            } else {
                $pending++;
            }
        }

        $this->csvResult = [
            'total'      => count($rows),
            'autoMapped' => $autoMapped,
            'pending'    => $pending,
            'existing'   => count($rows) - $autoMapped - $pending,
        ];
        $this->csvFile = null;
    }

    private function resetForm(): void
    {
        $this->editingId             = null;
        $this->name                  = '';
        $this->serial_number         = '';
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
                        <div class="text-xs text-gray-400">{{ $device->vendor }}</div>
                        <div class="text-xs text-gray-400 font-mono">{{ $device->serial_number }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $device->client?->name ?? '—' }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ $device->location?->name ?? '—' }}</td>
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
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                        <div class="flex items-center justify-end gap-3">
                            {{-- Importar CSV --}}
                            <button wire:click="openCsvModal({{ $device->id }})" title="Importar empleados desde CSV"
                                class="text-emerald-500 hover:text-emerald-700">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                </svg>
                            </button>
                            {{-- Editar --}}
                            <button wire:click="openEdit({{ $device->id }})" title="Editar"
                                class="text-indigo-500 hover:text-indigo-700">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                            {{-- Eliminar --}}
                            <button wire:click="delete({{ $device->id }})" wire:confirm="¿Eliminar este dispositivo?" title="Eliminar"
                                class="text-red-400 hover:text-red-600">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-500">No hay dispositivos registrados.</td>
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

            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del dispositivo</label>
                    <input wire:model="assign_name" type="text" placeholder="Ej: Entrada principal, Comedor..." class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('assign_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empresa</label>
                    <select wire:model="assign_client_id" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Seleccionar empresa...</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                    @error('assign_client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <p class="text-xs text-gray-400">El proveedor biométrico se crea automáticamente si no existe.</p>
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

    {{-- ── Modal: Importar CSV ──────────────────────────────────────── --}}
    @if($showCsvModal)
    @php $csvSource = $csvSourceId ? \App\Models\BiometricSource::find($csvSourceId) : null; @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" wire:click.self="closeCsvModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Importar empleados desde CSV</h3>
                    @if($csvSource)
                        <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $csvSource->name }} · {{ $csvSource->serial_number }}</p>
                    @endif
                </div>
                <button wire:click="closeCsvModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>

            <div class="px-6 py-5 space-y-4">
                @if($csvResult)
                    {{-- Resultado --}}
                    <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 space-y-1">
                        <p class="text-sm font-semibold text-emerald-800">Importación completada</p>
                        <p class="text-sm text-emerald-700">{{ $csvResult['total'] }} usuarios importados al dispositivo</p>
                        <div class="flex gap-4 mt-2 text-xs">
                            <span class="text-green-700 font-medium">✓ {{ $csvResult['autoMapped'] }} auto-mapeados</span>
                            @if($csvResult['existing'] > 0)
                                <span class="text-gray-500">{{ $csvResult['existing'] }} ya existían</span>
                            @endif
                            @if($csvResult['pending'] > 0)
                                <span class="text-amber-600 font-medium">⚠ {{ $csvResult['pending'] }} pendientes de mapeo</span>
                            @endif
                        </div>
                    </div>
                    @if($csvResult['pending'] > 0)
                        <p class="text-xs text-gray-500">Completa el mapeo en <strong>Empleados → Mapping</strong>.</p>
                    @endif
                @else
                    <input wire:model="csvFile" type="file" accept=".csv,.txt"
                        class="block w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100"/>
                    @if($importError)
                        <p class="text-xs text-red-600">{{ $importError }}</p>
                    @endif
                    <p class="text-xs text-gray-400">Columnas requeridas: <code class="bg-gray-100 px-1 rounded">pin</code>, <code class="bg-gray-100 px-1 rounded">nombre</code></p>
                @endif
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="closeCsvModal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    {{ $csvResult ? 'Cerrar' : 'Cancelar' }}
                </button>
                @if(!$csvResult)
                <button wire:click="uploadCsv" wire:loading.attr="disabled"
                    class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="uploadCsv">Importar y mapear</span>
                    <span wire:loading wire:target="uploadCsv">Procesando…</span>
                </button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
