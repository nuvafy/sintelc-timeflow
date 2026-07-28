<?php

use App\Models\BiometricSource;
use App\Models\FactorialEmployee;
use App\Services\DeviceOnboardingService;
use App\Services\DeviceInventoryService;
use App\Services\DeviceReconciliationService;
use App\Services\DeviceSyncBatchService;
use Livewire\Volt\Component;

new class extends Component {
    public int $deviceId;
    public array $decisions = [];
    public ?int $loadedSnapshotId = null;
    public ?string $message = null;

    public function mount(BiometricSource $device): void
    {
        $this->authorizeDevice($device);
        $this->deviceId = $device->id;
        $this->prepareDecisions();
    }

    public function with(): array
    {
        $device = $this->device();
        $analysis = app(DeviceReconciliationService::class)->analyze($device);

        return [
            'device' => $device,
            'analysis' => $analysis,
            'inventoryCommand' => $device->commands()
                ->where('command_type', 'query_users')
                ->latest('id')
                ->first(),
            'successfulInventoryCommand' => $device->commands()
                ->where('command_type', 'query_users')
                ->where('status', 'acknowledged')
                ->latest('id')
                ->first(),
            'employees' => FactorialEmployee::query()
                ->where('client_id', $device->client_id)
                ->where('active', true)
                ->orderBy('full_name')
                ->get(['id', 'factorial_id', 'full_name']),
        ];
    }

    public function requestInventory(): void
    {
        app(DeviceOnboardingService::class)->requestInventory($this->device());
        $this->message = 'Solicitud enviada. El inventario aparecerá cuando el dispositivo haga su próximo ping.';
    }

    public function refreshSnapshot(): void
    {
        $analysis = app(DeviceReconciliationService::class)->analyze($this->device());
        if ($analysis['snapshot_id'] && $analysis['snapshot_id'] !== $this->loadedSnapshotId) {
            $this->prepareDecisions($analysis);
            $this->message = 'Inventario recibido. Ya puedes revisar las coincidencias.';
        }
    }

    public function confirmEmptyInventory(): void
    {
        $device = $this->device();
        $command = $device->commands()
            ->where('command_type', 'query_users')
            ->where('status', 'acknowledged')
            ->latest('id')
            ->first();
        abort_unless($command, 422);

        app(DeviceInventoryService::class)->capture($device, [], 'confirmed_empty', [
            'command_id' => $command->id,
            'confirmed_by' => auth()->id(),
        ]);
        $this->prepareDecisions();
        $this->message = 'Equipo confirmado sin usuarios. Ya puedes decidir cuáles empleados de Factorial enviar.';
    }

    public function apply(): void
    {
        $device = $this->device();
        $analysis = app(DeviceReconciliationService::class)->analyze($device);
        abort_unless($analysis['snapshot_id'], 422);

        $decisions = collect($this->decisions)
            ->filter(fn(array $decision) => !(
                ($decision['action'] ?? '') === 'ignore'
                && trim((string) ($decision['pin'] ?? '')) === ''
            ))
            ->values()
            ->all();

        $batch = app(DeviceSyncBatchService::class)->create(
            $device,
            $decisions,
            auth()->user(),
            'onboarding',
            'wizard'
        );

        $this->message = $batch->pending_items > 0
            ? "Se enviaron {$batch->pending_items} cambio(s). Se confirmarán con un nuevo inventario."
            : 'La conciliación quedó guardada y el dispositivo está listo.';
    }

    private function prepareDecisions(?array $analysis = null): void
    {
        $analysis ??= app(DeviceReconciliationService::class)->analyze($this->device());
        $this->loadedSnapshotId = $analysis['snapshot_id'];
        $this->decisions = collect($analysis['rows'])->map(function (array $row) {
            $action = match ($row['case']) {
                'matched_factorial' => 'map_factorial',
                'matched_local' => 'keep_local',
                'device_only_suggested' => 'map_factorial',
                'device_only' => 'keep_local',
                'factorial_mapped_missing_on_device' => 'add_factorial',
                default => 'ignore',
            };

            return [
                'case' => $row['case'],
                'action' => $action,
                'pin' => (string) ($row['pin'] ?? ''),
                'name' => (string) ($row['reported_name'] ?? $row['factorial_name'] ?? ''),
                'factorial_employee_id' => $row['factorial_employee_id']
                    ?? $row['suggested_factorial_employee_id']
                    ?? null,
            ];
        })->all();
    }

    private function device(): BiometricSource
    {
        $device = BiometricSource::findOrFail($this->deviceId);
        $this->authorizeDevice($device);

        return $device;
    }

    private function authorizeDevice(BiometricSource $device): void
    {
        $user = auth()->user();
        abort_unless($user && $device->client_id, 403);
        abort_if($user->isClient() && (int) $user->client_id !== (int) $device->client_id, 403);
    }
}; ?>

