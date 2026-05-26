<?php

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialEmployee;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public ?int    $client_id = null;
    public string  $search    = '';
    public string  $tab       = 'biometric'; // 'biometric' | 'factorial' | 'mapping' | 'unresolved'

    public function updatedSearch(): void   { $this->resetPage(); }
    public function updatedClientId(): void { $this->resetPage(); }
    public function updatedTab(): void      { $this->resetPage(); }

    public function with(): array
    {
        $clients = Client::orderBy('name')->get();

        // ── Tab: Mapping ────────────────────────────────────────────
        if ($this->tab === 'mapping') {
            $unmappedUsers = collect();
            $employees     = collect();

            if ($this->client_id) {
                $employees = FactorialEmployee::where('client_id', $this->client_id)->orderBy('full_name')->get();

                $mappedPins = BiometricUserSync::where('client_id', $this->client_id)
                    ->whereNotNull('factorial_employee_id')
                    ->pluck('external_employee_code')
                    ->map(fn($c) => (string) $c)
                    ->flip();

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

                // Ordenar alfabéticamente por nombre del dispositivo
                $unmappedUsers = $unmappedUsers->sortBy(fn($u) => mb_strtolower($u['name']));
            }

            return [
                'unmappedUsers'     => $unmappedUsers->values(),
                'biometricUsers'    => collect(),
                'biometricSources'  => collect(),
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
                    ->pluck('full_name', 'id');

                $biometricSources->each(function ($source) use (&$biometricUsers, $mappings, $employees) {
                    foreach ($source->device_users ?? [] as $u) {
                        $pin = (string) $u['pin'];
                        if ($this->search && stripos($pin, $this->search) === false && stripos($u['name'] ?? '', $this->search) === false) {
                            continue;
                        }
                        $empId = $mappings[$pin] ?? null;
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
                'biometricUsers'   => $biometricUsers,
                'biometricSources' => $biometricSources,
                'employees'        => collect(),
                'unresolved'       => collect(),
                'unresolvedCount'  => $unresolvedCount,
                'clients'          => $clients,
                'vendorName'       => 'Biométrico',
                'mappedEmployeeIds' => collect(),
                'biometricIds'     => collect(),
            ];
        }

        // ── Tab: Sin asignar ────────────────────────────────────────
        if ($this->tab === 'unresolved') {
            if (!$this->client_id) {
                return ['unmappedUsers' => collect(), 'biometricSources' => collect(), 'employees' => collect(), 'unresolved' => collect(), 'clients' => $clients, 'unresolvedCount' => 0];
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
                'unmappedUsers'    => collect(),
                'biometricUsers'   => collect(),
                'biometricSources' => collect(),
                'employees'        => collect(),
                'unresolved'       => $unresolved,
                'unresolvedCount'  => $unresolved->total(),
                'clients'          => $clients,
                'vendorName'       => 'Biométrico',
                'mappedEmployeeIds' => collect(),
                'biometricIds'     => collect(),
            ];
        }

        // ── Tab: Empleados Factorial ────────────────────────────────
        if (!$this->client_id) {
            return ['unmappedUsers' => collect(), 'biometricSources' => collect(), 'employees' => collect(), 'unresolved' => collect(), 'unresolvedCount' => 0, 'clients' => $clients, 'vendorName' => 'Biométrico', 'mappedEmployeeIds' => collect(), 'biometricIds' => collect()];
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
            'unmappedUsers'     => collect(),
            'biometricUsers'    => collect(),
            'biometricSources'  => collect(),
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

    public function mapUser(string $pin, mixed $employeeId, ?int $providerId = null): void
    {
        $employeeId = ($employeeId !== '' && $employeeId !== null) ? (int) $employeeId : null;
        if (!$employeeId || !$this->client_id) return;

        $emp = FactorialEmployee::find($employeeId);
        if (!$emp) return;

        $provider = $providerId
            ? BiometricProvider::find($providerId)
            : BiometricProvider::where('client_id', $this->client_id)->first();
        if (!$provider) return;

        BiometricUserSync::updateOrCreate(
            ['biometric_provider_id' => $provider->id, 'external_employee_code' => $pin],
            ['client_id' => $this->client_id, 'factorial_employee_id' => $employeeId, 'sync_status' => 'pending', 'last_attempt_at' => now()]
        );

        $logIds = AttendanceLog::where('client_id', $this->client_id)
            ->where('employee_code', $pin)
            ->whereNull('factorial_employee_id')
            ->pluck('id');

        if ($logIds->isNotEmpty()) {
            AttendanceLog::whereIn('id', $logIds)->update([
                'factorial_employee_id' => $employeeId,
                'sync_status'           => 'resolved',
            ]);

            $delay = 0;
            foreach ($logIds as $logId) {
                SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
                $delay += 2;
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
        @if($client_id && $biometricUsers instanceof \Illuminate\Pagination\LengthAwarePaginator && $biometricUsers->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $biometricUsers->links() }}
        </div>
        @endif
        @endif
    </div>
    @endif

    {{-- TAB: Mapping --}}
    @if($tab === 'mapping')
    <div class="bg-white shadow rounded-lg overflow-hidden">
        @if(!$client_id)
            <p class="px-6 py-10 text-center text-sm text-gray-500">Selecciona una empresa para ver el mapping.</p>
        @elseif($unmappedUsers->isEmpty())
            <div class="px-6 py-10 text-center">
                <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm text-green-600 font-medium">✓ Todos los usuarios del biométrico están mapeados.</p>
                <p class="mt-1 text-xs text-gray-400">Importa el CSV desde <strong>Dispositivos</strong> si hay usuarios nuevos.</p>
            </div>
        @else
        <div class="px-6 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm text-gray-500">{{ $unmappedUsers->count() }} usuario(s) sin mapear · ordenados alfabéticamente</p>
            <p class="text-xs text-gray-400">{{ $employees->count() }} empleados Factorial disponibles en el selector</p>
        </div>
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-20">PIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nombre en dispositivo</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dispositivo</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-16">Match</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Asignar a</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @foreach($unmappedUsers as $row)
                @php
                    $score = $row['score'] ?? 0;
                    $scoreColor = $score >= 90 ? 'bg-emerald-100 text-emerald-700'
                                : ($score >= 70 ? 'bg-yellow-100 text-yellow-700'
                                : 'bg-red-100 text-red-600');
                @endphp
                <tr class="hover:bg-gray-50">
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
                            wire:change="mapUser('{{ $row['pin'] }}', $event.target.value, {{ $row['provider_id'] ?? 'null' }})"
                            class="block w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 py-1">
                            <option value="">— {{ $row['suggested_name'] ?? 'Sin sugerencia' }} —</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}" @selected($emp->id == ($row['suggested_id'] ?? null))>
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
