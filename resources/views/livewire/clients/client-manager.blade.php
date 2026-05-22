<?php

use App\Models\Client;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\ClientAttendanceConfig;
use App\Models\FactorialConnection;
use App\Models\FactorialLocation;
use Livewire\Volt\Component;
use Illuminate\Support\Str;

new class extends Component {

    // ── Crear / Editar cliente ─────────────────────────────────────
    public bool $showModal = false;
    public bool $editing   = false;
    public ?int $editingId = null;
    public string $name              = '';
    public string $slug              = '';
    public string $status            = 'active';
    public string $oauth_client_id     = '';
    public string $oauth_client_secret = '';
    public string $hq_address          = '';
    public string $contact_email       = '';

    // ── Configuración de asistencia ───────────────────────────────
    public string $checkin_id  = '0';
    public string $checkout_id = '1';
    public bool   $has_breaks  = false;
    public string $breakin_id  = '';
    public string $breakout_id = '';

    // ── Proveedor biométrico ───────────────────────────────────────
    public bool $showProviderModal  = false;
    public ?int $providerClientId   = null;
    public string $provider_name    = '';
    public string $provider_vendor  = 'zkteco';
    public ?int $provider_conn_id   = null;

    // ── Locaciones ─────────────────────────────────────────────────
    public ?int $expandedLocationsClient = null;
    public array $deviceLocationMap      = [];

    // ── Búsqueda ───────────────────────────────────────────────────
    public string $search = '';

    public function updatedSearch(): void { }

    public function with(): array
    {
        $clients = Client::with([
            'factorialConnections',
            'biometricProviders.connection',
            'biometricSources.location',
            'factorialLocations',
        ])
        ->withCount(['biometricSources', 'factorialConnections'])
        ->when($this->search, fn($q) => $q->where('name', 'like', "%{$this->search}%"))
        ->orderBy('name')
        ->get();

        return [
            'clients'     => $clients,
            'connections' => FactorialConnection::orderBy('name')->get(),
        ];
    }

    // ── Cliente CRUD ───────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetClientForm();
        $this->editing   = false;
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $client                    = Client::findOrFail($id);
        $this->editingId           = $client->id;
        $this->name                = $client->name;
        $this->slug                = $client->slug;
        $this->status              = $client->status;
        $this->oauth_client_id     = $client->oauth_client_id ?? '';
        $this->oauth_client_secret = $client->oauth_client_secret ?? '';
        $this->hq_address          = $client->hq_address ?? '';
        $this->contact_email       = $client->contact_email ?? '';

        $config = ClientAttendanceConfig::where('client_id', $id)->first();
        $this->checkin_id  = $config?->checkin_id  ?? '0';
        $this->checkout_id = $config?->checkout_id ?? '1';
        $this->has_breaks  = $config?->has_breaks  ?? false;
        $this->breakin_id  = $config?->breakin_id  ?? '';
        $this->breakout_id = $config?->breakout_id ?? '';

        $this->editing   = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $rules = [
            'name'                => 'required|string|max:255',
            'slug'                => 'required|string|max:255|alpha_dash',
            'status'              => 'required|in:active,inactive',
            'oauth_client_id'     => 'required|string|max:255',
            'oauth_client_secret' => 'required|string|max:500',
            'hq_address'          => 'nullable|string|max:500',
            'contact_email'       => 'nullable|email|max:255',
            'checkin_id'          => 'required|string|max:10',
            'checkout_id'         => 'required|string|max:10',
            'has_breaks'          => 'boolean',
            'breakin_id'          => $this->has_breaks ? 'required|string|max:10' : 'nullable',
            'breakout_id'         => $this->has_breaks ? 'required|string|max:10' : 'nullable',
        ];

        $this->validate($rules);

        $data = [
            'name'                => $this->name,
            'slug'                => $this->slug,
            'status'              => $this->status,
            'oauth_client_id'     => $this->oauth_client_id,
            'oauth_client_secret' => $this->oauth_client_secret,
            'hq_address'          => $this->hq_address ?: null,
            'contact_email'       => $this->contact_email ?: null,
        ];

        if ($this->editing) {
            $client = Client::findOrFail($this->editingId);
            $client->update($data);
        } else {
            $client = Client::create($data);
        }

        // Crear conexión automáticamente si el cliente no tiene una
        if (!$this->editing) {
            $hasConnection = FactorialConnection::where('client_id', $client->id)->exists();
            if (!$hasConnection) {
                FactorialConnection::create([
                    'client_id'           => $client->id,
                    'name'                => 'cnx_' . $this->slug,
                    'resource_owner_type' => 'company',
                ]);
            }
        }

        ClientAttendanceConfig::updateOrCreate(
            ['client_id' => $client->id],
            [
                'checkin_id'  => $this->checkin_id,
                'checkout_id' => $this->checkout_id,
                'has_breaks'  => $this->has_breaks,
                'breakin_id'  => $this->has_breaks ? $this->breakin_id : null,
                'breakout_id' => $this->has_breaks ? $this->breakout_id : null,
            ]
        );

        $this->showModal = false;
        $this->resetClientForm();
    }

    public function delete(int $id): void
    {
        Client::findOrFail($id)->delete();
    }

    public function updatedName(string $value): void
    {
        if (!$this->editing) {
            $this->slug = Str::slug($value);
        }
    }

    // ── Proveedor biométrico ───────────────────────────────────────

    public function openProvider(int $clientId): void
    {
        $client                  = Client::findOrFail($clientId);
        $this->providerClientId  = $clientId;
        $this->provider_name     = 'ZKTeco ' . $client->name;
        $this->provider_vendor   = 'zkteco';
        $this->provider_conn_id  = $client->factorialConnections()->first()?->id;
        $this->showProviderModal = true;
    }

    public function saveProvider(): void
    {
        $this->validate([
            'provider_name'    => 'required|string|max:255',
            'provider_vendor'  => 'required|string',
            'provider_conn_id' => 'nullable|exists:factorial_connections,id',
        ]);

        BiometricProvider::create([
            'client_id'               => $this->providerClientId,
            'factorial_connection_id' => $this->provider_conn_id,
            'vendor'                  => $this->provider_vendor,
            'name'                    => $this->provider_name,
            'status'                  => 'active',
        ]);

        $this->showProviderModal = false;
        $this->providerClientId  = null;
    }

    public function deleteProvider(int $id): void
    {
        BiometricProvider::findOrFail($id)->delete();
    }

    // ── Locaciones ─────────────────────────────────────────────────

    public function toggleLocations(int $clientId): void
    {
        if ($this->expandedLocationsClient === $clientId) {
            $this->expandedLocationsClient = null;
            $this->deviceLocationMap       = [];
        } else {
            $this->expandedLocationsClient = $clientId;
            $sources = BiometricSource::where('client_id', $clientId)->get();
            $this->deviceLocationMap = $sources->pluck('factorial_location_id', 'id')
                ->map(fn($v) => (string) ($v ?? ''))
                ->toArray();
        }
    }

    public function saveDeviceLocations(): void
    {
        foreach ($this->deviceLocationMap as $sourceId => $locationId) {
            BiometricSource::where('id', $sourceId)->update([
                'factorial_location_id' => $locationId ?: null,
            ]);
        }
        $this->expandedLocationsClient = null;
        $this->deviceLocationMap       = [];
    }

    private function resetClientForm(): void
    {
        $this->editingId           = null;
        $this->name                = '';
        $this->slug                = '';
        $this->status              = 'active';
        $this->oauth_client_id     = '';
        $this->oauth_client_secret = '';
        $this->hq_address          = '';
        $this->contact_email       = '';
        $this->checkin_id          = '0';
        $this->checkout_id         = '1';
        $this->has_breaks          = false;
        $this->breakin_id          = '';
        $this->breakout_id         = '';
        $this->resetValidation();
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800">Empresas</h2>
        <div class="flex items-center gap-3">
            <input wire:model.live.debounce.300ms="search" type="text"
                placeholder="Buscar empresa..."
                class="block w-56 rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
            <button wire:click="openCreate" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Nueva empresa
            </button>
        </div>
    </div>

    {{-- Cards --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @forelse($clients as $client)
        <div class="bg-white shadow rounded-lg overflow-hidden flex flex-col">
            {{-- Card header --}}
            <div class="px-5 py-4 flex items-center justify-between border-b border-gray-100">
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-gray-900 truncate">{{ $client->name }}</h3>
                    @if($client->contact_email)
                    <div class="flex items-center gap-1 mt-0.5 text-xs text-gray-400">
                        <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <span>{{ $client->contact_email }}</span>
                    </div>
                    @endif
                    <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $client->slug }}</p>
                </div>
                <div class="flex items-center gap-3 ml-3 flex-shrink-0">
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $client->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $client->status === 'active' ? 'Activa' : 'Inactiva' }}
                    </span>
                </div>
            </div>

            {{-- Body --}}
            <div class="px-5 py-4 space-y-4 flex-1">

                {{-- Dirección HQ --}}
                @if($client->hq_address)
                <div class="flex items-start gap-1.5 text-xs text-gray-500">
                    <svg class="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>{{ $client->hq_address }}</span>
                </div>
                @endif

                {{-- Conexiones Factorial --}}
                <div class="space-y-2">
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider">Conexiones</p>
                    @forelse($client->factorialConnections as $conn)
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-sm text-gray-700">
                            <span class="text-xs text-gray-400">Factorial</span>
                            {{ $conn->name }}
                        </div>
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $conn->access_token ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $conn->access_token ? 'Conectado' : 'Sin conectar' }}
                        </span>
                    </div>
                    @empty
                    <p class="text-sm text-gray-400 italic">Sin conexión Factorial</p>
                    @endforelse
                </div>

            </div>

            {{-- Footer --}}
            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between gap-3">
                <a href="{{ route('clients.records', $client) }}" wire:navigate
                    class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-indigo-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Registros
                </a>
                <div class="flex gap-3">
                    <button wire:click="openEdit({{ $client->id }})" class="text-sm text-indigo-600 hover:text-indigo-900">Editar</button>
                    <button wire:click="delete({{ $client->id }})" wire:confirm="¿Eliminar esta empresa? Se desvinculará de conexiones y dispositivos." class="text-sm text-red-600 hover:text-red-900">Eliminar</button>
                </div>
            </div>
        </div>
        @empty
        <div class="col-span-2 bg-white shadow rounded-lg px-6 py-12 text-center text-sm text-gray-500">
            No hay empresas registradas.
        </div>
        @endforelse
    </div>

    {{-- Modal: Crear / Editar empresa --}}
    @if($showModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4 max-h-screen overflow-y-auto">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">{{ $editing ? 'Editar empresa' : 'Nueva empresa' }}</h3>
                <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-6 py-4 space-y-4">
                {{-- Nombre + Slug --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nombre</label>
                        <input wire:model.live="name" type="text" placeholder="Ej: Grupo MLA"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Slug</label>
                        <input wire:model="slug" type="text"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Dirección HQ + Email --}}
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">
                            Dirección HQ <span class="text-gray-400 font-normal">(opcional)</span>
                        </label>
                        <input wire:model="hq_address" type="text" placeholder="Ej: Av. Insurgentes Sur 1234, CDMX"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('hq_address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">
                            Email de contacto <span class="text-gray-400 font-normal">(opcional)</span>
                        </label>
                        <input wire:model="contact_email" type="email" placeholder="admin@empresa.com"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('contact_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- OAuth credentials --}}
                <div class="border-t border-gray-100 pt-4 space-y-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Credenciales Factorial OAuth</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Client ID</label>
                            <input wire:model="oauth_client_id" type="text" autocomplete="off"
                                placeholder="thAYmPF7qXq..."
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('oauth_client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Client Secret</label>
                            <input wire:model="oauth_client_secret" type="password" autocomplete="new-password"
                                placeholder="••••••••••••••••"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('oauth_client_secret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- Estado --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">Estado</label>
                    <select wire:model="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>

                {{-- Configuración de asistencia --}}
                <div class="border-t border-gray-100 pt-4 space-y-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Configuración de asistencia biométrica</p>
                    <p class="text-xs text-gray-400">Indica qué ID de status envía el dispositivo para cada tipo de marca.</p>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Entrada</label>
                            <input wire:model="checkin_id" type="text" placeholder="0"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('checkin_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Salida</label>
                            <input wire:model="checkout_id" type="text" placeholder="1"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('checkout_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input wire:model.live="has_breaks" type="checkbox" id="has_breaks"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"/>
                        <label for="has_breaks" class="text-sm text-gray-700">¿Tiene pausas / descansos?</label>
                    </div>

                    @if($has_breaks)
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Inicio pausa</label>
                            <input wire:model="breakin_id" type="text" placeholder="2"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('breakin_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Fin pausa</label>
                            <input wire:model="breakout_id" type="text" placeholder="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('breakout_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="$set('showModal', false)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="save" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                    {{ $editing ? 'Guardar cambios' : 'Crear empresa' }}
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Modal: Agregar proveedor biométrico --}}
    @if($showProviderModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">Agregar proveedor biométrico</h3>
                <button wire:click="$set('showProviderModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="px-6 py-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nombre del proveedor</label>
                    <input wire:model="provider_name" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('provider_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Marca del equipo</label>
                    <select wire:model="provider_vendor" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="zkteco">ZKTeco</option>
                        <option value="hikvision">Hikvision</option>
                        <option value="suprema">Suprema</option>
                        <option value="other">Otro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Conexión Factorial <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <select wire:model="provider_conn_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Sin conexión</option>
                        @foreach($connections as $conn)
                            <option value="{{ $conn->id }}">{{ $conn->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                <button wire:click="$set('showProviderModal', false)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="saveProvider" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                    Agregar proveedor
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
