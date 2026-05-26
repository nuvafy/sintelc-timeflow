<?php

use App\Models\Client;
use App\Models\ClientAttendanceConfig;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public Client $client;

    public bool $editing = false;

    // Campos del perfil
    public string $name                = '';
    public string $slug                = '';
    public string $status              = 'active';
    public string $oauth_client_id     = '';
    public string $oauth_client_secret = '';
    public string $hq_address          = '';
    public string $contact_email       = '';

    // Configuración de asistencia
    public string $checkin_id  = '0';
    public string $checkout_id = '1';
    public bool   $has_breaks  = false;
    public string $breakin_id  = '';
    public string $breakout_id = '';

    public function mount(): void
    {
        $this->client->load('attendanceConfig');
        $this->fillFromClient();
    }

    protected function fillFromClient(): void
    {
        $this->name                = $this->client->name;
        $this->slug                = $this->client->slug;
        $this->status              = $this->client->status;
        $this->oauth_client_id     = $this->client->oauth_client_id ?? '';
        $this->oauth_client_secret = $this->client->oauth_client_secret ?? '';
        $this->hq_address          = $this->client->hq_address ?? '';
        $this->contact_email       = $this->client->contact_email ?? '';

        $config = $this->client->attendanceConfig;
        $this->checkin_id  = $config?->checkin_id  ?? '0';
        $this->checkout_id = $config?->checkout_id ?? '1';
        $this->has_breaks  = (bool) ($config?->has_breaks  ?? false);
        $this->breakin_id  = $config?->breakin_id  ?? '';
        $this->breakout_id = $config?->breakout_id ?? '';
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->fillFromClient();
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
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
        ]);

        $this->client->update([
            'name'                => $this->name,
            'slug'                => $this->slug,
            'status'              => $this->status,
            'oauth_client_id'     => $this->oauth_client_id,
            'oauth_client_secret' => $this->oauth_client_secret,
            'hq_address'          => $this->hq_address ?: null,
            'contact_email'       => $this->contact_email ?: null,
        ]);

        ClientAttendanceConfig::updateOrCreate(
            ['client_id' => $this->client->id],
            [
                'checkin_id'  => $this->checkin_id,
                'checkout_id' => $this->checkout_id,
                'has_breaks'  => $this->has_breaks,
                'breakin_id'  => $this->has_breaks ? $this->breakin_id : null,
                'breakout_id' => $this->has_breaks ? $this->breakout_id : null,
            ]
        );

        $this->client->refresh()->load('attendanceConfig');
        $this->editing = false;
    }
}; ?>

<div class="space-y-6">

    {{-- ── Información de la empresa ──────────────────────────────── --}}
    <div class="bg-white shadow rounded-lg overflow-hidden">

        {{-- Header de sección --}}
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-base font-semibold text-gray-900">Información de la empresa</h3>
            <div class="flex items-center gap-2">
                @if($editing)
                    <button wire:click="cancelEdit"
                        class="px-3 py-1.5 text-sm text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
                        Cancelar
                    </button>
                    <button wire:click="save"
                        class="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 transition">
                        Guardar cambios
                    </button>
                @else
                    <button wire:click="$set('editing', true)"
                        class="px-3 py-1.5 text-sm text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
                        Editar
                    </button>
                @endif
            </div>
        </div>

        <div class="px-6 py-5 space-y-6">

            {{-- Nombre, Slug, Estado --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Nombre</p>
                    @if($editing)
                        <input wire:model="name" type="text"
                            class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="text-sm font-semibold text-gray-900">{{ $client->name }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Slug</p>
                    @if($editing)
                        <input wire:model="slug" type="text"
                            class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="text-sm font-mono text-gray-600">{{ $client->slug }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Estado</p>
                    @if($editing)
                        <select wire:model="status"
                            class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="active">Activa</option>
                            <option value="inactive">Inactiva</option>
                        </select>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full {{ $client->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $client->status === 'active' ? 'Activa' : 'Inactiva' }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Email y Dirección --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Email de contacto</p>
                    @if($editing)
                        <input wire:model="contact_email" type="email" placeholder="admin@empresa.com"
                            class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('contact_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="text-sm text-gray-700">{{ $client->contact_email ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Dirección HQ</p>
                    @if($editing)
                        <input wire:model="hq_address" type="text" placeholder="Av. Insurgentes Sur 1234, CDMX"
                            class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('hq_address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="text-sm text-gray-700">{{ $client->hq_address ?: '—' }}</p>
                    @endif
                </div>
            </div>

            {{-- Credenciales OAuth --}}
            <div class="border-t border-gray-100 pt-5">
                <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-4">Credenciales Factorial OAuth</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-gray-500 mb-1">Client ID</p>
                        @if($editing)
                            <input wire:model="oauth_client_id" type="text" autocomplete="off"
                                placeholder="thAYmPF7qXq..."
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('oauth_client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @else
                            <p class="text-sm font-mono text-gray-700">
                                {{ $client->oauth_client_id ? \Illuminate\Support\Str::limit($client->oauth_client_id, 24) : '—' }}
                            </p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 mb-1">Client Secret</p>
                        @if($editing)
                            <input wire:model="oauth_client_secret" type="text" autocomplete="off"
                                placeholder="Client secret..."
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('oauth_client_secret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @else
                            <p class="text-sm font-mono text-gray-600">
                                {{ $client->oauth_client_secret ? '••••••••••••••••' : '—' }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Configuración de asistencia --}}
            <div class="border-t border-gray-100 pt-5">
                <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-4">Configuración de asistencia biométrica</p>
                @if($editing)
                    <p class="text-xs text-gray-400 mb-3">Indica qué ID de status envía el dispositivo para cada tipo de marca.</p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">ID Entrada</label>
                            <input wire:model="checkin_id" type="text" placeholder="0"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('checkin_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">ID Salida</label>
                            <input wire:model="checkout_id" type="text" placeholder="1"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('checkout_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        @if($has_breaks)
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">ID Inicio descanso</label>
                            <input wire:model="breakin_id" type="text" placeholder="2"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('breakin_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">ID Fin descanso</label>
                            <input wire:model="breakout_id" type="text" placeholder="3"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('breakout_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        @endif
                    </div>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input wire:model.live="has_breaks" type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"/>
                        <span class="text-sm text-gray-700">¿Tiene pausas / descansos?</span>
                    </label>
                @else
                    @php $config = $client->attendanceConfig; @endphp
                    <div class="flex flex-wrap gap-6">
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">ID Entrada</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config?->checkin_id ?? '0' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">ID Salida</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config?->checkout_id ?? '1' }}</p>
                        </div>
                        @if($config?->has_breaks)
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">ID Inicio descanso</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config->breakin_id }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">ID Fin descanso</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config->breakout_id }}</p>
                        </div>
                        @endif
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Descansos</p>
                            <p class="text-sm text-gray-700">{{ $config?->has_breaks ? 'Sí' : 'No' }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Conexiones ──────────────────────────────────────────────── --}}
    <livewire:connections.connection-manager :client-filter-id="$client->id" />

</div>
