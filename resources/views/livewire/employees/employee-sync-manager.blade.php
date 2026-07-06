<?php

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialEmployee;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination, WithFileUploads;

    public ?int    $client_id  = null;
    public bool    $clientLocked = false; // true cuando el usuario es cliente (no puede cambiar empresa)
    public string  $search    = '';
    public string  $tab         = 'biometric'; // 'biometric' | 'factorial' | 'mapping' | 'unresolved'
    public string  $scoreFilter  = 'all';       // 'all' | 'perfect' | 'good' | 'low'
    public string  $statusFilter = 'all';       // 'all' | 'mapped' | 'unmapped' (biometric/factorial tabs)
    public array   $selected    = [];          // PINs seleccionados para mapear
    public array   $assignments = [];          // PIN => factorial_employee_id (pendiente de guardar)
    public ?string $mapMessage  = null;        // Resultado del último mapeo

    // Modal CSV (a nivel de proveedor)
    public bool    $showCsvModal = false;
    public $csvFile              = null;
    public string  $importError  = '';
    public ?array  $csvResult    = null;

    public function syncFromDevices(): void
    {
        if (!$this->client_id) return;

        $provider = BiometricProvider::where('client_id', $this->client_id)->first();
        if (!$provider) return;

        $sources = BiometricSource::where('client_id', $this->client_id)
            ->where('status', 'active')
            ->get();

        foreach ($sources as $source) {
            $seq = \App\Models\DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') + 1;
            \App\Models\DeviceCommand::create([
                'biometric_source_id' => $source->id,
                'command_seq'         => $seq,
                'command_type'        => 'query_users',
                'payload'             => 'DATA QUERY USERINFO',
                'status'              => 'pending',
            ]);
        }

        $this->dispatch('notify', message: 'Solicitud enviada a ' . $sources->count() . ' dispositivo(s). La lista se actualizará en unos segundos.');
    }

    public function mount(): void
    {
        $user = auth()->user();
        if ($user->isClient()) {
            abort_if(!$user->client_id, 403);
            $this->client_id    = $user->client_id;
            $this->clientLocked = true;
            $this->tab          = 'mapping';
        }
    }

    public function updatedSearch(): void      { $this->resetPage(); }
    public function updatedClientId(): void    { $this->resetPage(); $this->selected = []; $this->assignments = []; $this->statusFilter = 'all'; }
    public function updatedTab(): void         { $this->resetPage(); $this->selected = []; $this->statusFilter = 'all'; }
    public function updatedScoreFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function setSelectAll(array $pins, bool $checked): void
    {
        if ($checked) {
            $this->selected = array_values(array_unique(array_merge($this->selected, $pins)));
        } else {
            $this->selected = array_values(array_diff($this->selected, $pins));
        }
    }

    public function updateAssignment(string $pin, mixed $employeeId): void
    {
        $this->assignments[$pin] = ($employeeId !== '' && $employeeId !== null) ? (int) $employeeId : null;
    }

    public function mapSelected(): void
    {
        if (!$this->client_id || empty($this->selected)) return;

        $provider = BiometricProvider::where('client_id', $this->client_id)->first();
        if (!$provider) return;

        // Construir mapa pin => employeeId filtrando los que no tienen asignación
        $toMap = [];
        foreach ($this->selected as $pin) {
            $employeeId = $this->assignments[$pin] ?? null;
            if ($employeeId) {
                $toMap[(string) $pin] = (int) $employeeId;
            }
        }

        if (empty($toMap)) {
            $this->selected = [];
            return;
        }

        $now = now()->toDateTimeString();

        // 1. Guardar BiometricUserSync — SELECT explícito para evitar race condition
        foreach ($toMap as $pin => $employeeId) {
            try {
                $existing = BiometricUserSync::where('biometric_provider_id', $provider->id)
                    ->where('factorial_employee_id', $employeeId)
                    ->first();

                if ($existing) {
                    // Si ya está mapeado al mismo PIN, no hacer nada
                    if ((string) $existing->external_employee_code === (string) $pin) {
                        continue;
                    }
                    // Si está mapeado a otro PIN, actualizar (el admin decidió cambiar)
                    $existing->update([
                        'external_employee_code' => $pin,
                        'client_id'              => $this->client_id,
                        'sync_status'            => 'pending',
                        'last_attempt_at'        => $now,
                    ]);
                } else {
                    BiometricUserSync::create([
                        'biometric_provider_id'  => $provider->id,
                        'factorial_employee_id'  => $employeeId,
                        'external_employee_code' => $pin,
                        'client_id'              => $this->client_id,
                        'sync_status'            => 'pending',
                        'last_attempt_at'        => $now,
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('mapSelected: error al guardar sync', [
                    'pin'        => $pin,
                    'employeeId' => $employeeId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // 2. Actualizar attendance_logs — 1 query por pin (todos simples y rápidos)
        foreach ($toMap as $pin => $employeeId) {
            AttendanceLog::where('client_id', $this->client_id)
                ->where('employee_code', $pin)
                ->whereNull('factorial_employee_id')
                ->update(['factorial_employee_id' => $employeeId, 'sync_status' => 'resolved']);
        }

        // 3. Despachar jobs de sync — obtener IDs en 1 query y despachar en lote
        $logIds = AttendanceLog::where('client_id', $this->client_id)
            ->whereIn('employee_code', array_keys($toMap))
            ->where('sync_status', 'resolved')
            ->whereNotNull('factorial_employee_id')
            ->pluck('id');

        $delay = 0;
        foreach ($logIds as $logId) {
            SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
            $delay += 2;
        }

        $saved   = count($toMap);
        $skipped = count($this->selected) - $saved;

        $this->mapMessage = "✓ {$saved} mapeado(s)" . ($skipped > 0 ? " · ⚠ {$skipped} omitido(s) sin asignación" : "");
        $this->selected = [];
    }

    public function with(): array
    {
        $clients = Client::orderBy('name')->get();

        // ── Tab: Mapping ────────────────────────────────────────────
        if ($this->tab === 'mapping') {
            $unmappedUsers = collect();
            $employees     = collect();

            if ($this->client_id) {
                $employees = FactorialEmployee::where('client_id', $this->client_id)->orderBy('full_name')->get();

                $mappedSyncs = BiometricUserSync::where('client_id', $this->client_id)
                    ->whereNotNull('factorial_employee_id')
                    ->get(['external_employee_code', 'factorial_employee_id']);

                $mappedPins        = $mappedSyncs->pluck('external_employee_code')->map(fn($c) => (string) $c)->flip();
                $mappedEmployeeIds = $mappedSyncs->pluck('factorial_employee_id')->flip();

                // Empleados disponibles en el selector = los que no están mapeados aún
                $employees = $employees->filter(fn($e) => !$mappedEmployeeIds->has($e->id))->values();

                // Normalizador
                $normalize = fn($s) => preg_replace('/\s+/', ' ', trim(str_replace(
                    ['„','ê','û','î','â','ô','Ñ','ñ','Á','á','É','é','Í','í','Ó','ó','Ú','ú','Ü','ü'],
                    ['n','e','u','i','a','o','n','n','a','a','e','e','i','i','o','o','u','u','u','u'],
                    mb_strtolower($s)
                )));

                $normalizedEmployees = $employees->map(fn($e) => [
                    'id'   => $e->id,
                    'name' => $e->full_name,
                    'norm' => $normalize($e->full_name),
                ])->all();

                BiometricSource::where('client_id', $this->client_id)->get()
                    ->each(function ($source) use (&$unmappedUsers, $mappedPins, $normalize, $normalizedEmployees) {
                        foreach ($source->device_users ?? [] as $u) {
                            $pin  = (string) ($u['pin'] ?? '');
                            $name = $u['name'] ?? '';
                            if ($pin === '' || $mappedPins->has($pin)) continue;
                            if ($this->search && stripos($pin, $this->search) === false
                                && stripos($name, $this->search) === false) continue;

                            // Mejor match por similitud
                            $bestScore = 0;
                            $bestId    = null;
                            $bestName  = null;
                            $normName  = $normalize($name);

                            foreach ($normalizedEmployees as $emp) {
                                similar_text($normName, $emp['norm'], $pct);
                                if ($pct > $bestScore) {
                                    $bestScore = $pct;
                                    $bestId    = $emp['id'];
                                    $bestName  = $emp['name'];
                                }
                            }

                            $unmappedUsers[$pin] = [
                                'pin'            => $pin,
                                'name'           => $name,
                                'source'         => $source->serial_number,
                                'source_id'      => $source->id,
                                'provider_id'    => $source->biometric_provider_id,
                                'suggested_id'   => $bestId,
                                'suggested_name' => $bestName,
                                'score'          => round($bestScore, 1),
                            ];
                        }
                    });

                // Excluir PINs cuyo empleado sugerido (100%) ya está mapeado a otro PIN
                // Reutilizamos $mappedEmployeeIds obtenido arriba
                $unmappedUsers = $unmappedUsers->filter(function ($u) use ($mappedEmployeeIds) {
                    if ($u['score'] >= 100 && $u['suggested_id'] && $mappedEmployeeIds->has($u['suggested_id'])) {
                        return false;
                    }
                    return true;
                });

                // Guardar total antes de aplicar filtro de score
                $totalUnmapped = $unmappedUsers->count();

                // Filtrar por score
                $unmappedUsers = match ($this->scoreFilter) {
                    'perfect' => $unmappedUsers->filter(fn($u) => $u['score'] >= 100),
                    'good'    => $unmappedUsers->filter(fn($u) => $u['score'] >= 70 && $u['score'] < 100),
                    'low'     => $unmappedUsers->filter(fn($u) => $u['score'] < 70),
                    default   => $unmappedUsers,
                };

                // Ordenar alfabéticamente por nombre del dispositivo
                $unmappedUsers = $unmappedUsers->sortBy(fn($u) => mb_strtolower($u['name']))->values();

                // Inicializar assignments desde sugerencias (solo si no están ya definidos)
                foreach ($unmappedUsers as $u) {
                    if (!array_key_exists($u['pin'], $this->assignments) && $u['suggested_id']) {
                        $this->assignments[$u['pin']] = $u['suggested_id'];
                    }
                }
            }

            $allSelected = $unmappedUsers->isNotEmpty()
                && $unmappedUsers->every(fn($u) => in_array($u['pin'], $this->selected));

            return [
                'unmappedUsers'     => $unmappedUsers,
                'totalUnmapped'     => $totalUnmapped ?? 0,
                'allSelected'       => $allSelected,
                'biometricUsers'      => collect(),
                'biometricTotalCount' => 0,
                'biometricMappedCount'=> 0,
                'biometricSources'    => collect(),
                'employees'           => $employees,
                'unresolved'          => collect(),
                'unresolvedCount'     => 0,
                'clients'             => $clients,
                'vendorName'          => 'Biométrico',
                'mappedEmployeeIds'   => collect(),
                'biometricIds'        => collect(),
                'biometricSyncs'      => collect(),
                'sourcesPerProvider'  => collect(),
                'totalSources'        => 0,
            ];
        }

        // ── Tab: Empleados en Biométrico ────────────────────────────
        if ($this->tab === 'biometric') {
            $biometricUsers  = collect();
            $biometricSources = collect();

            if ($this->client_id) {
                $biometricSources = BiometricSource::where('client_id', $this->client_id)
                    ->orderBy('name')
                    ->get();

                $mappings = BiometricUserSync::where('client_id', $this->client_id)
                    ->whereNotNull('factorial_employee_id')
                    ->pluck('factorial_employee_id', 'external_employee_code')
                    ->map(fn($id) => (int) $id);

                $employees = FactorialEmployee::where('client_id', $this->client_id)
                    ->get(['id', 'factorial_id', 'full_name'])
                    ->keyBy('id');

                $biometricSources->each(function ($source) use (&$biometricUsers, $mappings, $employees) {
                    foreach ($source->device_users ?? [] as $u) {
                        $pin = (string) $u['pin'];
                        if ($this->search && stripos($pin, $this->search) === false && stripos($u['name'] ?? '', $this->search) === false) {
                            continue;
                        }
                        $empId  = $mappings[$pin] ?? null;
                        $emp    = $empId ? ($employees[$empId] ?? null) : null;
                        $biometricUsers[$pin] = [
                            'pin'          => $pin,
                            'name'         => $u['name'] ?? null,
                            'source'       => $source->name,
                            'mapped'       => $empId !== null,
                            'factorial_id' => $emp?->factorial_id,
                        ];
                    }
                });
            }

            // Totales fijos antes de aplicar filtro de estado
            $biometricTotalCount  = $biometricUsers->count();
            $biometricMappedCount = $biometricUsers->filter(fn($u) => $u['mapped'])->count();

            // Filtro por estado
            if ($this->statusFilter === 'mapped') {
                $biometricUsers = $biometricUsers->filter(fn($u) => $u['mapped']);
            } elseif ($this->statusFilter === 'unmapped') {
                $biometricUsers = $biometricUsers->filter(fn($u) => !$u['mapped']);
            }

            // Paginación manual sobre la colección in-memory
            $perPage   = 20;
            $page      = $this->getPage();
            $allUsers  = $biometricUsers->values();
            $biometricUsers = new \Illuminate\Pagination\LengthAwarePaginator(
                $allUsers->slice(($page - 1) * $perPage, $perPage)->values(),
                $allUsers->count(),
                $perPage,
                $page,
                ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
            );

            return [
                'unmappedUsers'    => collect(),
                'allSelected'      => false,
                'totalUnmapped'    => 0,
                'biometricUsers'      => $biometricUsers,
                'biometricTotalCount' => $biometricTotalCount ?? 0,
                'biometricMappedCount'=> $biometricMappedCount ?? 0,
                'biometricSources'    => $biometricSources,
                'employees'           => collect(),
                'unresolved'          => collect(),
                'unresolvedCount'     => 0,
                'clients'             => $clients,
                'vendorName'          => 'Biométrico',
                'mappedEmployeeIds'   => collect(),
                'biometricIds'        => collect(),
            ];
        }

        // ── Tab: Empleados Factorial ────────────────────────────────
        if (!$this->client_id) {
            return ['unmappedUsers' => collect(), 'allSelected' => false, 'totalUnmapped' => 0, 'biometricSources' => collect(), 'biometricTotalCount' => 0, 'biometricMappedCount' => 0, 'employees' => collect(), 'unresolved' => collect(), 'unresolvedCount' => 0, 'clients' => $clients, 'vendorName' => 'Biométrico', 'mappedEmployeeIds' => collect(), 'biometricIds' => collect(), 'biometricSyncs' => collect(), 'sourcesPerProvider' => collect(), 'totalSources' => 0];
        }

        $query = FactorialEmployee::query()
            ->where('client_id', $this->client_id)
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('full_name', 'like', "%{$this->search}%")
                   ->orWhere('email', 'like', "%{$this->search}%");
            }))
            ->orderBy('full_name');

        $provider = BiometricProvider::where('client_id', $this->client_id)->first();
        $vendorName = $provider?->vendor ?? 'Biométrico';

        $biometricSyncs = BiometricUserSync::where('client_id', $this->client_id)
            ->whereNotNull('factorial_employee_id')
            ->get(['factorial_employee_id', 'external_employee_code', 'biometric_provider_id'])
            ->keyBy('factorial_employee_id');

        $biometricIds      = $biometricSyncs->pluck('external_employee_code', 'factorial_employee_id');
        $mappedEmployeeIds = $biometricIds->flip();

        $allSources         = BiometricSource::where('client_id', $this->client_id)->get(['biometric_provider_id', 'name']);
        $totalSources       = $allSources->count();
        $sourcesPerProvider = $allSources->groupBy('biometric_provider_id')->map(fn($s) => $s->pluck('name'));

        if ($this->statusFilter === 'mapped') {
            $query->whereIn('id', $biometricIds->keys()->all());
        } elseif ($this->statusFilter === 'unmapped') {
            $query->whereNotIn('id', $biometricIds->keys()->all());
        }

        return [
            'unmappedUsers'     => collect(),
            'allSelected'       => false,
            'totalUnmapped'     => 0,
            'biometricUsers'    => collect(),
            'biometricSources'  => collect(),
            'employees'         => $query->paginate(20),
            'unresolved'        => collect(),
            'unresolvedCount'   => 0,
            'clients'           => $clients,
            'vendorName'        => $vendorName,
            'mappedEmployeeIds' => $mappedEmployeeIds,
            'biometricIds'      => $biometricIds,
            'biometricSyncs'    => $biometricSyncs,
            'sourcesPerProvider'=> $sourcesPerProvider,
            'totalSources'      => $totalSources,
        ];
    }

    public function openCsvModal(): void
    {
        $this->csvFile     = null;
        $this->importError = '';
        $this->csvResult   = null;
        $this->showCsvModal = true;
    }

    public function closeCsvModal(): void
    {
        $this->showCsvModal = false;
        $this->csvFile      = null;
        $this->importError  = '';
        $this->csvResult    = null;
    }

    public function uploadCsv(): void
    {
        $this->importError = '';
        $this->csvResult   = null;

        if (!$this->client_id) { $this->importError = 'Selecciona una empresa primero.'; return; }

        $provider = BiometricProvider::where('client_id', $this->client_id)->first();
        if (!$provider) { $this->importError = 'No hay proveedor biométrico para esta empresa.'; return; }

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
                // Strip UTF-8 BOM from first field if present
                $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
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
                'card' => '',
                'role' => '0',
            ];
        }
        fclose($handle);

        if (empty($rows)) {
            $this->importError = 'Sin registros válidos. Columnas requeridas: pin, nombre.';
            return;
        }

        // Actualizar device_users en TODOS los dispositivos del proveedor (merge)
        $sources = BiometricSource::where('biometric_provider_id', $provider->id)->get();
        foreach ($sources as $source) {
            $existing = collect($source->device_users ?? []);
            $merged   = $existing->concat($rows)->unique('pin')->values()->toArray();
            $source->update(['device_users' => $merged, 'device_users_fetched_at' => now()]);
        }

        $this->csvResult = [
            'total'   => count($rows),
            'devices' => $sources->count(),
        ];
        $this->csvFile = null;
    }

    public function saveBiometricId(int $employeeId, string $pin): void
    {
        $pin = trim($pin);
        if ($pin === '') return;

        $employee = FactorialEmployee::findOrFail($employeeId);
        $provider = BiometricProvider::where('client_id', $employee->client_id)->first();

        if (!$provider) return;

        BiometricUserSync::updateOrCreate(
            [
                'biometric_provider_id' => $provider->id,
                'factorial_employee_id' => $employee->id,
            ],
            [
                'client_id'              => $employee->client_id,
                'external_employee_code' => $pin,
                'sync_status'            => 'pending',
                'last_attempt_at'        => now(),
            ]
        );

        // Resolver attendance logs pendientes con este PIN
        \App\Models\AttendanceLog::where('client_id', $employee->client_id)
            ->where('employee_code', $pin)
            ->whereNull('factorial_employee_id')
            ->update([
                'factorial_employee_id' => $employee->id,
                'sync_status'           => 'resolved',
            ]);
    }

}; ?>

<div>
    {{-- ── Tarjeta filtros ──────────────────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg px-6 py-4 mb-4">

        {{-- Fila 1: empresa + búsqueda --}}
        <div class="flex gap-3">
            @if($clientLocked)
            <div class="shrink-0 flex items-center">
                <span class="text-sm font-medium text-gray-700">{{ $clients->firstWhere('id', $client_id)?->name ?? '' }}</span>
            </div>
            @else
            <div class="{{ $client_id ? 'w-56' : 'w-full sm:w-72' }} shrink-0">
                <select wire:model.live="client_id" class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">— Selecciona una empresa —</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            @if($client_id)
            <div class="flex-1">
                <input
                    wire:model.live.debounce.300ms="search"
                    type="text"
                    placeholder="Buscar por nombre, email o PIN..."
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
            </div>
            @endif
        </div>

        @if($client_id)
        {{-- Tabs --}}
        <div class="border-t border-gray-100 mt-4 -mb-px">
            <nav class="flex gap-6">
                <button wire:click="$set('tab', 'biometric')"
                    class="py-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $tab === 'biometric' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Biométrico
                </button>
                <button wire:click="$set('tab', 'factorial')"
                    class="py-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $tab === 'factorial' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Factorial
                </button>
                <button wire:click="$set('tab', 'mapping')"
                    class="py-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $tab === 'mapping' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                    Mapping
                </button>
            </nav>
        </div>

        {{-- Pills + count / acciones --}}
        <div class="border-t border-gray-100 pt-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-400 mr-1">Filtrar:</span>
                @if($tab === 'mapping')
                    @foreach([
                        ['all',     'Todos',  'bg-gray-100 text-gray-700 hover:bg-gray-200'],
                        ['perfect', '100%',   'bg-emerald-200 text-emerald-800 hover:bg-emerald-300'],
                        ['good',    '70–99%', 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200'],
                        ['low',     '< 70%',  'bg-red-100 text-red-600 hover:bg-red-200'],
                    ] as [$val, $label, $cls])
                    <button wire:click="$set('scoreFilter', '{{ $val }}')"
                        class="text-xs font-medium px-3 py-1 rounded-full transition {{ $scoreFilter === $val ? 'ring-2 ring-offset-1 ring-gray-400 ' : '' }}{{ $cls }}">
                        {{ $label }}
                    </button>
                    @endforeach
                @else
                    @php
                        $pillOptions = $tab === 'factorial'
                            ? ['all' => 'Todos', 'mapped' => 'Con PIN', 'unmapped' => 'Sin PIN']
                            : ['all' => 'Todos', 'mapped' => 'Mapeados', 'unmapped' => 'Sin asignar'];
                    @endphp
                    @foreach($pillOptions as $val => $label)
                    <button wire:click="$set('statusFilter', '{{ $val }}')"
                        class="px-3 py-1 rounded-full text-xs font-medium transition-colors
                            {{ $statusFilter === $val ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        {{ $label }}
                    </button>
                    @endforeach
                @endif
            </div>
            <div class="flex items-center gap-3">
                @if($tab === 'mapping')
                    @if($mapMessage)
                    <span class="text-xs {{ str_contains($mapMessage, '⚠') ? 'text-amber-600' : 'text-emerald-600' }} font-medium">{{ $mapMessage }}</span>
                    @endif
                    {{-- Botón CSV --}}
                    <button wire:click="openCsvModal" title="Importar usuarios desde CSV"
                        class="text-emerald-500 hover:text-emerald-700">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 9m0 8V9m0 0L9 7"/>
                        </svg>
                    </button>
                    @if(count($selected) > 0)
                    <button wire:click="mapSelected" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-1.5 rounded transition disabled:opacity-50">
                        <span wire:loading.remove wire:target="mapSelected">✓ Mapear {{ count($selected) }} seleccionado(s)</span>
                        <span wire:loading wire:target="mapSelected">Guardando…</span>
                    </button>
                    @else
                    <span class="text-xs text-gray-400">
                        {{ $unmappedUsers->count() }}{{ $unmappedUsers->count() !== $totalUnmapped ? ' de ' . $totalUnmapped : '' }} pendiente(s)
                    </span>
                    @endif
                @elseif($tab === 'biometric')
                    <button wire:click="syncFromDevices" wire:loading.attr="disabled" title="Solicitar lista actualizada de usuarios a los dispositivos"
                        class="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-800 disabled:opacity-50 transition">
                        <svg wire:loading.remove wire:target="syncFromDevices" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        <svg wire:loading wire:target="syncFromDevices" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Sync
                    </button>
                    <span class="text-xs text-gray-400">{{ $biometricMappedCount }} de {{ $biometricTotalCount }} empleado(s) mapeados</span>
                @elseif($tab === 'factorial')
                    <span class="text-xs text-gray-400">
                        {{ $mappedEmployeeIds->count() }} de {{ $employees instanceof \Illuminate\Pagination\LengthAwarePaginator ? $employees->total() : $employees->count() }} empleado(s) mapeados
                    </span>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Sin empresa: prompt --}}
    @if(!$client_id)
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-16 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <p class="text-base font-medium text-gray-700">Selecciona una empresa para continuar</p>
            <p class="mt-1 text-sm text-gray-400">Elige una empresa en el selector de arriba para ver los empleados, mapping y registros pendientes.</p>
        </div>
    </div>
    @else

    {{-- ── Resultados ───────────────────────────────────────────────── --}}

    {{-- TAB: Empleados en Biométrico --}}
    @if($tab === 'biometric')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre en dispositivo</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mapping</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Id Factorial</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($biometricUsers as $user)
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-3 whitespace-nowrap font-mono text-sm font-semibold text-gray-900">
                        {{ $user['pin'] }}
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-700">
                        {{ $user['name'] ?? '—' }}
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-500">
                        {{ $user['source'] }}
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        <div class="flex items-center h-full">
                        <button type="button" disabled
                            class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 cursor-default
                                {{ $user['mapped'] ? 'bg-green-500' : 'bg-gray-200' }}">
                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200
                                {{ $user['mapped'] ? 'translate-x-4' : 'translate-x-0' }}"></span>
                        </button>
                        </div>
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap font-mono text-sm text-gray-500">
                        {{ $user['factorial_id'] ?? '—' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center text-sm text-gray-500">
                        No hay empleados registrados en el biométrico.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($biometricUsers instanceof \Illuminate\Pagination\LengthAwarePaginator && $biometricUsers->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $biometricUsers->links() }}
        </div>
        @endif
    </div>
    @endif

    {{-- TAB: Mapping --}}
    @if($tab === 'mapping')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        @if($totalUnmapped === 0)
            <div class="px-6 py-10 text-center">
                <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm text-green-600 font-medium">✓ Todos los usuarios del biométrico están mapeados.</p>
                <p class="mt-1 text-xs text-gray-400">Usa el ícono <span class="text-emerald-600">CSV</span> (arriba a la derecha) si hay usuarios nuevos para importar.</p>
            </div>
        @else
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 w-10">
                        <input type="checkbox"
                            @checked($allSelected)
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                            @change="$wire.setSelectAll({{ Js::from($unmappedUsers->pluck('pin')->all()) }}, $event.target.checked)">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-20">PIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nombre en dispositivo</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dispositivo</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-16">Match</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Asignar a</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @if($unmappedUsers->isEmpty())
                <tr>
                    <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-400">
                        Sin resultados para este filtro — prueba con otro pill.
                    </td>
                </tr>
                @endif
                @foreach($unmappedUsers as $row)
                @php
                    $score = $row['score'] ?? 0;
                    $scoreColor = $score >= 100 ? 'bg-emerald-100 text-emerald-700'
                                : ($score >= 70 ? 'bg-yellow-100 text-yellow-700'
                                : 'bg-red-100 text-red-600');
                    $isSelected   = in_array($row['pin'], $selected);
                    $hasAssign    = !empty($assignments[$row['pin']]);
                    $rowBg = $isSelected
                        ? ($hasAssign ? 'bg-indigo-50' : 'bg-amber-50')
                        : 'hover:bg-gray-50';
                @endphp
                <tr wire:key="maprow-{{ $row['pin'] }}" class="{{ $rowBg }}">
                    <td class="px-4 py-3">
                        <input type="checkbox"
                            wire:key="cb-{{ $row['pin'] }}"
                            value="{{ $row['pin'] }}"
                            @checked($isSelected)
                            wire:change="@if($isSelected) setSelectAll(['{{ $row['pin'] }}'], false) @else setSelectAll(['{{ $row['pin'] }}'], true) @endif"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                    </td>
                    <td class="px-4 py-3 font-mono font-semibold text-gray-900">{{ $row['pin'] }}</td>
                    <td class="px-4 py-3 text-gray-800">{{ $row['name'] ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-400 text-xs">{{ $row['source'] }}</td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $scoreColor }}">
                            {{ $score }}%
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <select
                            wire:change="updateAssignment('{{ $row['pin'] }}', $event.target.value)"
                            class="block w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 py-1">
                            <option value="">— Sin mapear —</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected($emp->id == ($assignments[$row['pin']] ?? null))>
                                    {{ $emp->full_name }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
    @endif

    {{-- TAB: Empleados Factorial --}}
    @if($tab === 'factorial')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre en Factorial</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mapping</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Id Biométrico</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($employees as $employee)
                @php
                    $isMapped    = isset($biometricIds[$employee->id]);
                    $sync        = $biometricSyncs[$employee->id] ?? null;
                    $sourceNames = $sync ? ($sourcesPerProvider[$sync->biometric_provider_id] ?? collect()) : collect();
                    $sourceCount = $sourceNames->count();
                    $deviceName  = $sourceCount === 0 ? '—'
                        : ($sourceCount === $totalSources ? 'Todos'
                        : ($sourceCount > 1 ? 'Varios'
                        : $sourceNames->first()));
                @endphp
                <tr class="hover:bg-gray-50" wire:key="emp-row-{{ $employee->id }}" x-data="{ pin: '' }">
                    <td class="px-5 py-3 whitespace-nowrap font-mono text-sm font-semibold text-gray-900">
                        {{ $employee->factorial_id }}
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $employee->full_name }}</div>
                        <div class="text-xs text-gray-500">{{ $employee->email }}</div>
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-500">
                        {{ $isMapped ? $deviceName : '—' }}
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        <div class="flex items-center h-full">
                        @if($isMapped)
                        <button type="button" disabled
                            class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 cursor-default bg-green-500">
                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 translate-x-4"></span>
                        </button>
                        @else
                        <button type="button"
                            @click="pin = pin === '{{ $employee->factorial_id }}' ? '' : '{{ $employee->factorial_id }}'"
                            class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 cursor-pointer"
                            :class="pin === '{{ $employee->factorial_id }}' ? 'bg-gray-400' : 'bg-gray-200'">
                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200"
                                :class="pin === '{{ $employee->factorial_id }}' ? 'translate-x-4' : 'translate-x-0'"></span>
                        </button>
                        @endif
                        </div>
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap font-mono text-sm text-gray-700">
                        @if($isMapped)
                            {{ $biometricIds[$employee->id] }}
                        @else
                            <div class="flex items-center gap-1">
                                <input
                                    x-model="pin"
                                    type="text"
                                    placeholder="PIN"
                                    class="w-24 rounded border-gray-300 text-xs font-mono px-2 py-1 focus:border-indigo-500 focus:ring-indigo-500"
                                    @keydown.enter="if(pin.trim()) $wire.saveBiometricId({{ $employee->id }}, pin)"
                                />
                                <button
                                    x-show="pin.trim().length > 0"
                                    @click="$wire.saveBiometricId({{ $employee->id }}, pin)"
                                    class="text-xs text-white bg-indigo-600 hover:bg-indigo-700 px-2 py-1 rounded transition">
                                    ✓
                                </button>
                            </div>
                        @endif
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap">
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
                    <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">
                        Sin empleados.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($employees instanceof \Illuminate\Pagination\LengthAwarePaginator && $employees->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $employees->links() }}
        </div>
        @endif
    </div>
    @endif


    @endif {{-- @else ($client_id) --}}

    {{-- ── Modal: Importar usuarios desde CSV ──────────────────────── --}}
    @if($showCsvModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" wire:click.self="closeCsvModal">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Importar usuarios desde CSV</h3>
                    <p class="text-xs text-gray-400 mt-0.5">Los usuarios se agregarán a todos los dispositivos del proveedor.</p>
                </div>
                <button wire:click="closeCsvModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-5 space-y-4">
                @if($csvResult)
                    <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-5 py-3 space-y-1">
                        <p class="text-sm font-semibold text-emerald-800">Archivo importado correctamente</p>
                        <p class="text-sm text-emerald-700">{{ $csvResult['total'] }} usuarios actualizados en {{ $csvResult['devices'] }} dispositivo(s).</p>
                        <p class="text-xs text-emerald-600 mt-1">Ve al tab <strong>Mapping</strong> para asignarlos a empleados de Factorial.</p>
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
                <button wire:click="closeCsvModal"
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
        </div>
    </div>
    @endif
</div>
