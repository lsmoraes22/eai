<x-filament::page>

    <h2 class="text-xl font-bold mb-4">
        📁 Explorador de Arquivos – {{ $currentPath }}
    <x-filament::button wire:click="goBack" color="gray">◀</x-filament::button>
    </h2>
    @php
        $items = $this->getItems();
    @endphp

    <div class="mt-6 space-y-4">

        {{-- Diretórios --}}
        <h3 class="font-semibold">Pastas</h3>
        <ul class="border rounded p-2 bg-gray-50">
            @forelse ($items['directories'] as $dir)
                <li class="py-1">
                    <button wire:click="enterDirectory('{{ $dir }}')" class="text-blue-600 hover:underline ">
                        📂 {{ $dir }}
                    </button>
                </li>
            @empty
                <li class="text-gray-500 italic">Nenhuma pasta encontrada.</li>
            @endforelse
        </ul>
        {{-- Arquivos --}}
        <h3 class="font-semibold mt-4">Arquivos</h3>
        <ul class="border rounded p-2 bg-gray-50">
            @forelse ($items['files'] as $file)
                <li class="py-1">
                    📄 {{ $file }}
			<button wire:click="previewFile('{{ $file }}')" class="text-blue-600 hover:underline">
			   👁️ Preview
			</button>
			<button wire:click="deleteFile('{{ $file }}')" class="text-red-600 hover:underlineborder " >
			🗑️  Deletar
			</button>
                </li>
            @empty
                <li class="text-gray-500 italic">Nenhum arquivo encontrado.</li>
            @endforelse
        </ul>

    </div>

@if($previewType === 'image')
    <div class="mt-4">
        <img src="{{ $previewContent }}" class="max-w-full rounded shadow">
    </div>
@endif

@if($previewType === 'text')
    <div class="mt-4 bg-gray-100 p-4 rounded shadow max-h-96 overflow-auto">
<pre>{{ $previewContent }}</pre>
    </div>
@endif

@if($previewType === 'none')
    <div class="mt-4 text-gray-600">{{ $previewContent }}</div>
@endif


</x-filament::page>

