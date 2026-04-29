<?php

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\DeviceCommand;
use App\Models\FactorialEmployee;
use Livewire\Volt\Component;

new class extends Component {

    public int    $sourceId;
    public array  $mappedRows = [];   // BiometricUserSync records (always shown)
    public array  $newRows    = [];   // device_users not yet mapped (after sync)
    public bool   $syncing    = false;
    public string $notice     = '';

    public function mount(int $sourceId): void
    {
        $this->sourceId = $sourceId;
        $this->loadMappedRows();
    }

    // ── Loaders ────────────────────────────────────────────────────

    protected function loadMappedRows(): void
    {
        $source      = BiometricSource::findOrFail($this->sourceId);
        $employees   = $this->getEmployees($source);
        $deviceUsers = collect($source->device_users ?? [])->keyBy(fn($u) => (string) $u['pin']);

        $syncs = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)->get();

        $this->mappedRows = $syncs->map(function ($sync) use ($employees, $deviceUsers) {
            $emp        = $employees->find($sync->factorial_employee_id);
            $deviceUser = $deviceUsers[(string) $sync->external_employee_code] ?? null;
            return [
                'pin'           => $sync->external_employee_code,
                'hint'          => $deviceUser['name'] ?? '',
                'employee_id'   => $sync->factorial_employee_id,
                'employee_name' => $emp?->full_name ?? '—',
                'overwritten'   => $sync->pin_overwritten ?? false,
            ];
        })->toArray();
    }

    protected function buildNewRows(): void
    {
        $source      = BiometricSource::findOrFail($this->sourceId);
        $employees   = $this->getEmployees($source);
        $rawUsers    = $source->device_users ?? [];

        $existingPins = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->pluck('external_employee_code')
            ->map(fn($p) => (string) $p)
            ->toArray();

        $this->newRows = [];
        foreach ($rawUsers as $user) {
            $pin = (string) $user['pin'];
            if (in_array($pin, $existingPins)) continue;

            $hint = $user['name'] ?? '';
            $this->newRows[] = [
                'pin'         => $pin,
                'hint'        => $hint,
                'employee_id' => $this->autoMatch($hint, $employees),
                'confirmed'   => false,
            ];
        }
    }

    protected function getEmployees(BiometricSource $source)
    {
        $factorialCompanyId = \App\Models\FactorialConnection::where('client_id', $source->client_id)
            ->whereNotNull('factorial_company_id')
            ->value('factorial_company_id');

        $query = FactorialEmployee::orderBy('full_name');

        if ($factorialCompanyId) {
            $query->where('company_id', $factorialCompanyId);
        } else {
            $query->where('factorial_connection_id', function ($q) use ($source) {
                $q->select('id')->from('factorial_connections')->where('client_id', $source->client_id);
            });
        }

        return $query->get();
    }

    protected function autoMatch(string $hint, $employees): ?int
    {
        if (empty($hint)) return null;
        $hint      = mb_strtolower($hint);
        $best      = null;
        $bestScore = 0;

        foreach ($employees as $emp) {
            similar_text($hint, mb_strtolower($emp->full_name), $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best      = $emp->id;
            }
        }

        return $bestScore >= 50 ? $best : null;
    }

    public function with(): array
    {
        $source = BiometricSource::findOrFail($this->sourceId);
        return [
            'source'    => $source,
            'employees' => $this->getEmployees($source),
        ];
    }

    // ── Sync ───────────────────────────────────────────────────────

    public function queryDevice(): void
    {
        $source = BiometricSource::findOrFail($this->sourceId);
        $maxSeq = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;

        DeviceCommand::create([
            'biometric_source_id' => $source->id,
            'command_seq'         => $maxSeq + 1,
            'command_type'        => 'query_users',
            'payload'             => 'DATA QUERY USERINFO',
            'status'              => 'pending',
        ]);

        $this->syncing = true;
        $this->dispatch('sync-started');
    }

    public function refreshFromDevice(): void
    {
        $this->syncing = false;
        $this->loadMappedRows();
        $this->buildNewRows();

        $count         = count($this->newRows);
        $this->notice  = $count > 0
            ? "{$count} usuario(s) nuevo(s) encontrado(s) en el dispositivo. Asígnalos a un empleado y guarda el mapeo."
            : 'Sincronización completada. No hay usuarios nuevos sin mapear.';
    }

    // ── Mapeo de nuevos usuarios ───────────────────────────────────

    public function updateEmployee(int $index, string $value): void
    {
        $this->newRows[$index]['employee_id'] = $value ? (int) $value : null;
        $this->newRows[$index]['confirmed']   = false;
    }

    public function toggleConfirm(int $index): void
    {
        $this->newRows[$index]['confirmed'] = !$this->newRows[$index]['confirmed'];
    }

    public function saveMapping(): void
    {
        $source = BiometricSource::findOrFail($this->sourceId);

        foreach ($this->newRows as $row) {
            if (!$row['confirmed'] || !$row['employee_id']) continue;

            BiometricUserSync::updateOrCreate(
                [
                    'biometric_provider_id'  => $source->biometric_provider_id,
                    'external_employee_code' => $row['pin'],
                ],
                [
                    'client_id'             => $source->client_id,
                    'factorial_employee_id' => $row['employee_id'],
                    'sync_status'           => 'mapped',
                    'pin_overwritten'       => false,
                ]
            );
        }

        $this->loadMappedRows();
        $this->buildNewRows();
        $this->notice = 'Mapeo guardado correctamente.';
    }

    // ── Sobrescribir PINs ──────────────────────────────────────────

    public function overwritePins(): void
    {
        $source    = BiometricSource::findOrFail($this->sourceId);
        $employees = $this->getEmployees($source)->keyBy('id');
        $maxSeq    = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;
        $count     = 0;

        foreach ($this->mappedRows as $row) {
            if ($row['overwritten'] || !$row['employee_id']) continue;

            $employee = $employees[$row['employee_id']] ?? null;
            if (!$employee || !$employee->access_id) continue;

            DeviceCommand::create([
                'biometric_source_id' => $source->id,
                'command_seq'         => ++$maxSeq,
                'command_type'        => 'overwrite_pin',
                'payload'             => "DATA UPDATE USERINFO PIN={$row['pin']}\tNewPIN={$employee->access_id}\tName={$employee->full_name}",
                'status'              => 'pending',
            ]);

            BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
                ->where('external_employee_code', $row['pin'])
                ->update([
                    'pin_overwritten'        => true,
                    'external_employee_code' => (string) $employee->access_id,
                ]);

            $count++;
        }

        $this->loadMappedRows();
        $this->notice = "{$count} comandos de sobrescritura enviados al dispositivo.";
    }

    // ── Histórico ──────────────────────────────────────────────────

    public function processHistoric(): void
    {
        $source   = BiometricSource::findOrFail($this->sourceId);
        $provider = $source->biometric_provider_id;

        $mappings = BiometricUserSync::where('biometric_provider_id', $provider)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        $resolved = 0;
        $logs     = \App\Models\AttendanceLog::where('biometric_source_id', $source->id)
            ->whereNull('factorial_employee_id')
            ->get();

        foreach ($logs as $log) {
            $employeeId = $mappings[$log->employee_code] ?? null;

            if (!$employeeId) {
                $emp = \App\Models\FactorialEmployee::where('factorial_connection_id', function ($q) use ($source) {
                    $q->select('id')->from('factorial_connections')->where('client_id', $source->client_id);
                })->where('access_id', $log->employee_code)->first();
                $employeeId = $emp?->id;
            }

            if ($employeeId) {
                $log->update(['factorial_employee_id' => $employeeId, 'sync_status' => 'resolved']);
                SyncAttendanceToFactorial::dispatch($log->id);
                $resolved++;
            }
        }

        $total        = $logs->count();
        $this->notice = "Histórico procesado: {$resolved} de {$total} registros resueltos.";
    }

    // ── Helpers ────────────────────────────────────────────────────

    public function confirmedNewCount(): int
    {
        return collect($this->newRows)->filter(fn($r) => $r['confirmed'] && $r['employee_id'])->count();
    }

    public function pendingOverwriteCount(): int
    {
        return collect($this->mappedRows)->filter(fn($r) => !$r['overwritten'] && $r['employee_id'])->count();
    }

    public function overwrittenCount(): int
    {
        return collect($this->mappedRows)->filter(fn($r) => $r['overwritten'])->count();
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Mapeo de PINs biométricos</h2>
            <p class="text-sm text-gray-500 mt-0.5">
                <span class="font-mono text-gray-700">{{ $source->serial_number }}</span>
                <span class="mx-1 text-gray-300">·</span>
                {{ $source->client?->name }}
                @if($source->vendor)
                    <span class="mx-1 text-gray-300">·</span>
                    <span class="text-gray-400">{{ $source->vendor }}</span>
                @endif
            </p>
        </div>

        {{-- Stats pills --}}
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-1 px-3 py-1 bg-indigo-50 rounded-full">
                <span class="text-sm font-semibold text-indigo-700">{{ count($mappedRows) }}</span>
                <span class="text-xs text-indigo-500">mapeados</span>
            </div>
            @if($this->overwrittenCount() > 0)
            <div class="flex items-center gap-1 px-3 py-1 bg-emerald-50 rounded-full">
                <span class="text-sm font-semibold text-emerald-700">{{ $this->overwrittenCount() }}</span>
                <span class="text-xs text-emerald-500">sobrescritos</span>
            </div>
            @endif
            @if(!empty($newRows))
            <div class="flex items-center gap-1 px-3 py-1 bg-amber-50 rounded-full">
                <span class="text-sm font-semibold text-amber-700">{{ count($newRows) }}</span>
                <span class="text-xs text-amber-500">nuevos</span>
            </div>
            @endif
        </div>
    </div>

    {{-- Notice --}}
    @if($notice)
    <div class="mb-5 px-4 py-3 bg-indigo-50 border border-indigo-200 rounded-lg text-sm text-indigo-700 flex items-start gap-2">
        <svg class="w-4 h-4 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
        </svg>
        {{ $notice }}
    </div>
    @endif

    {{-- ── Sync bar (Alpine-powered countdown) ────────────────────── --}}
    <div
        x-data="{
            countdown: 0,
            timer: null,
            get circumference() { return 2 * Math.PI * 18; },
            get dashoffset() { return this.circumference * (1 - this.countdown / 30); },
            startCountdown() {
                this.countdown = 30;
                clearInterval(this.timer);
                this.timer = setInterval(() => {
                    if (this.countdown > 0) {
                        this.countdown--;
                    } else {
                        clearInterval(this.timer);
                        $wire.refreshFromDevice();
                    }
                }, 1000);
            }
        }"
        @sync-started.window="startCountdown()"
        class="flex items-center gap-4 mb-6 px-5 py-4 bg-white rounded-xl border border-gray-200 shadow-sm"
    >
        {{-- Sync button --}}
        <button
            wire:click="queryDevice"
            :disabled="countdown > 0"
            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
        >
            <svg wire:loading wire:target="queryDevice" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            <svg wire:loading.remove wire:target="queryDevice" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span>Sincronizar</span>
        </button>

        {{-- Countdown ring --}}
        <div x-show="countdown > 0" x-transition class="flex items-center gap-3">
            <div class="relative" style="width:44px;height:44px;">
                <svg style="width:44px;height:44px;transform:rotate(-90deg)" viewBox="0 0 44 44">
                    <circle cx="22" cy="22" r="18" fill="none" stroke="#e5e7eb" stroke-width="3"/>
                    <circle cx="22" cy="22" r="18" fill="none" stroke="#6366f1" stroke-width="3"
                        :stroke-dasharray="circumference"
                        :stroke-dashoffset="dashoffset"
                        style="transition: stroke-dashoffset 1s linear; stroke-linecap: round;"
                    />
                </svg>
                <span
                    class="absolute inset-0 flex items-center justify-center text-xs font-bold text-indigo-600"
                    x-text="countdown"
                ></span>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-700">Esperando respuesta del dispositivo…</p>
                <p class="text-xs text-gray-400">El biométrico responderá en su próxima sincronización</p>
            </div>
        </div>

        {{-- Idle info --}}
        <div x-show="countdown === 0" class="text-xs text-gray-400 ml-auto">
            @if($source->device_users_fetched_at)
                Última consulta: {{ $source->device_users_fetched_at->diffForHumans() }}
            @else
                Sin datos del dispositivo aún
            @endif
        </div>
    </div>


    {{-- ── Sección: Usuarios ya mapeados ──────────────────────────── --}}
    <div class="mb-6">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-sm font-semibold text-gray-700">
                    Usuarios mapeados
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs font-normal bg-gray-100 text-gray-500 rounded-full">{{ count($mappedRows) }}</span>
                </h3>
            </div>
            <div class="flex items-center gap-2">
                @if(count($mappedRows) > 0)
                <button wire:click="processHistoric"
                    wire:confirm="¿Procesar registros históricos? Se resolverán los registros sin empleado usando el mapeo confirmado."
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Procesar histórico
                </button>
                @endif
                @if($this->pendingOverwriteCount() > 0)
                <button wire:click="overwritePins"
                    wire:confirm="¿Sobrescribir {{ $this->pendingOverwriteCount() }} PINs en el dispositivo? El equipo actualizará los PINs al access_id de Factorial. Esta acción no se puede deshacer fácilmente."
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Sobrescribir PINs ({{ $this->pendingOverwriteCount() }})
                </button>
                @endif
            </div>
        </div>

        @if(empty($mappedRows))
        <div class="flex flex-col items-center gap-2 py-10 bg-white rounded-xl border border-gray-200 text-center">
            <svg class="w-8 h-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <p class="text-sm text-gray-400">No hay usuarios mapeados aún.</p>
            <p class="text-xs text-gray-400">Haz clic en <strong class="text-gray-600">Sincronizar</strong> para consultar el dispositivo y obtener la lista de usuarios.</p>
        </div>
        @else
        <div class="bg-white shadow-sm rounded-xl overflow-hidden border border-gray-200">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">PIN</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre en dispositivo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado Factorial</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-28">Estado</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @foreach($mappedRows as $row)
                    <tr class="{{ $row['overwritten'] ? 'bg-emerald-50' : '' }} hover:bg-gray-50 transition">
                        <td class="px-4 py-3">
                            <span class="font-mono text-sm font-bold text-gray-800">{{ $row['pin'] }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            {{ $row['hint'] ?: '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm text-gray-800">{{ $row['employee_name'] }}</span>
                            @if($row['overwritten'])
                                <span class="ml-2 text-xs font-mono text-emerald-600">PIN unificado</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($row['overwritten'])
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-700">Sobrescrito</span>
                            @else
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-700">Mapeado</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>


    {{-- ── Sección: Usuarios nuevos sin mapear ─────────────────────── --}}
    @if(!empty($newRows))
    <div>
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="text-sm font-semibold text-amber-700">
                    Usuarios nuevos sin mapear
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs font-normal bg-amber-100 text-amber-600 rounded-full">{{ count($newRows) }}</span>
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">Estos usuarios están en el dispositivo pero aún no tienen un empleado asignado.</p>
            </div>
            <button wire:click="saveMapping"
                @if($this->confirmedNewCount() === 0) disabled @endif
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Guardar mapeo ({{ $this->confirmedNewCount() }})
            </button>
        </div>

        <div class="bg-white shadow-sm rounded-xl overflow-hidden border border-amber-200">
            <table class="min-w-full divide-y divide-amber-100">
                <thead style="background-color: #fffbeb;">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-amber-700 uppercase tracking-wider w-20">PIN</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-amber-700 uppercase tracking-wider">Nombre en dispositivo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-amber-700 uppercase tracking-wider">Asignar empleado</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-amber-700 uppercase tracking-wider w-28">Confirmar</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-amber-50">
                    @foreach($newRows as $i => $row)
                    <tr class="{{ $row['confirmed'] ? 'bg-indigo-50' : 'hover:bg-gray-50' }} transition">
                        <td class="px-4 py-3">
                            <span class="font-mono text-sm font-bold text-gray-800">{{ $row['pin'] }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            {{ $row['hint'] ?: '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <select
                                wire:change="updateEmployee({{ $i }}, $event.target.value)"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500 {{ $row['confirmed'] ? 'bg-indigo-50 border-indigo-300' : '' }}"
                                {{ $row['confirmed'] ? 'disabled' : '' }}
                            >
                                <option value="">Sin asignar</option>
                                @foreach($employees as $emp)
                                    <option value="{{ $emp->id }}" {{ $row['employee_id'] == $emp->id ? 'selected' : '' }}>
                                        {{ $emp->full_name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button
                                wire:click="toggleConfirm({{ $i }})"
                                @if(!$row['employee_id']) disabled @endif
                                class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full transition
                                    {{ $row['confirmed']
                                        ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-40' }}"
                            >
                                {{ $row['confirmed'] ? '✓ Confirmado' : 'Confirmar' }}
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
