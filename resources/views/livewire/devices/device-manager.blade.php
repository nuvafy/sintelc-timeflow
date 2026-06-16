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

    public string $statusFilter = '';
    public string $clientFilter = '';

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

    // Modal importar empleados (tabbed: Factorial / CSV)
    public bool    $showImportModal   = false;
    public string  $importTab         = 'factorial';
    public ?int    $csvSourceId       = null;
    public $csvFile                   = null;
    public string  $importError       = '';
    public ?array  $csvResult         = null;
    public ?int    $pushSourceId      = null;
    public int     $pushEmployeeCount = 0;
    public ?string $pushSuccessMsg    = null;

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
        $devices = BiometricSource::with(['client', 'location'])
            ->whereNotNull('client_id')
            ->withCount('attendanceLogs')
            ->when($this->statusFilter === 'online',   fn($q) => $q->where('status', 'active')->where('last_ping_at', '>=', now()->subMinutes(15)))
            ->when($this->statusFilter === 'recent',   fn($q) => $q->where('status', 'active')->whereBetween('last_ping_at', [now()->subHour(), now()->subMinutes(15)]))
            ->when($this->statusFilter === 'offline',  fn($q) => $q->where('status', 'active')->where(fn($q2) => $q2->whereNull('last_ping_at')->orWhere('last_ping_at', '<', now()->subHour())))
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('status', 'inactive'))
            ->when($this->clientFilter,                fn($q) => $q->where('client_id', $this->clientFilter))
            ->paginate(10);

        $sourceIds = $devices->pluck('id');
        $pushStatus = DeviceCommand::whereIn('biometric_source_id', $sourceIds)
            ->where('command_type', 'set_user')
            ->orderByDesc('id')
            ->get()
            ->unique('biometric_source_id')
            ->keyBy('biometric_source_id')
            ->map(fn($cmd) => $cmd->status);

        return [
            'devices'    => $devices,
            'pushStatus' => $pushStatus,
            'unassigned'         => BiometricSource::whereNull('client_id')
                ->orderByDesc('last_ping_at')
                ->get(),
            'clients'            => Client::orderBy('name')->get(),
            'locations'          => FactorialLocation::orderBy('name')->get(),
            'providers'          => $this->client_id
                ? BiometricProvider::where('client_id', $this->client_id)->orderBy('vendor')->get()
                : collect(),
        ];
    }

    public function updatedClientFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void  { $this->resetPage(); }

    public function updatedClientId(): void
    {
        $this->biometric_provider_id = null;

        if ($this->client_id) {
            $provider = BiometricProvider::where('client_id', $this->client_id)->first();
            if ($provider) {
                $this->biometric_provider_id = $provider->id;
            }
        }
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
            BiometricSource::create($data);
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

    // ── Modal importar empleados ──────────────────────────────────

    public function openImportModal(int $id, string $tab = 'factorial'): void
    {
        $source = BiometricSource::findOrFail($id);

        $this->importTab         = $tab;
        $this->pushSourceId      = $source->id;
        $this->pushSuccessMsg    = null;
        $this->pushEmployeeCount = FactorialEmployee::where('client_id', $source->client_id)
            ->where('active', true)
            ->count();

        $this->csvSourceId  = $source->id;
        $this->csvFile      = null;
        $this->importError  = '';
        $this->csvResult    = null;

        $this->showImportModal = true;
    }

    public function confirmPush(): void
    {
        $source = BiometricSource::findOrFail($this->pushSourceId);

        \Illuminate\Support\Facades\Artisan::call('biometric:push-users', [
            'sourceId' => $source->id,
        ]);

        $this->pushSuccessMsg = "{$this->pushEmployeeCount} usuario(s) encolados. El equipo los recibirá en su próximo ping.";
    }

    public function closeImportModal(): void
    {
        $this->showImportModal   = false;
        $this->pushSourceId      = null;
        $this->pushEmployeeCount = 0;
        $this->pushSuccessMsg    = null;
        $this->csvSourceId       = null;
        $this->csvFile           = null;
        $this->importError       = '';
        $this->csvResult         = null;
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

        $this->csvResult = [
            'total'   => count($rows),
            'message' => 'Usuarios cargados. Ve a Mapeo de empleados para asignarlos.',
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
    {{-- ── Tarjeta filtros ─────────────────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg px-5 py-3 mb-4">
        <div class="flex items-center justify-between gap-3">
            <select wire:model.live="clientFilter"
                class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todas las empresas</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}">{{ mb_substr($c->name, 0, 30) }}</option>
                @endforeach
            </select>
            <button wire:click="openCreate" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Nuevo dispositivo
            </button>
        </div>
        <div class="border-t border-gray-100 mt-4 pt-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-400 mr-1">Filtrar:</span>
                @foreach(['' => 'Todos', 'online' => 'En línea', 'recent' => 'Reciente', 'offline' => 'Sin señal', 'inactive' => 'Inactivo'] as $val => $label)
                <button
                    wire:click="$set('statusFilter', '{{ $val }}')"
                    class="px-3 py-1 rounded-full text-xs font-medium transition-colors
                        {{ $statusFilter === $val
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>
            <span class="text-xs text-gray-400">{{ $devices->total() }} dispositivo(s)</span>
        </div>
    </div>

    {{-- ── Equipos descubiertos (sin asignar) ───────────────────────── --}}
    @if($unassigned->isNotEmpty())
    <div class="mb-4 bg-white shadow rounded-lg overflow-hidden border-l-4 border-amber-400">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex items-center gap-2">
            <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <h3 class="text-sm font-semibold text-gray-700">Equipos descubiertos sin asignar <span class="text-amber-600">({{ $unassigned->count() }})</span></h3>
        </div>
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Serial</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Último ping</th>
                    <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acción</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @foreach($unassigned as $device)
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-5 py-3 text-sm font-mono font-medium text-gray-800">
                        {{ $device->serial_number ?? '—' }}
                    </td>
                    <td class="px-5 py-3 text-sm text-gray-500">
                        {{ $device->last_ping_at?->diffForHumans() ?? 'Nunca' }}
                    </td>
                    <td class="px-6 py-3 text-right">
                        <button wire:click="openAssign({{ $device->id }})"
                            class="inline-flex items-center px-3 py-1.5 bg-amber-500 text-white text-xs font-semibold rounded-md hover:bg-amber-600 transition">
                            Asignar empresa
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- ── Tabla resultados ────────────────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registros</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuarios en equipo</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Último ping</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($devices as $device)
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-3 whitespace-nowrap">
                        @php $vendorLabels = ['zkteco'=>'ZKTeco','hikvision'=>'Hikvision','suprema'=>'Suprema','other'=>'Otro']; @endphp
                        <div class="text-xs text-gray-400">{{ $vendorLabels[$device->provider?->vendor] ?? ($device->provider?->vendor ?? 'ZKTeco') }}</div>
                        <div class="text-sm font-medium text-gray-900">{{ $device->name }}</div>
                        <div class="text-xs text-gray-400 font-mono">{{ $device->serial_number }}</div>
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        <p class="text-sm text-gray-700">{{ $device->client?->name ?? '—' }}</p>
                        <p class="text-xs text-gray-400">{{ $device->location?->name ?? 'Sin ubicación' }}</p>
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-600">{{ $device->attendance_logs_count }}</td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        @php
                            $deviceUsers = $device->device_users ?? [];
                            $ps = $pushStatus[$device->id] ?? null;
                        @endphp
                        @if(count($deviceUsers) > 0)
                            <span class="text-sm font-medium text-gray-700">{{ count($deviceUsers) }}</span>
                            @if($device->device_users_fetched_at)
                                <p class="text-xs text-gray-400">{{ $device->device_users_fetched_at->diffForHumans() }}</p>
                            @endif
                        @else
                            <span class="text-xs text-gray-400">—</span>
                        @endif
                        @if($ps === 'acknowledged')
                            <p class="text-xs text-green-600 font-medium">✓ Push aceptado</p>
                        @elseif($ps === 'failed')
                            <p class="text-xs text-red-500 font-medium">✗ Push rechazado</p>
                        @elseif($ps === 'sent' || $ps === 'pending')
                            <p class="text-xs text-amber-500 font-medium">· Push pendiente</p>
                        @endif
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-500">
                        {{ $device->last_ping_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        @if($device->status !== 'active')
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-500">
                                Deshabilitado
                            </span>
                        @elseif(!$device->last_ping_at)
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-400">
                                Sin señal
                            </span>
                        @elseif($device->last_ping_at->gt(now()->subMinutes(15)))
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-700">
                                En línea
                            </span>
                        @elseif($device->last_ping_at->gt(now()->subHour()))
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-700">
                                Reciente
                            </span>
                        @else
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-600">
                                Sin señal
                            </span>
                        @endif
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-right">
                        <div class="flex items-center justify-end gap-3">
                            {{-- Importar empleados (Factorial / CSV) --}}
                            <button wire:click="openImportModal({{ $device->id }})" title="Importar empleados al dispositivo"
                                class="text-sky-500 hover:text-sky-700">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
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
        </div>{{-- overflow-x-auto --}}
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
                            @php $vendorLabels = ['zkteco'=>'ZKTeco','hikvision'=>'Hikvision','suprema'=>'Suprema','other'=>'Otro']; @endphp
                            <option value="{{ $provider->id }}">{{ $vendorLabels[$provider->vendor] ?? ucfirst($provider->vendor) }}</option>
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

    {{-- ── Modal: Importar empleados al dispositivo ───────────────── --}}
    @if($showImportModal)
    @php $importSource = $pushSourceId ? \App\Models\BiometricSource::find($pushSourceId) : null; @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" wire:click.self="closeImportModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">

            {{-- Header --}}
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Importar empleados</h3>
                    @if($importSource)
                        <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $importSource->name }} · {{ $importSource->serial_number }}</p>
                    @endif
                </div>
                <button wire:click="closeImportModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>

            {{-- Tabs --}}
            <div class="flex border-b border-gray-200">
                <button wire:click="$set('importTab', 'factorial')"
                    class="flex-1 py-2.5 text-sm font-medium transition border-b-2 {{ $importTab === 'factorial' ? 'border-sky-500 text-sky-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Desde Factorial
                </button>
                <button wire:click="$set('importTab', 'csv')"
                    class="flex-1 py-2.5 text-sm font-medium transition border-b-2 {{ $importTab === 'csv' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Desde CSV
                </button>
            </div>

            {{-- Tab: Factorial --}}
            @if($importTab === 'factorial')
            <div class="px-6 py-5">
                @if($pushSuccessMsg)
                    <div class="rounded-lg bg-sky-50 border border-sky-200 px-5 py-3 flex items-start gap-3">
                        <svg class="w-5 h-5 text-sky-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <p class="text-sm text-sky-800">{{ $pushSuccessMsg }}</p>
                    </div>
                @else
                    <p class="text-sm text-gray-700">
                        Se enviarán <span class="font-semibold text-gray-900">{{ $pushEmployeeCount }} empleado(s) activos</span>
                        de Factorial al dispositivo.
                    </p>
                    <p class="text-xs text-gray-400 mt-1">El equipo los recibirá en su próximo ping al servidor.</p>
                    @if($pushEmployeeCount === 0)
                        <p class="text-xs text-amber-600 mt-3 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                            No hay empleados activos para este cliente.
                        </p>
                    @endif
                @endif
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="closeImportModal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    {{ $pushSuccessMsg ? 'Cerrar' : 'Cancelar' }}
                </button>
                @if(!$pushSuccessMsg)
                <button wire:click="confirmPush" wire:loading.attr="disabled"
                    @disabled($pushEmployeeCount === 0)
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-sky-600 rounded-md hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="confirmPush">Enviar empleados</span>
                    <span wire:loading wire:target="confirmPush">Encolando…</span>
                </button>
                @endif
            </div>
            @endif

            {{-- Tab: CSV --}}
            @if($importTab === 'csv')
            <div class="px-6 py-5 space-y-4">
                @if($csvResult)
                    <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-5 py-3 space-y-1">
                        <p class="text-sm font-semibold text-emerald-800">Archivo importado correctamente</p>
                        <p class="text-sm text-emerald-700">{{ $csvResult['total'] }} usuarios guardados en el dispositivo.</p>
                        <p class="text-xs text-emerald-600 mt-1">{{ $csvResult['message'] }}</p>
                    </div>
                @else
                    <input wire:model="csvFile" type="file" accept=".csv,.txt"
                        class="block w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100"/>
                    @if($importError)
                        <p class="text-xs text-red-600">{{ $importError }}</p>
                    @endif
                    <p class="text-xs text-gray-400">
                        Columnas requeridas: <code class="bg-gray-100 px-1 rounded">pin</code>, <code class="bg-gray-100 px-1 rounded">nombre</code>
                        &nbsp;·&nbsp;
                        <a href="{{ route('templates.empleados') }}" class="text-emerald-600 hover:text-emerald-800 underline">Descargar plantilla</a>
                    </p>
                @endif
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="closeImportModal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    {{ $csvResult ? 'Cerrar' : 'Cancelar' }}
                </button>
                @if(!$csvResult)
                <button wire:click="uploadCsv" wire:loading.attr="disabled"
                    class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-md hover:bg-emerald-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="uploadCsv">Importar usuarios</span>
                    <span wire:loading wire:target="uploadCsv">Importando…</span>
                </button>
                @endif
            </div>
            @endif

        </div>
    </div>
    @endif
</div>
