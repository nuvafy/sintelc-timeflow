<?php

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialEmployee;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;

new class extends Component {

    public Client $client;

    // Filas de sugerencias: array de arrays con pin, name, source_id, provider_id, suggested_id, score
    public array $suggestions = [];

    // Empleados disponibles para el selector manual (id => full_name)
    public array $employeeOptions = [];

    // Overrides manuales: pin|source_id => factorial_employee_id seleccionado
    public array $overrides = [];

    // Mensajes de resultado por pin|source_id
    public array $results = [];

    public function mount(Client $client): void
    {
        $this->client = $client;
        $this->loadSuggestions();
    }

    public function loadSuggestions(): void
    {
        // Empleados Factorial de esta empresa
        $employees = FactorialEmployee::where('client_id', $this->client->id)
            ->orderBy('full_name')
            ->get(['id', 'full_name']);

        $this->employeeOptions = $employees->pluck('full_name', 'id')->toArray();

        // Mapeos ya existentes (pin => factorial_employee_id)
        $existingByPin = BiometricUserSync::where('client_id', $this->client->id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code')
            ->toArray();

        // Normalizador de nombres
        $normalize = fn($s) => preg_replace('/\s+/', ' ', trim(str_replace(
            ['„','ê','û','î','â','ô','Ñ','ñ','Á','á','É','é','Í','í','Ó','ó','Ú','ú','Ü','ü'],
            ['n','e','u','i','a','o','n','n','a','a','e','e','i','i','o','o','u','u','u','u'],
            mb_strtolower($s)
        )));

        $normalizedEmployees = $employees->map(fn($e) => [
            'id'   => $e->id,
            'norm' => $normalize($e->full_name),
            'name' => $e->full_name,
        ])->all();

        // Recorrer todos los biométricos de la empresa
        $sources = BiometricSource::where('client_id', $this->client->id)
            ->whereNotNull('device_users')
            ->with('provider')
            ->get();

        $suggestions = [];

        foreach ($sources as $source) {
            foreach ($source->device_users as $user) {
                $pin = $user['pin'] ?? null;
                $name = $user['name'] ?? '';

                if (!$pin || isset($existingByPin[$pin])) continue;

                // Calcular mejor match
                $bestScore = 0;
                $bestId    = null;
                $bestName  = null;
                $normPin   = $normalize($name);

                foreach ($normalizedEmployees as $emp) {
                    similar_text($normPin, $emp['norm'], $pct);
                    if ($pct > $bestScore) {
                        $bestScore = $pct;
                        $bestId    = $emp['id'];
                        $bestName  = $emp['name'];
                    }
                }

                $key = $pin . '|' . $source->id;

                $suggestions[$key] = [
                    'key'          => $key,
                    'pin'          => $pin,
                    'device_name'  => $name,
                    'source_id'    => $source->id,
                    'source_label' => $source->serial_number,
                    'provider_id'  => $source->biometric_provider_id,
                    'suggested_id' => $bestId,
                    'suggested_name' => $bestName,
                    'score'        => round($bestScore, 1),
                ];
            }
        }

        // Ordenar: primero los de mayor score
        usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);

        $this->suggestions = array_values($suggestions);
        $this->overrides   = [];
        $this->results     = [];
    }

    public function accept(string $key): void
    {
        $suggestion = collect($this->suggestions)->firstWhere('key', $key);
        if (!$suggestion) return;

        $employeeId = $this->overrides[$key] ?? $suggestion['suggested_id'];
        if (!$employeeId) return;

        $pin      = $suggestion['pin'];
        $sourceId = $suggestion['source_id'];

        $source = BiometricSource::find($sourceId);
        if (!$source) return;

        // Crear o actualizar el mapeo
        BiometricUserSync::updateOrCreate(
            [
                'biometric_provider_id'  => $suggestion['provider_id'],
                'external_employee_code' => $pin,
            ],
            [
                'client_id'              => $this->client->id,
                'factorial_employee_id'  => $employeeId,
                'sync_status'            => 'pending',
                'last_attempt_at'        => now(),
            ]
        );

        // Resolver logs pendientes de este pin
        $logIds = AttendanceLog::where('client_id', $this->client->id)
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

        $empName = $this->employeeOptions[$employeeId] ?? "ID {$employeeId}";

        $this->results[$key] = [
            'ok'      => true,
            'message' => "Mapeado a {$empName}" . ($logIds->isNotEmpty() ? " · {$logIds->count()} registros en cola" : ''),
        ];

        // Quitar de sugerencias
        $this->suggestions = array_values(
            array_filter($this->suggestions, fn($s) => $s['key'] !== $key)
        );

        Log::info('EmployeeMapping: mapeo aceptado', [
            'client_id'   => $this->client->id,
            'pin'         => $pin,
            'employee_id' => $employeeId,
            'logs'        => $logIds->count(),
        ]);
    }

    public function skip(string $key): void
    {
        $this->suggestions = array_values(
            array_filter($this->suggestions, fn($s) => $s['key'] !== $key)
        );
        unset($this->overrides[$key]);
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Mapeo de empleados</h2>
            <p class="text-sm text-gray-500 mt-0.5">Usuarios biométricos sin asignar en todos los dispositivos de <strong>{{ $client->name }}</strong></p>
        </div>
        <button wire:click="loadSuggestions"
            class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Recargar
        </button>
    </div>

    {{-- Resultados de acciones recientes --}}
    @if(!empty($results))
    <div class="mb-4 space-y-1">
        @foreach($results as $r)
        <div class="flex items-center gap-2 px-4 py-2 bg-emerald-50 border border-emerald-200 rounded-lg text-sm text-emerald-700">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            {{ $r['message'] }}
        </div>
        @endforeach
    </div>
    @endif

    {{-- Tabla de sugerencias --}}
    @if(empty($suggestions))
    <div class="bg-white shadow rounded-lg px-6 py-12 text-center">
        <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-gray-500">Todos los usuarios biométricos están mapeados.</p>
        <p class="text-xs text-gray-400 mt-1">Si agregaste nuevos dispositivos, sube el CSV y recarga.</p>
    </div>
    @else
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <p class="text-sm text-gray-500">{{ count($suggestions) }} usuario(s) sin mapear</p>
            <p class="text-xs text-gray-400">La sugerencia se calcula por similitud de nombre</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">PIN</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre en dispositivo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado sugerido</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Match</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Asignar a</th>
                        <th class="px-4 py-3 w-32"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @foreach($suggestions as $s)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-gray-600">{{ $s['pin'] }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $s['device_name'] }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $s['source_label'] }}</td>
                        <td class="px-4 py-3 text-gray-700">{{ $s['suggested_name'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            @php
                                $score = $s['score'];
                                $color = $score >= 90 ? 'bg-emerald-100 text-emerald-700'
                                       : ($score >= 70 ? 'bg-yellow-100 text-yellow-700'
                                       : 'bg-red-100 text-red-600');
                            @endphp
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $color }}">
                                {{ $score }}%
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <select wire:model="overrides.{{ $s['key'] }}"
                                class="block w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 py-1">
                                <option value="">— Usar sugerencia ({{ $s['suggested_name'] ?? 'ninguna' }}) —</option>
                                @foreach($employeeOptions as $empId => $empName)
                                    <option value="{{ $empId }}">{{ $empName }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 justify-end">
                                <button wire:click="accept('{{ $s['key'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="accept('{{ $s['key'] }}')"
                                    class="px-3 py-1 text-xs font-medium text-white bg-indigo-600 rounded hover:bg-indigo-700 disabled:opacity-50 transition">
                                    Aceptar
                                </button>
                                <button wire:click="skip('{{ $s['key'] }}')"
                                    class="px-3 py-1 text-xs font-medium text-gray-600 bg-white border border-gray-300 rounded hover:bg-gray-50 transition">
                                    Omitir
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