<div wire:poll.8s="refreshSnapshot" class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <a href="{{ auth()->user()->isAdmin() ? route('devices') : route('client.devices') }}" wire:navigate class="text-sm text-indigo-600 hover:text-indigo-800">← Volver a dispositivos</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900">Configurar {{ $device->name }}</h1>
            <p class="text-sm text-gray-500">Compara lo que existe en el biométrico con Factorial antes de enviar cambios.</p>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full bg-gray-200 px-2 py-1 text-gray-700">
                    Protocolo: {{ config('biometric-protocols.profiles.' . ($device->push_protocol_profile ?: 'attendance_push') . '.label', 'Pendiente de detectar') }}
                </span>
                @if($device->reported_user_count !== null)
                    <span class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-700">
                        {{ $device->reported_user_count }} usuario(s) reportados
                    </span>
                @endif
                @if($device->device_firmware)
                    <span class="font-mono text-gray-400">{{ $device->device_firmware }}</span>
                @endif
            </div>
        </div>
        <button wire:click="requestInventory" wire:loading.attr="disabled"
            class="inline-flex justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
            Consultar dispositivo
        </button>
    </div>

    @if($message)
        <div class="rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">{{ $message }}</div>
    @endif
    @error('decisions') <div class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror
    @error('decisions.*') <div class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div> @enderror

    @if(!$analysis['snapshot_id'] && $inventoryCommand)
        @php
            $waitedTooLong = $inventoryCommand->status === 'acknowledged'
                && $inventoryCommand->acknowledged_at?->lt(now()->subMinutes(2));
        @endphp
        <div class="rounded-md px-4 py-3 text-sm {{ $waitedTooLong ? 'bg-amber-50 text-amber-800' : 'bg-gray-100 text-gray-700' }}">
            @if(in_array($inventoryCommand->status, ['pending', 'sent'], true))
                Esperando que el dispositivo recoja la consulta. Último ping: {{ $device->last_ping_at?->diffForHumans() ?? 'sin conexión' }}.
            @elseif($waitedTooLong)
                El dispositivo recibió la consulta, pero no devolvió el inventario. Puedes intentar nuevamente; se usará el protocolo correspondiente a {{ $device->device_name ?: 'este modelo' }}.
            @elseif($inventoryCommand->status === 'acknowledged')
                El dispositivo recibió la consulta. Esperando que envíe su inventario de usuarios…
            @else
                El dispositivo rechazó la consulta. Intenta nuevamente o revisa su conexión.
            @endif
        </div>
    @endif

    @if(!$analysis['snapshot_id'] && $successfulInventoryCommand)
        <div class="flex items-center justify-between gap-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-sm text-emerald-800">Si el dispositivo es nuevo y no tiene usuarios, es normal que no envíe ninguna fila.</p>
            <button wire:click="confirmEmptyInventory" wire:confirm="¿Confirmas que este dispositivo no tiene usuarios?"
                class="shrink-0 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                Confirmar equipo vacío
            </button>
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">En el equipo</p><p class="text-2xl font-semibold">{{ collect($analysis['rows'])->whereNotNull('reported_name')->count() }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Ya vinculados</p><p class="text-2xl font-semibold text-emerald-600">{{ ($analysis['summary']['matched_factorial'] ?? 0) + ($analysis['summary']['matched_local'] ?? 0) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Requieren decisión</p><p class="text-2xl font-semibold text-amber-600">{{ ($analysis['summary']['device_only'] ?? 0) + ($analysis['summary']['device_only_suggested'] ?? 0) }}</p></div>
        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Sólo en Factorial</p><p class="text-2xl font-semibold text-indigo-600">{{ $analysis['summary']['factorial_only'] ?? 0 }}</p></div>
    </div>

    @if(!$analysis['snapshot_id'])
        <div class="rounded-lg border-2 border-dashed border-gray-300 bg-white px-6 py-12 text-center">
            <h2 class="font-semibold text-gray-900">Primero consulta el dispositivo</h2>
            <p class="mt-1 text-sm text-gray-500">No se enviará ningún usuario durante esta consulta.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-5 py-4">
                <h2 class="font-semibold text-gray-900">Revisa cada persona</h2>
                <p class="text-xs text-gray-500">Inventario recibido {{ $analysis['captured_at']?->diffForHumans() }}.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">PIN</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Persona</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Qué hacer</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Empleado Factorial</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                    @foreach($decisions as $index => $decision)
                        <tr wire:key="decision-{{ $loadedSnapshotId }}-{{ $index }}">
                            <td class="px-4 py-3"><input wire:model="decisions.{{ $index }}.pin" class="w-28 rounded-md border-gray-300 text-sm font-mono" placeholder="PIN"></td>
                            <td class="px-4 py-3"><input wire:model="decisions.{{ $index }}.name" class="w-full min-w-48 rounded-md border-gray-300 text-sm" placeholder="Nombre"></td>
                            <td class="px-4 py-3">
                                <select wire:model.live="decisions.{{ $index }}.action" class="rounded-md border-gray-300 text-sm">
                                    @if(($decision['case'] ?? '') === 'factorial_only')
                                        <option value="ignore">No enviar por ahora</option>
                                        <option value="add_factorial">Enviar al equipo</option>
                                    @else
                                        <option value="map_factorial">Vincular con Factorial</option>
                                        <option value="keep_local">Conservar sólo local</option>
                                        <option value="ignore">Ignorar</option>
                                    @endif
                                </select>
                            </td>
                            <td class="px-4 py-3">
                                @if(in_array($decision['action'], ['map_factorial', 'add_factorial'], true))
                                    <select wire:model="decisions.{{ $index }}.factorial_employee_id" class="w-full min-w-56 rounded-md border-gray-300 text-sm">
                                        <option value="">Selecciona una persona</option>
                                        @foreach($employees as $employee)
                                            <option value="{{ $employee->id }}">{{ $employee->full_name }} ({{ $employee->factorial_id }})</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-xs text-gray-400">No se sincronizará</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-5 py-4">
                <p class="text-xs text-gray-500">Los cambios nuevos se verifican contra el equipo antes de darse por terminados.</p>
                <button wire:click="apply" wire:loading.attr="disabled" wire:confirm="¿Aplicar estas decisiones?"
                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                    Aplicar decisiones
                </button>
            </div>
        </div>
    @endif
</div>
