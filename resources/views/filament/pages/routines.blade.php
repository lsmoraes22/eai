<x-filament-panels::page>
    <div class="space-y-4">
        <table class="min-w-full rounded-lg border border-gray-300">
            <thead>
                <tr class="border-b bg-gray-100">
                    <th class="p-2 text-left">Rotina</th>
                    <th class="p-2 text-left">Parâmetros</th>
                    <th class="p-2 text-center">Executar</th>
                </tr>
            </thead>

            <tbody>
            @foreach ($rotinas as $rotina)
                <tr class="border-b align-top">
                    <td class="p-2 font-mono">
                        {{ $rotina }}
                    </td>

                    <td class="p-2 space-y-2">
                        @forelse ($parametros[$rotina] as $param)
                            <x-filament::input
                                wire:model.defer="params.{{ $rotina }}.{{ $param }}"
                                placeholder="--{{ $param }}"
                            />
                        @empty
                            <span class="text-gray-400">Sem parâmetros</span>
                        @endforelse
                    </td>

                    <td class="p-2 text-center">
                        <x-filament::button
                            icon="heroicon-o-play"
                            color="gray"
                            wire:click="execute('{{ $rotina }}')"
                        >
                            Run
                        </x-filament::button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
