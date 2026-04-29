<?php

use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Models\FactorialLocation;
use App\Models\Client;
use App\Services\FactorialService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;

new class extends Component {
    public bool $showModal = false;
    public bool $editing   = false;
    public ?int $editingId = null;
    public ?string $oauthUrl = null;

    public array $syncResults = [];

    public string $name      = '';
    public ?int   $client_id = null;

    public function mount(): void
    {
        $clientId = request('client_id');
        if ($clientId) {
            $this->client_id = (int) $clientId;
            $this->suggestName();
            $this->editing   = false;
            $this->showModal = true;
        }
    }

    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:255',
            'client_id' => 'required|exists:clients,id',
        ];
    }

    public function with(): array
    {
        return [
            'connections' => FactorialConnection::with('client')->get(),
            'clients'     => Client::orderBy('name')->get(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->editing   = false;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $conn            = FactorialConnection::findOrFail($id);
        $this->editingId = $conn->id;
        $this->name      = $conn->name;
        $this->client_id = $conn->client_id;
        $this->editing   = true;
        $this->showModal = true;
    }

    public function updatedClientId($value): void
    {
        if (!$this->editing && $value) {
            $this->suggestName();
        }
    }

    protected function suggestName(): void
    {
        $client = Client::find($this->client_id);
        if ($client) {
            $short = strtolower(str_replace(' ', '_', $client->name));
            $this->name = 'cnx_' . substr($short, 0, 8);
        }
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editing) {
            FactorialConnection::findOrFail($this->editingId)->update($data);
            $this->showModal = false;
            $this->resetForm();
        } else {
            $connection      = FactorialConnection::create(array_merge($data, [
                'resource_owner_type' => 'company',
            ]));
            $this->oauthUrl  = route('oauth.factorial.redirect', ['connection_id' => $connection->id]);
        }
    }

    public function delete(int $id): void
    {
        FactorialConnection::findOrFail($id)->delete();
    }

    public function sync(int $id): void
    {
        $connection = FactorialConnection::with('client')->findOrFail($id);

        if (empty($connection->access_token)) {
            return;
        }

        try {
            $service = new FactorialService($connection);

            // Sync employees
            $response  = $service->getEmployees();
            $employees = $response['data'] ?? [];
            $empCount  = 0;

            foreach ($employees as $employee) {
                if (empty($employee['id'])) continue;

                FactorialEmployee::updateOrCreate(
                    ['factorial_connection_id' => $connection->id, 'factorial_id' => (int) $employee['id']],
                    [
                        'client_id'              => $connection->client_id,
                        'access_id'              => isset($employee['access_id']) ? (int) $employee['access_id'] : null,
                        'first_name'             => $employee['first_name'] ?? null,
                        'last_name'              => $employee['last_name'] ?? null,
                        'full_name'              => $employee['full_name'] ?? null,
                        'email'                  => $employee['email'] ?? null,
                        'login_email'            => $employee['login_email'] ?? null,
                        'company_id'             => isset($employee['company_id']) ? (int) $employee['company_id'] : null,
                        'company_identifier'     => $employee['company_identifier'] ?? null,
                        'location_id'            => isset($employee['location_id']) ? (int) $employee['location_id'] : null,
                        'active'                 => (bool) ($employee['active'] ?? false),
                        'attendable'             => (bool) ($employee['attendable'] ?? false),
                        'is_terminating'         => (bool) ($employee['is_terminating'] ?? false),
                        'terminated_on'          => isset($employee['terminated_on']) ? Carbon::parse($employee['terminated_on']) : null,
                        'factorial_created_at'   => isset($employee['created_at']) ? Carbon::parse($employee['created_at']) : null,
                        'factorial_updated_at'   => isset($employee['updated_at']) ? Carbon::parse($employee['updated_at']) : null,
                        'raw_payload'            => $employee,
                    ]
                );
                $empCount++;
            }

            // Sync locations
            $locResponse = $service->getLocations();
            $locations   = $locResponse['data'] ?? $locResponse;
            $locCount    = 0;

            if (is_array($locations)) {
                foreach ($locations as $location) {
                    if (empty($location['id'])) continue;

                    FactorialLocation::updateOrCreate(
                        ['factorial_connection_id' => $connection->id, 'factorial_location_id' => (int) $location['id']],
                        [
                            'client_id'            => $connection->client_id,
                            'factorial_company_id' => isset($location['company_id']) ? (int) $location['company_id'] : null,
                            'name'                 => $location['name'] ?? "Ubicación {$location['id']}",
                        ]
                    );
                    $locCount++;
                }
            }

            $this->syncResults[$id] = [
                'ok'        => true,
                'employees' => $empCount,
                'locations' => $locCount,
            ];
        } catch (\Throwable $e) {
            Log::error('Error al sincronizar conexión Factorial', [
                'connection_id' => $id,
                'message'       => $e->getMessage(),
            ]);

            $this->syncResults[$id] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function getTokenStatus(FactorialConnection $conn): array
    {
        if (!$conn->access_token) {
            return ['label' => 'Sin conectar', 'color' => 'bg-gray-100 text-gray-600'];
        }
        if ($conn->expires_at && $conn->expires_at->isPast()) {
            return ['label' => 'Token expirado', 'color' => 'bg-red-100 text-red-700'];
        }
        return ['label' => 'Conectado', 'color' => 'bg-green-100 text-green-700'];
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name      = '';
        $this->client_id = null;
        $this->oauthUrl  = null;
        $this->resetValidation();
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800">Conexiones Factorial</h2>
        <button wire:click="openCreate" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nueva conexión
        </button>
    </div>

    {{-- Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        @forelse($connections as $conn)
        @php $status = $this->getTokenStatus($conn); @endphp
        <div class="bg-white shadow rounded-lg overflow-hidden flex flex-col">
            <div class="p-5 flex-1">
                {{-- Header de card --}}
                <div class="flex items-start justify-between">
                    <div class="min-w-0 flex-1 pr-3">
                        <h3 class="text-base font-semibold text-gray-900 truncate">{{ $conn->name }}</h3>
                        <p class="text-sm text-gray-500 mt-0.5 truncate">{{ $conn->client?->name ?? 'Sin empresa asignada' }}</p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $status['color'] }}">
                            {{ $status['label'] }}
                        </span>
                        <a href="{{ route('oauth.factorial.redirect', ['connection_id' => $conn->id]) }}"
                           title="{{ $conn->access_token ? 'Reconectar' : 'Conectar' }}"
                           class="text-gray-400 hover:text-indigo-600 transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                            </svg>
                        </a>
                    </div>
                </div>

                {{-- Factorial ID --}}
                @if($conn->factorial_company_id)
                <div class="mt-4 flex justify-between text-xs text-gray-500">
                    <span>Factorial ID</span>
                    <span class="font-mono text-gray-700">{{ $conn->factorial_company_id }}</span>
                </div>
                @endif
            </div>

            {{-- Sync result banner --}}
            @if(isset($syncResults[$conn->id]))
            @php $r = $syncResults[$conn->id]; @endphp
            @if($r['ok'])
            <div class="px-5 py-2 bg-emerald-50 border-t border-emerald-100 flex items-center justify-between">
                <p class="text-xs text-emerald-700">✓ {{ $r['employees'] }} empleados · {{ $r['locations'] }} ubicaciones</p>
                <a href="{{ route('employees') }}" wire:navigate class="text-xs font-medium text-emerald-700 underline hover:text-emerald-900">
                    Ver empleados →
                </a>
            </div>
            @else
            <div class="px-5 py-2 bg-red-50 border-t border-red-100">
                <p class="text-xs text-red-700">✗ Error: {{ Str::limit($r['error'], 80) }}</p>
            </div>
            @endif
            @endif

            {{-- Footer --}}
            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between gap-2">
                @if($conn->access_token)
                <button wire:click="sync({{ $conn->id }})" wire:loading.attr="disabled" wire:target="sync({{ $conn->id }})"
                    class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 rounded hover:bg-indigo-700 transition disabled:opacity-50">
                    <svg wire:loading wire:target="sync({{ $conn->id }})" class="animate-spin w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    <svg wire:loading.remove wire:target="sync({{ $conn->id }})" class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Sincronizar
                </button>
                @else
                <a href="{{ route('oauth.factorial.redirect', ['connection_id' => $conn->id]) }}"
                   class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 rounded hover:bg-indigo-700 transition">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    Conectar
                </a>
                @endif
                <div class="flex gap-3 items-center">
                    <button wire:click="openEdit({{ $conn->id }})" class="text-sm text-indigo-600 hover:text-indigo-900">Editar</button>
                    <button wire:click="delete({{ $conn->id }})" wire:confirm="¿Eliminar esta conexión?" class="text-sm text-red-600 hover:text-red-900">Eliminar</button>
                </div>
            </div>
        </div>
        @empty
        <div class="col-span-3 bg-white shadow rounded-lg px-6 py-12 text-center text-sm text-gray-500">
            No hay conexiones configuradas. Créalas desde la sección <strong>Empresas</strong>.
        </div>
        @endforelse
    </div>

    {{-- Modal: Crear / Editar --}}
    @if($showModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">{{ $editing ? 'Editar conexión' : 'Nueva conexión' }}</h3>
                <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-6 py-4 space-y-4">
                {{-- Empresa --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">Empresa</label>
                    <select wire:model.live="client_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        {{ $editing ? 'disabled' : '' }}>
                        <option value="">Seleccionar empresa...</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                    @error('client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Nombre --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nombre de la conexión</label>
                    <input wire:model="name" type="text" placeholder="cnx_empresa"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Credenciales read-only si hay empresa --}}
                @if($client_id && !$editing)
                @php $selectedClient = $clients->find($client_id); @endphp
                @if($selectedClient && $selectedClient->oauth_client_id)
                <div class="bg-gray-50 rounded-md px-3 py-2 text-xs text-gray-500 space-y-1">
                    <p class="font-medium text-gray-600">Credenciales de la empresa</p>
                    <div class="flex justify-between">
                        <span>Client ID</span>
                        <span class="font-mono">{{ Str::limit($selectedClient->oauth_client_id, 20) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Client Secret</span>
                        <span class="font-mono">••••••••••••</span>
                    </div>
                </div>
                @else
                <div class="bg-amber-50 border border-amber-200 rounded-md px-3 py-2 text-xs text-amber-700">
                    Esta empresa no tiene credenciales OAuth configuradas. Edítala primero en la sección Empresas.
                </div>
                @endif
                @endif
            </div>

            {{-- URL generada --}}
            @if($oauthUrl)
            <div class="px-6 pb-4">
                <div class="bg-emerald-50 border border-emerald-200 rounded-md p-4">
                    <p class="text-sm font-medium text-emerald-800 mb-2">Conexión creada. Comparte este enlace con el cliente:</p>
                    <div class="flex gap-2">
                        <input type="text" readonly value="{{ $oauthUrl }}"
                            class="flex-1 text-xs font-mono bg-white border border-emerald-300 rounded px-3 py-2 text-gray-700 focus:outline-none"
                            onclick="this.select()"/>
                        <button
                            onclick="navigator.clipboard.writeText('{{ $oauthUrl }}').then(() => this.textContent = '✓').catch(() => {}); return false;"
                            class="px-3 py-2 text-xs font-medium bg-emerald-600 text-white rounded hover:bg-emerald-700 transition whitespace-nowrap">
                            Copiar
                        </button>
                    </div>
                    <p class="text-xs text-emerald-700 mt-2">El cliente hace clic, inicia sesión en Factorial y queda conectado automáticamente.</p>
                </div>
            </div>
            @endif

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="$set('showModal', false); $set('oauthUrl', null)"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    {{ $oauthUrl ? 'Cerrar' : 'Cancelar' }}
                </button>
                @if(!$oauthUrl)
                <button wire:click="save" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                    {{ $editing ? 'Guardar cambios' : 'Crear conexión' }}
                </button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
