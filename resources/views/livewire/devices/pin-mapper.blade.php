<?php

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\DeviceCommand;
use App\Models\FactorialEmployee;
use Livewire\Volt\Component;

new class extends Component {

    public int $sourceId;
    public array $rows = [];
    public string $notice = '';

    public function mount(int $sourceId): void
    {
        $this->sourceId = $sourceId;
        $this->buildRows();
    }

    protected function buildRows(): void
    {
        $source    = BiometricSource::findOrFail($this->sourceId);
        $employees = $this->getEmployees($source);
        $existing  = BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->get()
            ->keyBy('external_employee_code');

        $rawUsers = $source->device_users ?? [];

        $this->rows = [];
        foreach ($rawUsers as $user) {
            $pin        = (string) $user['pin'];
            $hint       = $user['name'] ?? '';
            $sync       = $existing[$pin] ?? null;
            $matchedId  = $sync?->factorial_employee_id ?? $this->autoMatch($hint, $employees);
            $overwritten = $sync?->pin_overwritten ?? false;

            $this->rows[] = [
                'pin'          => $pin,
                'hint'         => $hint,
                'employee_id'  => $matchedId,
                'confirmed'    => $sync !== null,
                'overwritten'  => $overwritten,
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
        $hint = mb_strtolower($hint);
        $best = null;
        $bestScore = 0;

        foreach ($employees as $emp) {
            similar_text($hint, mb_strtolower($emp->full_name), $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $emp->id;
            }
        }

        return $bestScore >= 50 ? $best : null;
    }

    protected function excelFallback(): array
    {
        return [
            ['pin' => '2',  'name' => 'Humberto Daniel Fernandez'],
            ['pin' => '3',  'name' => 'Estefanía Rivera'],
            ['pin' => '5',  'name' => 'Fernando Hernandez Roldan'],
            ['pin' => '7',  'name' => 'Karla V. Alvarez Vázquez'],
            ['pin' => '8',  'name' => 'Luis Alberto Gonzalez Perez'],
            ['pin' => '9',  'name' => 'Gustavo Uriel Rivera Sánchez'],
            ['pin' => '10', 'name' => 'Ivan Torres Guarneros'],
            ['pin' => '11', 'name' => 'Jair Maldonado Perez'],
            ['pin' => '12', 'name' => 'Kenny Arth Luevanos Uribe'],
            ['pin' => '13', 'name' => 'Lucero Asiri Silva Chavez'],
            ['pin' => '14', 'name' => 'Sofia Ortiz Garcia'],
            ['pin' => '15', 'name' => 'Jesús Alejandro Ibarra Martínez'],
            ['pin' => '34', 'name' => 'Luis Gustavo De Jesus Reyes'],
            ['pin' => '35', 'name' => 'Ana Paulina Suarez Mata'],
            ['pin' => '36', 'name' => 'Maria de los Ángeles Becerra'],
            ['pin' => '38', 'name' => 'Cristian Javier Villa Diaz'],
            ['pin' => '39', 'name' => 'Peter Behnsen'],
            ['pin' => '40', 'name' => 'Pedro Daniel Cerdeira Goncalves'],
            ['pin' => '41', 'name' => 'Felipe Caldeira Da Costa'],
            ['pin' => '42', 'name' => 'Andrea M Juarez Contreras'],
            ['pin' => '45', 'name' => 'Mario Chávez Calderon'],
        ];
    }

    public function with(): array
    {
        $source = BiometricSource::findOrFail($this->sourceId);
        return [
            'source'    => $source,
            'employees' => $this->getEmployees($source),
        ];
    }

    // ── Nivel 1: Mapeo ────────────────────────────────────────────

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

        $this->notice = 'Comando enviado. El dispositivo responderá en su próxima sincronización (~30s).';
    }

    public function refreshFromDevice(): void
    {
        $this->buildRows();
        $this->notice = '';
    }

    public function updateEmployee(int $index, string $value): void
    {
        $this->rows[$index]['employee_id'] = $value ? (int) $value : null;
        $this->rows[$index]['confirmed']   = false;
    }

    public function toggleConfirm(int $index): void
    {
        $this->rows[$index]['confirmed'] = !$this->rows[$index]['confirmed'];
    }

    public function saveMapping(): void
    {
        $source = BiometricSource::findOrFail($this->sourceId);

        foreach ($this->rows as $row) {
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

        $this->buildRows();
        $this->notice = 'Mapeo guardado correctamente.';
    }

    // ── Nivel 2: Sobrescribir PINs ────────────────────────────────

    public function overwritePins(): void
    {
        $source    = BiometricSource::findOrFail($this->sourceId);
        $employees = $this->getEmployees($source)->keyBy('id');
        $maxSeq    = DeviceCommand::where('biometric_source_id', $source->id)->max('command_seq') ?? 0;
        $count     = 0;

        foreach ($this->rows as $row) {
            if (!$row['confirmed'] || !$row['employee_id'] || $row['overwritten']) continue;

            $employee = $employees[$row['employee_id']] ?? null;
            if (!$employee || !$employee->access_id) continue;

            // Comando para sobrescribir PIN en el dispositivo
            $payload = "DATA UPDATE USERINFO PIN={$row['pin']}\tNewPIN={$employee->access_id}\tName={$employee->full_name}";

            DeviceCommand::create([
                'biometric_source_id' => $source->id,
                'command_seq'         => ++$maxSeq,
                'command_type'        => 'overwrite_pin',
                'payload'             => $payload,
                'status'              => 'pending',
            ]);

            // Marcar como sobrescrito en la tabla de sync
            BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
                ->where('external_employee_code', $row['pin'])
                ->update([
                    'pin_overwritten'        => true,
                    'external_employee_code' => (string) $employee->access_id,
                ]);

            $count++;
        }

        $this->buildRows();
        $this->notice = "{$count} comandos de sobrescritura enviados al dispositivo.";
    }

    public function processHistoric(): void
    {
        $source   = BiometricSource::findOrFail($this->sourceId);
        $provider = $source->biometric_provider_id;

        $mappings = BiometricUserSync::where('biometric_provider_id', $provider)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        $resolved = 0;

        $logs = \App\Models\AttendanceLog::where('biometric_source_id', $source->id)
            ->whereNull('factorial_employee_id')
            ->get();

        foreach ($logs as $log) {
            // Estrategia 1: tabla de mapeo
            $employeeId = $mappings[$log->employee_code] ?? null;

            // Estrategia 2: match directo por access_id
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

        $total = $logs->count();
        $this->notice = "Histórico procesado: {$resolved} de {$total} registros resueltos.";
    }

    public function confirmedCount(): int
    {
        return collect($this->rows)->filter(fn($r) => $r['confirmed'] && $r['employee_id'])->count();
    }

    public function overwrittenCount(): int
    {
        return collect($this->rows)->filter(fn($r) => $r['overwritten'])->count();
    }

    public function pendingOverwriteCount(): int
    {
        return collect($this->rows)->filter(fn($r) => $r['confirmed'] && $r['employee_id'] && !$r['overwritten'])->count();
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Mapeo de PINs biométricos</h2>
            <p class="text-sm text-gray-500 mt-0.5">
                <span class="font-mono">{{ $source->serial_number }}</span> · {{ $source->client?->name }}
                @if($source->device_users_fetched_at)
                · <span class="text-xs text-gray-400">Usuarios del dispositivo: {{ $source->device_users_fetched_at->diffForHumans() }}</span>
                @else
                · <span class="text-xs text-amber-600">Sin datos del dispositivo — consulta el dispositivo primero</span>
                @endif
            </p>
        </div>

        {{-- Stats --}}
        <div class="flex items-center gap-4 text-sm text-gray-500">
            <span><span class="font-semibold text-gray-800">{{ count($rows) }}</span> usuarios</span>
            <span><span class="font-semibold text-indigo-600">{{ $this->confirmedCount() }}</span> mapeados</span>
            <span><span class="font-semibold text-emerald-600">{{ $this->overwrittenCount() }}</span> sobrescritos</span>
        </div>
    </div>

    {{-- Notice --}}
    @if($notice)
    <div class="mb-4 px-4 py-3 bg-indigo-50 border border-indigo-200 rounded-md text-sm text-indigo-700">
        {{ $notice }}
    </div>
    @endif

    {{-- Actions bar --}}
    <div class="flex items-center gap-3 mb-4">
        {{-- Nivel 1 --}}
        <button wire:click="queryDevice"
            class="inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
            <svg wire:loading wire:target="queryDevice" class="animate-spin w-4 h-4 mr-2 text-gray-500" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            <svg wire:loading.remove wire:target="queryDevice" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Consultar dispositivo
        </button>

        @if($source->device_users_fetched_at)
        <button wire:click="refreshFromDevice" class="text-sm text-indigo-600 hover:text-indigo-800">
            Actualizar lista
        </button>
        @endif

        <div class="flex-1"></div>

        {{-- Procesar histórico --}}
        @if($this->confirmedCount() > 0)
        <button wire:click="processHistoric"
            wire:confirm="¿Procesar registros históricos? Se resolverán los attendance_logs sin empleado usando el mapeo confirmado."
            class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
            <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Procesar histórico
        </button>
        @endif

        {{-- Nivel 1: Guardar mapeo --}}
        <button wire:click="saveMapping"
            @if($this->confirmedCount() === 0) disabled @endif
            class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 transition disabled:opacity-40">
            Guardar mapeo ({{ $this->confirmedCount() }})
        </button>

        {{-- Nivel 2: Sobrescribir PINs --}}
        @if($this->pendingOverwriteCount() > 0)
        <button wire:click="overwritePins"
            wire:confirm="¿Sobrescribir {{ $this->pendingOverwriteCount() }} PINs en el dispositivo? El equipo actualizará los PINs al access_id de Factorial. Esta acción no se puede deshacer fácilmente."
            class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-md hover:bg-amber-700 transition">
            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Sobrescribir PINs ({{ $this->pendingOverwriteCount() }})
        </button>
        @endif
    </div>

    {{-- Table --}}
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">PIN</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre en dispositivo</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado Factorial</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-28">Estado</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-28">Acción</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @if(empty($rows))
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center text-sm text-gray-400">
                        <div class="flex flex-col items-center gap-2">
                            <svg class="w-8 h-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                            </svg>
                            <p>No hay usuarios del dispositivo aún.</p>
                            <p class="text-xs">Haz clic en <strong>Consultar dispositivo</strong> y espera ~30 segundos.</p>
                        </div>
                    </td>
                </tr>
                @endif
                @foreach($rows as $i => $row)
                <tr class="transition {{ $row['overwritten'] ? 'bg-emerald-50' : ($row['confirmed'] ? 'bg-indigo-50' : 'hover:bg-gray-50') }}">

                    {{-- PIN --}}
                    <td class="px-4 py-3">
                        <span class="font-mono text-sm font-bold text-gray-800">{{ $row['pin'] }}</span>
                    </td>

                    {{-- Nombre del dispositivo --}}
                    <td class="px-4 py-3 text-sm text-gray-600">
                        {{ $row['hint'] ?: '—' }}
                    </td>

                    {{-- Dropdown empleado --}}
                    <td class="px-4 py-3">
                        @if($row['overwritten'])
                            @php $emp = $employees->find($row['employee_id']); @endphp
                            <span class="text-sm text-gray-700">{{ $emp?->full_name ?? '—' }}</span>
                            <span class="ml-2 text-xs text-emerald-600 font-mono">PIN→{{ $emp?->access_id }}</span>
                        @else
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
                        @endif
                    </td>

                    {{-- Estado --}}
                    <td class="px-4 py-3 text-center">
                        @if($row['overwritten'])
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-700">Sobrescrito</span>
                        @elseif($row['confirmed'])
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-700">Mapeado</span>
                        @else
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-500">Pendiente</span>
                        @endif
                    </td>

                    {{-- Confirmar --}}
                    <td class="px-4 py-3 text-center">
                        @if(!$row['overwritten'])
                        <button wire:click="toggleConfirm({{ $i }})"
                            @if(!$row['employee_id']) disabled @endif
                            class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-full transition
                                {{ $row['confirmed']
                                    ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'
                                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-40' }}">
                            {{ $row['confirmed'] ? '✓ Confirmado' : 'Confirmar' }}
                        </button>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Legend --}}
    <div class="mt-4 flex items-center gap-6 text-xs text-gray-400">
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-gray-100 inline-block"></span> Pendiente — sin mapear</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-indigo-100 inline-block"></span> Mapeado — usa tabla de mapeo</span>
        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-100 inline-block"></span> Sobrescrito — match directo por access_id</span>
    </div>
</div>
