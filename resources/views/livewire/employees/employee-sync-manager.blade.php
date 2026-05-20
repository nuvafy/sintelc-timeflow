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
use Livewire\WithFileUploads;

new class extends Component {
    use WithPagination, WithFileUploads;

    public ?int    $client_id = null;
    public string  $search    = '';
    public string  $tab       = 'biometric'; // 'biometric' | 'factorial' | 'mapping' | 'unresolved'

    // ── CSV Import ────────────────────────────────────────────────
    public $csvFile         = null;
    public array $preview   = [];   // rows: [pin, name, employee_id, employee_name, confidence]
    public bool  $importing = false;
    public string $importError = '';

    public function updatedSearch(): void   { $this->resetPage(); }
    public function updatedClientId(): void { $this->resetPage(); $this->preview = []; }
    public function updatedTab(): void      { $this->resetPage(); }

    public function with(): array
    {
        $clients = Client::orderBy('name')->get();

        // ── Tab: Mapping ────────────────────────────────────────────
        if ($this->tab === 'mapping') {
            $employees = $this->client_id
                ? FactorialEmployee::where('client_id', $this->client_id)->orderBy('full_name')->get()
                : collect();

            return [
                'biometricUsers'    => collect(),
                'employees'         => $employees,
                'unresolved'        => collect(),
                'unresolvedCount'   => 0,
                'clients'           => $clients,
                'vendorName'        => 'Biométrico',
                'mappedEmployeeIds' => collect(),
                'biometricIds'      => collect(),
            ];
        }

        // ── Tab: Empleados en Biométrico ────────────────────────────
        if ($this->tab === 'biometric') {
            $biometricUsers = collect();

            if ($this->client_id) {
                $mappings = BiometricUserSync::where('client_id', $this->client_id)
                    ->whereNotNull('factorial_employee_id')
                    ->pluck('factorial_employee_id', 'external_employee_code')
                    ->map(fn($id) => (int) $id);

                $employees = FactorialEmployee::where('client_id', $this->client_id)
                    ->pluck('full_name', 'id');

                BiometricSource::where('client_id', $this->client_id)->get()
                    ->each(function ($source) use (&$biometricUsers, $mappings, $employees) {
                        foreach ($source->device_users ?? [] as $u) {
                            $pin = (string) $u['pin'];
                            if ($this->search && stripos($pin, $this->search) === false && stripos($u['name'] ?? '', $this->search) === false) {
                                return;
                            }
                            $empId   = $mappings[$pin] ?? null;
                            $biometricUsers[$pin] = [
                                'pin'           => $pin,
                                'name'          => $u['name'] ?? null,
                                'source'        => $source->name,
                                'mapped'        => $empId !== null,
                                'employee_name' => $empId ? ($employees[$empId] ?? '—') : null,
                            ];
                        }
                    });
            }

            $unresolvedCount = AttendanceLog::where('client_id', $this->client_id)
                ->whereNull('factorial_employee_id')
                ->distinct('employee_code')
                ->count('employee_code');

            return [
                'biometricUsers'  => $biometricUsers->values(),
                'employees'       => collect(),
                'unresolved'      => collect(),
                'unresolvedCount' => $unresolvedCount,
                'clients'         => $clients,
                'vendorName'      => 'Biométrico',
                'mappedEmployeeIds' => collect(),
                'biometricIds'    => collect(),
            ];
        }

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
                'biometricUsers'  => collect(),
                'employees'       => collect(),
                'unresolved'      => $unresolved,
                'unresolvedCount' => $unresolved->total(),
                'clients'         => $clients,
                'vendorName'      => 'Biométrico',
                'mappedEmployeeIds' => collect(),
                'biometricIds'    => collect(),
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
            'biometricUsers'    => collect(),
            'employees'         => $query->paginate(20),
            'unresolved'        => collect(),
            'unresolvedCount'   => $unresolvedCount,
            'clients'           => $clients,
            'vendorName'        => $vendorName,
            'mappedEmployeeIds' => $mappedEmployeeIds,
            'biometricIds'      => $biometricIds,
        ];
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

    // ── CSV Import ────────────────────────────────────────────────

    public function uploadCsv(): void
    {
        $this->importError = '';

        if (!$this->client_id) {
            $this->importError = 'Selecciona una empresa primero.';
            return;
        }

        $this->validate(['csvFile' => 'required|file|mimes:csv,txt|max:2048']);

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
            $row = array_combine($header, $line);
            $pin  = trim($row['pin'] ?? '');
            $name = trim($row['nombre'] ?? $row['name'] ?? '');
            if ($pin === '') continue;
            $rows[] = ['pin' => $pin, 'name' => $name];
        }
        fclose($handle);

        if (empty($rows)) {
            $this->importError = 'El archivo no contiene registros válidos. Columnas requeridas: pin, nombre.';
            return;
        }

        // Guardar en device_users de todos los BiometricSources del cliente
        $deviceUsers = array_map(fn($r) => ['pin' => $r['pin'], 'name' => $r['name'], 'card' => '', 'role' => '0'], $rows);
        BiometricSource::where('client_id', $this->client_id)->update(['device_users' => json_encode($deviceUsers)]);

        // Auto-match contra empleados de Factorial
        $employees = FactorialEmployee::where('client_id', $this->client_id)->get();
        $existingMappings = BiometricUserSync::where('client_id', $this->client_id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        $this->preview = array_map(function ($row) use ($employees, $existingMappings) {
            // Si ya tiene mapeo, marcarlo
            if (isset($existingMappings[$row['pin']])) {
                $emp = $employees->find($existingMappings[$row['pin']]);
                return [
                    'pin'           => $row['pin'],
                    'name'          => $row['name'],
                    'employee_id'   => $emp?->id,
                    'employee_name' => $emp?->full_name ?? '—',
                    'confidence'    => 100,
                    'already_mapped'=> true,
                ];
            }

            // Auto-match por nombre
            $best      = null;
            $bestScore = 0;
            $bestName  = '';

            foreach ($employees as $emp) {
                similar_text(
                    mb_strtolower($row['name']),
                    mb_strtolower($emp->full_name),
                    $pct
                );
                if ($pct > $bestScore) {
                    $bestScore = $pct;
                    $best      = $emp->id;
                    $bestName  = $emp->full_name;
                }
            }

            return [
                'pin'            => $row['pin'],
                'name'           => $row['name'],
                'employee_id'    => $bestScore >= 50 ? $best : null,
                'employee_name'  => $bestScore >= 50 ? $bestName : '',
                'confidence'     => round($bestScore),
                'already_mapped' => false,
            ];
        }, $rows);

        $this->tab = 'mapping';
        $this->csvFile = null;
    }

    public function updateMappingRow(int $index, ?int $employeeId): void
    {
        if (!isset($this->preview[$index])) return;

        $employees = FactorialEmployee::where('client_id', $this->client_id)->get();
        $emp = $employees->find($employeeId);

        $this->preview[$index]['employee_id']   = $employeeId;
        $this->preview[$index]['employee_name']  = $emp?->full_name ?? '';
        $this->preview[$index]['confidence']     = $employeeId ? 100 : 0;
    }

    public function confirmMappings(): void
    {
        if (!$this->client_id || empty($this->preview)) return;

        $provider = BiometricProvider::where('client_id', $this->client_id)->first();
        if (!$provider) return;

        $now = now();

        foreach ($this->preview as $row) {
            if (!$row['employee_id'] || $row['already_mapped']) continue;

            BiometricUserSync::updateOrCreate(
                [
                    'biometric_provider_id' => $provider->id,
                    'factorial_employee_id' => $row['employee_id'],
                ],
                [
                    'client_id'              => $this->client_id,
                    'external_employee_code' => $row['pin'],
                    'sync_status'            => 'pending',
                    'last_attempt_at'        => $now,
                ]
            );

            // Resolver attendance logs pendientes
            AttendanceLog::where('client_id', $this->client_id)
                ->where('employee_code', $row['pin'])
                ->whereNull('factorial_employee_id')
                ->update([
                    'factorial_employee_id' => $row['employee_id'],
                    'sync_status'           => 'resolved',
                ]);
        }

        $this->preview = [];
        $this->tab = 'biometric';
    }

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['pin', 'nombre']);
            fputcsv($out, ['1001', 'Juan Pérez']);
            fputcsv($out, ['1002', 'María López']);
            fclose($out);
        }, 'plantilla_biometrico.csv', ['Content-Type' => 'text/csv']);
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
                wire:click="$set('tab', 'biometric')"
                class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $tab === 'biometric' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Empleados en Biométrico
            </button>
            <button
                wire:click="$set('tab', 'factorial')"
                class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors {{ $tab === 'factorial' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Empleados en Factorial
            </button>
            <button
                wire:click="$set('tab', 'mapping')"
                class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 {{ $tab === 'mapping' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Mapping
                @if(!empty($preview))
                    <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 text-[10px] font-bold leading-none text-white bg-indigo-500 rounded-full">
                        {{ count($preview) }}
                    </span>
                @endif
            </button>
            <button
                wire:click="$set('tab', 'unresolved')"
                class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 {{ $tab === 'unresolved' ? 'border-amber-500 text-amber-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Pendientes de asignar
                @if($unresolvedCount > 0)
                    <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 text-[10px] font-bold leading-none text-white bg-amber-500 rounded-full">
                        {{ $unresolvedCount }}
                    </span>
                @endif
            </button>
        </nav>
    </div>

    {{-- TAB: Empleados en Biométrico --}}
    @if($tab === 'biometric')

    {{-- Upload CSV --}}
    @if($client_id)
    <div class="bg-white shadow rounded-lg p-4 mb-4">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 flex-1">
                <input wire:model="csvFile" type="file" accept=".csv,.txt"
                    class="block text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"/>
                <button wire:click="uploadCsv" wire:loading.attr="disabled"
                    class="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded hover:bg-indigo-700 transition disabled:opacity-50">
                    <span wire:loading.remove wire:target="uploadCsv">Importar y pre-mapear</span>
                    <span wire:loading wire:target="uploadCsv">Procesando...</span>
                </button>
            </div>
            <button wire:click="downloadTemplate"
                class="text-xs text-indigo-600 hover:underline whitespace-nowrap">
                ↓ Descargar plantilla CSV
            </button>
        </div>
        @if($importError)
            <p class="mt-2 text-xs text-red-600">{{ $importError }}</p>
        @endif
    </div>
    @endif

    <div class="bg-white shadow rounded-lg overflow-hidden">
        @if(!$client_id)
            <p class="px-6 py-10 text-center text-sm text-gray-500">Selecciona una empresa para ver los empleados del biométrico.</p>
        @else
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PIN</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre en dispositivo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado Factorial</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($biometricUsers as $user)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap font-mono text-sm font-semibold text-gray-900">
                        {{ $user['pin'] }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                        {{ $user['name'] ?? '—' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        {{ $user['source'] }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                        {{ $user['employee_name'] ?? '—' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($user['mapped'])
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Mapeado</span>
                        @else
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-700">Sin asignar</span>
                        @endif
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
        @endif
    </div>
    @endif

    {{-- TAB: Mapping --}}
    @if($tab === 'mapping')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        @if(empty($preview))
            <div class="px-6 py-10 text-center text-sm text-gray-500">
                <p>No hay importación en curso.</p>
                <button wire:click="$set('tab', 'biometric')" class="mt-2 text-indigo-600 hover:underline text-xs">
                    ← Volver a Empleados en Biométrico para subir un CSV
                </button>
            </div>
        @else
        <div x-data="{ filter: 'all' }">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4 flex-wrap">
            <div>
                <p class="text-sm font-medium text-gray-700">{{ count($preview) }} registros importados</p>
                <p class="text-xs text-gray-500 mt-0.5">Revisa las sugerencias y confirma el mapeo.</p>
            </div>
            {{-- Filtro --}}
            <div class="flex rounded-md shadow-sm border border-gray-300 overflow-hidden text-xs font-medium">
                <button @click="filter = 'all'"
                    :class="filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1.5 transition">Todos ({{ count($preview) }})</button>
                <button @click="filter = 'review'"
                    :class="filter === 'review' ? 'bg-amber-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1.5 border-l border-gray-300 transition">
                    Revisar ({{ collect($preview)->filter(fn($r) => !$r['already_mapped'] && $r['confidence'] < 80)->count() }})
                </button>
                <button @click="filter = 'none'"
                    :class="filter === 'none' ? 'bg-red-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1.5 border-l border-gray-300 transition">
                    Sin sugerencia ({{ collect($preview)->filter(fn($r) => !$r['already_mapped'] && $r['confidence'] < 50)->count() }})
                </button>
            </div>
            <div class="flex gap-3">
                <button wire:click="$set('preview', [])" class="px-3 py-1.5 text-sm text-gray-600 border border-gray-300 rounded hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="confirmMappings"
                    class="px-4 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded hover:bg-indigo-700 transition">
                    Confirmar mapeo
                </button>
            </div>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PIN</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nombre biométrico</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado Factorial</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Confianza</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($preview as $index => $row)
                @php
                    $isReview = !$row['already_mapped'] && $row['confidence'] < 80;
                    $isNone   = !$row['already_mapped'] && $row['confidence'] < 50;
                @endphp
                <tr class="hover:bg-gray-50"
                    x-show="filter === 'all' || (filter === 'review' && {{ $isReview ? 'true' : 'false' }}) || (filter === 'none' && {{ $isNone ? 'true' : 'false' }})">
                    <td class="px-6 py-3 font-mono text-sm font-semibold text-gray-900">{{ $row['pin'] }}</td>
                    <td class="px-6 py-3 text-sm text-gray-700">{{ $row['name'] ?: '—' }}</td>
                    <td class="px-6 py-3">
                        @if($row['already_mapped'])
                            <span class="text-sm text-gray-500 italic">Ya mapeado: {{ $row['employee_name'] }}</span>
                        @else
                            <select
                                wire:change="updateMappingRow({{ $index }}, $event.target.value)"
                                class="block w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">— Sin asignar —</option>
                                @foreach($employees as $emp)
                                    <option value="{{ $emp->id }}" {{ $row['employee_id'] == $emp->id ? 'selected' : '' }}>
                                        {{ $emp->full_name }}
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </td>
                    <td class="px-6 py-3">
                        @if($row['already_mapped'])
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-600">Existente</span>
                        @elseif($row['confidence'] >= 80)
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-700">{{ $row['confidence'] }}%</span>
                        @elseif($row['confidence'] >= 50)
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-amber-100 text-amber-700">{{ $row['confidence'] }}%</span>
                        @else
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-600">Sin sugerencia</span>
                        @endif
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
                        @if($isMapped)
                            <span class="text-gray-700">{{ $biometricIds[$employee->id] }}</span>
                        @else
                            <div x-data="{ pin: '', saved: false }" class="flex items-center gap-1">
                                <input
                                    x-model="pin"
                                    type="text"
                                    placeholder="PIN"
                                    class="w-24 rounded border-gray-300 text-xs font-mono px-2 py-1 focus:border-indigo-500 focus:ring-indigo-500"
                                    @keydown.enter="if(pin.trim()) { $wire.saveBiometricId({{ $employee->id }}, pin); saved = true }"
                                />
                                <button
                                    x-show="pin.trim().length > 0"
                                    @click="$wire.saveBiometricId({{ $employee->id }}, pin); saved = true"
                                    class="text-xs text-white bg-indigo-600 hover:bg-indigo-700 px-2 py-1 rounded transition">
                                    ✓
                                </button>
                            </div>
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
