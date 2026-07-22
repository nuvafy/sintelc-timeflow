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
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

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

    // Modal clonar biométricos
    public bool    $showCloneModal        = false;
    public ?int    $cloneTargetId         = null;
    public ?int    $cloneSourceId         = null;
    public ?string $cloneSuccessMsg       = null;

    // Modal importar empleados
    public bool    $showImportModal       = false;
    public ?int    $pushSourceId          = null;
    public string  $importMode            = 'factorial'; // 'factorial' | 'sintelc'
    public int     $pushEmployeeCount     = 0; // total activos en Factorial
    public int     $pushNewCount          = 0; // solo los no mapeados/no en device
    public int     $pushSintelcCount      = 0; // mapeados en Sintelc con PIN real
    public ?string $pushSuccessMsg        = null;


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
        $user    = auth()->user();
        $isAdmin = $user->isAdmin();

        $devices = BiometricSource::with(['client', 'location'])
            ->whereNotNull('client_id')
            ->when(!$isAdmin, fn($q) => $q->where('client_id', $user->client_id))
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
            'isAdmin'            => $isAdmin,
            'unassigned'         => $isAdmin
                ? BiometricSource::whereNull('client_id')->orderByDesc('last_ping_at')->get()
                : collect(),
            'clients'            => Client::query()
                ->when(!$isAdmin, fn($q) => $q->whereKey($user->client_id))
                ->orderBy('name')->get(),
            'locations'          => FactorialLocation::query()
                ->when(!$isAdmin, fn($q) => $q->where('client_id', $user->client_id))
                ->orderBy('name')->get(),
            'providers'          => ($isAdmin ? $this->client_id : $user->client_id)
                ? BiometricProvider::where('client_id', $isAdmin ? $this->client_id : $user->client_id)->orderBy('vendor')->get()
                : collect(),
        ];
    }

    public function updatedClientFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void  { $this->resetPage(); }

    public function updatedClientId(): void
    {
        if (auth()->user()->isClient()) {
            abort_unless((int) $this->client_id === (int) auth()->user()->client_id, 403);
        }

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
        $device = $this->authorizedDevice($id);
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
        $user = auth()->user();

        if ($user->isClient()) {
            $this->validate(['name' => 'nullable|string|max:255', 'serial_number' => 'required|string|max:255']);

            $source = BiometricSource::where('serial_number', $this->serial_number)
                ->whereNull('client_id')
                ->first();

            if (!$source) {
                $assigned = BiometricSource::where('serial_number', $this->serial_number)->exists();
                $this->addError('serial_number', $assigned
                    ? 'Este dispositivo ya está registrado en otra empresa.'
                    : 'El dispositivo aún no se ha conectado al servidor. Verifica la configuración e intenta de nuevo.');
                return;
            }

            $provider = BiometricProvider::firstOrCreate(
                ['client_id' => $user->client_id],
                ['vendor' => 'zkteco', 'status' => 'active']
            );

            $source->update([
                'name'                  => $this->name ?: $this->serial_number,
                'client_id'             => $user->client_id,
                'biometric_provider_id' => $provider->id,
                'status'                => 'active',
                'onboarding_status'     => 'assigned',
            ]);

            app(\App\Services\DeviceOnboardingService::class)->requestInventory($source->fresh());

            $this->showModal = false;
            $this->resetForm();
            return;
        }

        $data = $this->validate();

        if ($this->editing) {
            $this->authorizedDevice($this->editingId)->update($data);
        } else {
            BiometricSource::create($data);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $this->authorizedDevice($id)->delete();
    }

    public function toggleStatus(int $id): void
    {
        $device = $this->authorizedDevice($id);
        $device->update(['status' => $device->status === 'active' ? 'inactive' : 'active']);
    }

    // ── Asignar equipo descubierto ─────────────────────────────────

    public function openAssign(int $id): void
    {
        $this->authorizeAdmin();
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
        $this->authorizeAdmin();
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
            'onboarding_status'     => 'assigned',
        ]);

        app(\App\Services\DeviceOnboardingService::class)->requestInventory($source->fresh());

        $this->showAssignModal   = false;
        $this->assigningSourceId = null;
    }

    // ── Modal importar empleados ──────────────────────────────────

    public function openImportModal(int $id): void
    {
        $source = $this->authorizedDevice($id);

        $this->pushSourceId   = $source->id;
        $this->pushSuccessMsg = null;
        $this->importMode     = 'factorial';

        // Empleados ya mapeados en este proveedor
        $mappedFactorialIds = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->pluck('factorial_employee_id')
            ->toArray();

        // PINs ya presentes en el dispositivo
        $devicePins = collect($source->device_users ?? [])->pluck('pin')->map(fn($p) => (string)$p)->toArray();

        $this->pushEmployeeCount = FactorialEmployee::where('client_id', $source->client_id)
            ->where('active', true)->count();

        // Solo los que no están mapeados y no están en el dispositivo
        $this->pushNewCount = FactorialEmployee::where('client_id', $source->client_id)
            ->where('active', true)
            ->whereNotIn('id', $mappedFactorialIds)
            ->get()
            ->filter(fn($e) => !in_array((string)$e->factorial_id, $devicePins, true))
            ->count();

        // Mapeados en Sintelc (todos, independientemente del PIN)
        $this->pushSintelcCount = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->whereNotNull('factorial_employee_id')
            ->count();

        $this->showImportModal = true;
    }


    public function confirmPush(): void
    {
        $source = $this->authorizedDevice($this->pushSourceId);

        $isAttendance = true; // SDK recomienda Attendance PUSH (DATA UPDATE USERINFO) para todos los modelos

        if ($this->importMode === 'sintelc') {
            $this->_pushFromSintelc($source, $isAttendance);
        } else {
            $this->_pushFromFactorial($source, $isAttendance);
        }
    }

    private function _pushFromFactorial(BiometricSource $source, bool $isAttendance): void
    {
        $mappedFactorialIds = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->pluck('factorial_employee_id')
            ->toArray();

        $devicePins = collect($source->device_users ?? [])->pluck('pin')->map(fn($p) => (string)$p)->toArray();

        $employees = FactorialEmployee::where('client_id', $source->client_id)
            ->where('active', true)
            ->whereNotIn('id', $mappedFactorialIds)
            ->get()
            ->filter(fn($e) => !in_array((string)$e->factorial_id, $devicePins, true));

        if ($employees->isEmpty()) {
            $this->pushSuccessMsg = 'No hay empleados nuevos para enviar.';
            return;
        }

        $maxSeq  = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;
        $now     = now();
        $inserts = [];

        foreach ($employees->values() as $i => $employee) {
            $pin     = $employee->factorial_id;
            $name    = mb_substr($employee->full_name, 0, 24);
            $payload = $isAttendance
                ? "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPassword=\tPrivilege=0\tGroup=1"
                : "DATA UPDATE user CardNo=\tPin={$pin}\tPassword=\tGroup=1\tStartTime=0\tEndTime=0\tName={$name}\tPrivilege=0";

            $inserts[] = [
                'biometric_source_id' => $source->id,
                'command_seq'         => $maxSeq + $i + 1,
                'command_type'        => 'set_user',
                'payload'             => $payload,
                'status'              => 'pending',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        DeviceCommand::insert($inserts);

        // Añadir al device_users existente (no reemplazar)
        $existing = collect($source->device_users ?? []);
        $newUsers = $employees->values()->map(fn($e) => [
            'pin'  => (string)$e->factorial_id,
            'name' => mb_substr($e->full_name, 0, 24),
        ]);
        $source->update([
            'device_users'            => $existing->concat($newUsers)->unique('pin')->values()->toArray(),
            'device_users_fetched_at' => $now,
        ]);

        // Crear mapeos solo para los enviados
        $mappings = $employees->values()->map(fn($e) => [
            'biometric_provider_id'  => $source->biometric_provider_id,
            'factorial_employee_id'  => $e->id,
            'client_id'              => $source->client_id,
            'external_employee_code' => (string)$e->factorial_id,
            'sync_status'            => 'synced',
            'created_at'             => $now,
            'updated_at'             => $now,
        ])->toArray();

        BiometricUserSync::upsert(
            $mappings,
            ['biometric_provider_id', 'factorial_employee_id'],
            ['external_employee_code', 'sync_status', 'updated_at']
        );

        $count = $employees->count();
        $this->pushSuccessMsg = "{$count} empleado(s) nuevos encolados. El equipo los recibirá en su próximo ping.";
    }

    private function _pushFromSintelc(BiometricSource $source, bool $isAttendance): void
    {
        $syncs = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->whereNotNull('factorial_employee_id')
            ->with('factorialEmployee')
            ->get();

        if ($syncs->isEmpty()) {
            $this->pushSuccessMsg = 'No hay empleados mapeados en Sintelc para enviar.';
            return;
        }

        $maxSeq  = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;
        $now     = now();
        $inserts = [];
        $users   = [];

        foreach ($syncs as $i => $sync) {
            $employee = $sync->factorialEmployee;
            if (!$employee) continue;

            $pin  = $sync->external_employee_code; // PIN real del dispositivo
            $name = mb_substr($employee->full_name, 0, 24);

            $payload = $isAttendance
                ? "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPassword=\tPrivilege=0\tGroup=1"
                : "DATA UPDATE user CardNo=\tPin={$pin}\tPassword=\tGroup=1\tStartTime=0\tEndTime=0\tName={$name}\tPrivilege=0";

            $inserts[] = [
                'biometric_source_id' => $source->id,
                'command_seq'         => $maxSeq + $i + 1,
                'command_type'        => 'set_user',
                'payload'             => $payload,
                'status'              => 'pending',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];

            $users[] = ['pin' => $pin, 'name' => $name];
        }

        if (empty($inserts)) {
            $this->pushSuccessMsg = 'No se pudo generar comandos.';
            return;
        }

        DeviceCommand::insert($inserts);

        $existing = collect($source->device_users ?? []);
        $source->update([
            'device_users'            => $existing->concat($users)->unique('pin')->values()->toArray(),
            'device_users_fetched_at' => $now,
        ]);

        $count = count($inserts);
        $this->pushSuccessMsg = "{$count} empleado(s) mapeados encolados con su PIN de Sintelc. El equipo los recibirá en su próximo ping.";
    }

    public function closeImportModal(): void
    {
        $this->showImportModal   = false;
        $this->pushSourceId      = null;
        $this->pushEmployeeCount = 0;
        $this->pushSuccessMsg    = null;
    }

    public function openCloneModal(int $id): void
    {
        $this->authorizedDevice($id);
        $this->cloneTargetId   = $id;
        $this->cloneSourceId   = null;
        $this->cloneSuccessMsg = null;
        $this->showCloneModal  = true;
    }

    public function startClone(): void
    {
        if (!$this->cloneTargetId || !$this->cloneSourceId) return;

        $target = $this->authorizedDevice($this->cloneTargetId);
        $source = $this->authorizedDevice($this->cloneSourceId);
        abort_unless((int) $target->client_id === (int) $source->client_id, 403);

        // Marcar el source con el target para cuando llegue el BIODATA
        $source->update(['clone_target_id' => $target->id]);

        // Encolar QUERY BIODATA al source
        $seq = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') + 1;
        DeviceCommand::create([
            'biometric_source_id' => $source->id,
            'command_seq'         => $seq,
            'command_type'        => 'query_biodata',
            'payload'             => 'DATA QUERY BIODATA',
            'status'              => 'pending',
        ]);

        $this->cloneSuccessMsg = "Solicitud enviada a {$source->name}. Los biométricos se copiarán a {$target->name} automáticamente cuando el equipo responda.";
    }

    public function closeCloneModal(): void
    {
        $this->showCloneModal  = false;
        $this->cloneTargetId   = null;
        $this->cloneSourceId   = null;
        $this->cloneSuccessMsg = null;
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

    private function authorizedDevice(?int $id): BiometricSource
    {
        abort_unless($id, 404);

        $device = BiometricSource::findOrFail($id);
        $user   = auth()->user();

        abort_unless($user, 403);
        if ($user->isClient()) {
            abort_unless($user->client_id && (int) $device->client_id === (int) $user->client_id, 403);
        }

        return $device;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }
}; ?>

<div>
    {{-- ── Tarjeta filtros ─────────────────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg px-5 py-3 mb-4">
        <div class="flex items-center justify-between gap-3">
            @if($isAdmin)
            <select wire:model.live="clientFilter"
                class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">Todas las empresas</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}">{{ mb_substr($c->name, 0, 30) }}</option>
                @endforeach
            </select>
            @else
            <span class="text-sm text-gray-500">Mis dispositivos</span>
            @endif
            <button wire:click="openCreate" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                {{ $isAdmin ? 'Nuevo dispositivo' : 'Agregar dispositivo' }}
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

    {{-- ── Equipos descubiertos (sin asignar) — solo admin ─────────── --}}
    @if($isAdmin && $unassigned->isNotEmpty())
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
                    @if($isAdmin && !$clientFilter)
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                    @endif
                    <th class="px-5 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Registros</th>
                    <th class="px-5 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Usuarios en equipo</th>
                    <th class="px-5 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Último ping</th>
                    <th class="px-5 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    <th class="px-5 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
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
                    @if($isAdmin && !$clientFilter)
                    <td class="px-5 py-3 whitespace-nowrap">
                        <p class="text-sm text-gray-700">{{ $device->client?->name ?? '—' }}</p>
                        <p class="text-xs text-gray-400">{{ $device->location?->name ?? 'Sin ubicación' }}</p>
                    </td>
                    @endif
                    <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-600 text-center">{{ $device->attendance_logs_count }}</td>
                    <td class="px-5 py-3 whitespace-nowrap text-center">
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
                    <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-500 text-center">
                        {{ $device->last_ping_at?->diffForHumans() ?? '—' }}
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-center">
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
                    <td class="px-5 py-3 whitespace-nowrap text-center">
                        <div class="flex items-center justify-center gap-3">
                            <a href="{{ route('devices.onboarding', $device) }}" wire:navigate title="Configurar y conciliar usuarios"
                                class="text-emerald-600 hover:text-emerald-800">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                </svg>
                            </a>
                            {{-- Enviar empleados al dispositivo --}}
                            <button wire:click="openImportModal({{ $device->id }})" title="Envío anterior (temporal)"
                                class="hidden text-sky-500 hover:text-sky-700">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </button>
                            {{-- Clonar biométricos desde otro dispositivo --}}
                            <button wire:click="openCloneModal({{ $device->id }})" title="Clonar biométricos desde otro dispositivo"
                                class="text-yellow-500 hover:text-yellow-600">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
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
                    <td colspan="{{ ($isAdmin && !$clientFilter) ? 7 : 6 }}" class="px-6 py-10 text-center text-sm text-gray-500">No hay dispositivos registrados.</td>
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
                <h3 class="text-lg font-medium text-gray-900">
                    {{ $editing ? 'Editar dispositivo' : ($isAdmin ? 'Nuevo dispositivo' : 'Agregar dispositivo') }}
                </h3>
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
                    <input wire:model="serial_number" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="{{ !$isAdmin ? 'Ej: CGXD230900001' : '' }}"/>
                    @error('serial_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if(!$isAdmin && !$editing)
                    <p class="mt-1 text-xs text-gray-400">Ingresa el número de serie tal como aparece en el dispositivo. Solo se puede agregar si ya está conectado al servidor.</p>
                    @endif
                </div>

                @if($isAdmin)
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
                @endif
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                <button wire:click="$set('showModal', false)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="save" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                    {{ $editing ? 'Guardar cambios' : ($isAdmin ? 'Crear dispositivo' : 'Agregar dispositivo') }}
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

    {{-- ── Modal: Importar empleados al dispositivo ──────────────────── --}}
    @if($showImportModal)
    @php $importSource = $pushSourceId ? \App\Models\BiometricSource::find($pushSourceId) : null; @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" wire:click.self="closeImportModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Enviar empleados al dispositivo</h3>
                    @if($importSource)
                        <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $importSource->name }} · {{ $importSource->serial_number }}</p>
                    @endif
                </div>
                <button wire:click="closeImportModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-5 space-y-4">
                @if($pushSuccessMsg)
                    <div class="rounded-lg bg-sky-50 border border-sky-200 px-5 py-3 flex items-start gap-3">
                        <svg class="w-5 h-5 text-sky-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <p class="text-sm text-sky-800">{{ $pushSuccessMsg }}</p>
                    </div>
                @else
                    {{-- Tabs --}}
                    <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm font-medium">
                        <button wire:click="$set('importMode', 'factorial')"
                            class="flex-1 py-2 px-3 text-center transition-colors
                                {{ $importMode === 'factorial' ? 'bg-sky-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">
                            Desde Factorial
                        </button>
                        <button wire:click="$set('importMode', 'sintelc')"
                            class="flex-1 py-2 px-3 text-center border-l border-gray-200 transition-colors
                                {{ $importMode === 'sintelc' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">
                            Desde Sintelc
                        </button>
                    </div>

                    @if($importMode === 'factorial')
                        <div class="space-y-2">
                            <p class="text-sm text-gray-700">
                                Se enviarán <span class="font-semibold text-gray-900">{{ $pushNewCount }} empleado(s) nuevos</span>
                                — los que aún no están mapeados ni en el dispositivo.
                            </p>
                            <p class="text-xs text-gray-400">
                                Total activos en Factorial: {{ $pushEmployeeCount }}.
                                Ya mapeados: {{ $pushEmployeeCount - $pushNewCount }}.
                            </p>
                            <p class="text-xs text-gray-400">El PIN asignado será el ID de Factorial de cada empleado.</p>
                            @if($pushNewCount === 0)
                                <p class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                                    Todos los empleados ya están mapeados o registrados en el dispositivo.
                                </p>
                            @endif
                        </div>
                    @else
                        <div class="space-y-2">
                            <p class="text-sm text-gray-700">
                                Se enviarán <span class="font-semibold text-gray-900">{{ $pushSintelcCount }} empleado(s) mapeados</span>
                                en Sintelc con su PIN real del dispositivo.
                            </p>
                            <p class="text-xs text-gray-400">
                                Usa los PINs que Sintelc ya tiene registrados para cada empleado.
                                Ideal para restaurar o sincronizar dispositivos configurados manualmente.
                            </p>
                            @if($pushSintelcCount === 0)
                                <p class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                                    No hay empleados mapeados en Sintelc para este proveedor.
                                </p>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="closeImportModal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    {{ $pushSuccessMsg ? 'Cerrar' : 'Cancelar' }}
                </button>
                @if(!$pushSuccessMsg)
                @php $canPush = $importMode === 'factorial' ? $pushNewCount > 0 : $pushSintelcCount > 0; @endphp
                <button wire:click="confirmPush" wire:loading.attr="disabled"
                    @disabled(!$canPush)
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white rounded-md transition-colors disabled:opacity-50 disabled:cursor-not-allowed
                        {{ $importMode === 'factorial' ? 'bg-sky-600 hover:bg-sky-700' : 'bg-indigo-600 hover:bg-indigo-700' }}">
                    <span wire:loading.remove wire:target="confirmPush">Enviar empleados</span>
                    <span wire:loading wire:target="confirmPush">Encolando…</span>
                </button>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- ── Modal: Clonar biométricos ──────────────────────────────────── --}}
    @if($showCloneModal)
    @php $cloneTarget = $cloneTargetId ? \App\Models\BiometricSource::find($cloneTargetId) : null; @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" wire:click.self="closeCloneModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Clonar biométricos</h3>
                    @if($cloneTarget)
                        <p class="text-xs text-gray-400 font-mono mt-0.5">Destino: {{ $cloneTarget->name }} · {{ $cloneTarget->serial_number }}</p>
                    @endif
                </div>
                <button wire:click="closeCloneModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-5 space-y-4">
                @if($cloneSuccessMsg)
                    <div class="rounded-lg bg-yellow-50 border border-yellow-200 px-5 py-4 flex items-start gap-3">
                        <svg class="w-5 h-5 text-yellow-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        <p class="text-sm text-yellow-800">{{ $cloneSuccessMsg }}</p>
                    </div>
                @else
                    <p class="text-sm text-gray-600">
                        Selecciona el dispositivo <span class="font-medium">origen</span> del que se copiarán las plantillas biométricas.
                        El proceso es automático: el equipo origen enviará sus plantillas al servidor y las reenviará al destino.
                    </p>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Copiar desde</label>
                        @php
                            $siblingDevices = $cloneTarget
                                ? \App\Models\BiometricSource::where('client_id', $cloneTarget->client_id)
                                    ->where('id', '!=', $cloneTarget->id)
                                    ->where('status', 'active')
                                    ->orderBy('name')
                                    ->get()
                                : collect();
                        @endphp
                        @if($siblingDevices->isEmpty())
                            <p class="text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                                No hay otros dispositivos activos en la misma empresa.
                            </p>
                        @else
                            <select wire:model="cloneSourceId"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-yellow-500 focus:ring-yellow-500">
                                <option value="">— Selecciona dispositivo origen —</option>
                                @foreach($siblingDevices as $sibling)
                                    <option value="{{ $sibling->id }}">
                                        {{ $sibling->name }} ({{ $sibling->serial_number }})
                                        @if($sibling->last_ping_at?->gt(now()->subMinutes(15))) · En línea @endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-400">Se recomienda elegir un dispositivo en línea para que la respuesta sea inmediata.</p>
                        @endif
                    </div>
                @endif
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="closeCloneModal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    {{ $cloneSuccessMsg ? 'Cerrar' : 'Cancelar' }}
                </button>
                @if(!$cloneSuccessMsg && !$siblingDevices->isEmpty())
                <button wire:click="startClone" wire:loading.attr="disabled" @disabled(!$cloneSourceId)
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-yellow-500 rounded-md hover:bg-yellow-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                    <span wire:loading.remove wire:target="startClone">Iniciar clonación</span>
                    <span wire:loading wire:target="startClone">Enviando…</span>
                </button>
                @endif
            </div>
        </div>
    </div>
    @endif

</div>
