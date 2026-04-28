<?php

use App\Models\Client;
use App\Models\BiometricProvider;
use App\Models\FactorialConnection;
use Livewire\Volt\Component;
use Illuminate\Support\Str;

new class extends Component {

    // ── Crear / Editar cliente ─────────────────────────────────────
    public bool $showModal = false;
    public bool $editing   = false;
    public ?int $editingId = null;
    public string $name   = '';
    public string $slug   = '';
    public string $status = 'active';

    // ── Proveedor biométrico ───────────────────────────────────────
    public bool $showProviderModal  = false;
    public ?int $providerClientId   = null;
    public string $provider_name    = '';
    public string $provider_vendor  = 'zkteco';
    public ?int $provider_conn_id   = null;

    public function with(): array
    {
        $clients = Client::with([
            'factorialConnections',
            'biometricProviders.connection',
            'biometricSources',
        ])
        ->withCount(['biometricSources', 'factorialConnections'])
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
        $client          = Client::findOrFail($id);
        $this->editingId = $client->id;
        $this->name      = $client->name;
        $this->slug      = $client->slug;
        $this->status    = $client->status;
        $this->editing   = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'   => 'required|string|max:255',
            'slug'   => 'required|string|max:255|alpha_dash',
            'status' => 'required|in:active,inactive',
        ]);

        if ($this->editing) {
            Client::findOrFail($this->editingId)->update([
                'name'   => $this->name,
                'slug'   => $this->slug,
                'status' => $this->status,
            ]);
        } else {
            Client::create([
                'name'   => $this->name,
                'slug'   => $this->slug,
                'status' => $this->status,
            ]);
        }

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

    private function resetClientForm(): void
    {
        $this->editingId = null;
        $this->name      = '';
        $this->slug      = '';
        $this->status    = 'active';
        $this->resetValidation();
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800">Empresas</h2>
        <button wire:click="openCreate" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Nueva empresa
        </button>
    </div>

    {{-- Cards --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @forelse($clients as $client)
        <div class="bg-white shadow rounded-lg overflow-hidden">
            {{-- Card header --}}
            <div class="px-5 py-4 flex items-center justify-between border-b border-gray-100">
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-gray-900 truncate">{{ $client->name }}</h3>
                    <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $client->slug }}</p>
                </div>
                <span class="ml-3 px-2 py-0.5 text-xs font-semibold rounded-full flex-shrink-0 {{ $client->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                    {{ $client->status === 'active' ? 'Activa' : 'Inactiva' }}
                </span>
            </div>

            {{-- Body --}}
            <div class="px-5 py-4 space-y-4">

                {{-- Conexión Factorial --}}
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">Conexión Factorial</p>
                    @forelse($client->factorialConnections as $conn)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-700">{{ $conn->name }}</span>
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $conn->access_token ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $conn->access_token ? 'Conectado' : 'Sin conectar' }}
                        </span>
                    </div>
                    @empty
                    <p class="text-sm text-gray-400 italic">Sin conexión asignada</p>
                    @endforelse
                </div>

                {{-- Proveedor biométrico --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-medium text-gray-400 uppercase tracking-wider">Proveedor biométrico</p>
                        @if($client->biometricProviders->isEmpty())
                        <button wire:click="openProvider({{ $client->id }})"
                            class="inline-flex items-center text-xs font-medium text-indigo-600 hover:text-indigo-800">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Agregar
                        </button>
                        @endif
                    </div>
                    @forelse($client->biometricProviders as $provider)
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-sm text-gray-700">{{ $provider->name }}</span>
                            <span class="ml-2 text-xs text-gray-400 font-mono">{{ $provider->vendor }}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $provider->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $provider->status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                            <button wire:click="deleteProvider({{ $provider->id }})" wire:confirm="¿Eliminar este proveedor?" class="text-xs text-red-500 hover:text-red-700">Eliminar</button>
                        </div>
                    </div>
                    @empty
                    <p class="text-sm text-gray-400 italic">Sin proveedor configurado</p>
                    @endforelse
                </div>

                {{-- Stats --}}
                <div class="flex gap-6 pt-1 border-t border-gray-100">
                    <div class="text-center">
                        <p class="text-lg font-semibold text-gray-800">{{ $client->biometric_sources_count }}</p>
                        <p class="text-xs text-gray-400">Dispositivos</p>
                    </div>
                    <div class="text-center">
                        <p class="text-lg font-semibold text-gray-800">{{ $client->factorial_connections_count }}</p>
                        <p class="text-xs text-gray-400">Conexiones</p>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
                <button wire:click="openEdit({{ $client->id }})" class="text-sm text-indigo-600 hover:text-indigo-900">Editar</button>
                <button wire:click="delete({{ $client->id }})" wire:confirm="¿Eliminar esta empresa? Se desvinculará de conexiones y dispositivos." class="text-sm text-red-600 hover:text-red-900">Eliminar</button>
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
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">{{ $editing ? 'Editar empresa' : 'Nueva empresa' }}</h3>
                <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-6 py-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nombre</label>
                    <input wire:model.live="name" type="text" placeholder="Ej: Prosys" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Slug</label>
                    <input wire:model="slug" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Estado</label>
                    <select wire:model="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
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
