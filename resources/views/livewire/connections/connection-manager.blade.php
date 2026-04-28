<?php

use App\Models\FactorialConnection;
use App\Models\Client;
use Livewire\Volt\Component;

new class extends Component {
    public bool $showModal = false;
    public bool $editing = false;
    public ?int $editingId = null;

    public string $name = '';
    public ?int $client_id = null;
    public string $oauth_client_id = '';
    public string $oauth_client_secret = '';
    public string $resource_owner_type = 'company';

    public function rules(): array
    {
        return [
            'name'                => 'required|string|max:255',
            'client_id'           => 'required|exists:clients,id',
            'oauth_client_id'     => 'required|string',
            'oauth_client_secret' => 'required|string',
            'resource_owner_type' => 'required|in:company,employee',
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
        $conn = FactorialConnection::findOrFail($id);
        $this->editingId            = $conn->id;
        $this->name                 = $conn->name;
        $this->client_id            = $conn->client_id;
        $this->oauth_client_id      = $conn->oauth_client_id;
        $this->oauth_client_secret  = $conn->oauth_client_secret;
        $this->resource_owner_type  = $conn->resource_owner_type ?? 'company';
        $this->editing   = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        try {
            $data = $this->validate();

            if ($this->editing) {
                FactorialConnection::findOrFail($this->editingId)->update($data);
            } else {
                FactorialConnection::create($data);
            }

            $this->showModal = false;
            $this->resetForm();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ConnectionManager save error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        FactorialConnection::findOrFail($id)->delete();
    }

    public function getTokenStatus(FactorialConnection $conn): array
    {
        if (! $conn->access_token) {
            return ['label' => 'Sin conectar', 'color' => 'bg-gray-100 text-gray-600'];
        }
        if ($conn->expires_at && $conn->expires_at->isPast()) {
            return ['label' => 'Token expirado', 'color' => 'bg-red-100 text-red-700'];
        }
        return ['label' => 'Conectado', 'color' => 'bg-green-100 text-green-700'];
    }

    private function resetForm(): void
    {
        $this->editingId           = null;
        $this->name                = '';
        $this->client_id           = null;
        $this->oauth_client_id     = '';
        $this->oauth_client_secret = '';
        $this->resource_owner_type = 'company';
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
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ $conn->name }}</h3>
                        <p class="text-sm text-gray-500 mt-0.5">{{ $conn->client?->name }}</p>
                    </div>
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $status['color'] }}">
                        {{ $status['label'] }}
                    </span>
                </div>

                <div class="mt-4 space-y-1 text-xs text-gray-500">
                    <div class="flex justify-between">
                        <span>Client ID</span>
                        <span class="font-mono text-gray-700">{{ Str::limit($conn->oauth_client_id, 20) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Tipo</span>
                        <span class="text-gray-700">{{ $conn->resource_owner_type ?? 'company' }}</span>
                    </div>
                    @if($conn->expires_at)
                    <div class="flex justify-between">
                        <span>Expira</span>
                        <span class="{{ $conn->expires_at->isPast() ? 'text-red-600 font-medium' : 'text-gray-700' }}">
                            {{ $conn->expires_at->format('d/m/Y H:i') }}
                        </span>
                    </div>
                    @endif
                    @if($conn->factorial_company_id)
                    <div class="flex justify-between">
                        <span>Company ID</span>
                        <span class="text-gray-700">{{ $conn->factorial_company_id }}</span>
                    </div>
                    @endif
                </div>
            </div>

            <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between gap-2">
                <a href="{{ route('oauth.factorial.redirect', ['connection_id' => $conn->id]) }}"
                   class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 rounded hover:bg-indigo-700 transition">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    {{ $conn->access_token ? 'Reconectar' : 'Conectar con Factorial' }}
                </a>
                <div class="flex gap-3">
                    <button wire:click="openEdit({{ $conn->id }})" class="text-sm text-indigo-600 hover:text-indigo-900">Editar</button>
                    <button wire:click="delete({{ $conn->id }})" wire:confirm="¿Eliminar esta conexión?" class="text-sm text-red-600 hover:text-red-900">Eliminar</button>
                </div>
            </div>
        </div>
        @empty
        <div class="col-span-3 bg-white shadow rounded-lg px-6 py-12 text-center text-sm text-gray-500">
            No hay conexiones configuradas. Crea una para empezar.
        </div>
        @endforelse
    </div>

    {{-- Modal --}}
    @if($showModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">{{ $editing ? 'Editar conexión' : 'Nueva conexión' }}</h3>
                <button wire:click="$set('showModal', false)" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-6 py-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nombre</label>
                    <input wire:model="name" type="text" placeholder="Ej: Conexión principal" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Cliente</label>
                    <select wire:model="client_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Seleccionar...</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                    @error('client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">OAuth Client ID</label>
                    <input wire:model="oauth_client_id" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('oauth_client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">OAuth Client Secret</label>
                    <input wire:model="oauth_client_secret" type="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('oauth_client_secret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Tipo de recurso</label>
                    <select wire:model="resource_owner_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="company">Company</option>
                        <option value="employee">Employee</option>
                    </select>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                <button wire:click="$set('showModal', false)" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Cancelar
                </button>
                <button wire:click="save" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                    {{ $editing ? 'Guardar cambios' : 'Crear conexión' }}
                </button>
            </div>
        </div>
    </div>
    @endif
</div>
