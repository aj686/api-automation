{{-- Editable key/value rows of CollectionShow's form (params, headers, body fields). --}}
<div class="text-sm">
    @if ($rows === [])
        <p class="mb-2 text-zinc-600">{{ $empty }}</p>
    @else
        <table class="mb-2 w-full table-fixed border-collapse">
            <thead class="text-left text-xs text-zinc-500">
                <tr>
                    <th scope="col" class="w-8 pb-1 font-medium"><span class="sr-only">On</span></th>
                    <th scope="col" class="w-2/5 pb-1 font-medium">Key</th>
                    <th scope="col" class="pb-1 font-medium">Value</th>
                    <th scope="col" class="w-10 pb-1"><span class="sr-only">Remove</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $row)
                    <tr wire:key="{{ $list }}-{{ $i }}">
                        <td class="py-0.5 align-middle">
                            <input type="checkbox" wire:model.live="form.{{ $list }}.{{ $i }}.enabled" aria-label="Send row {{ $i + 1 }}">
                        </td>
                        <td class="py-0.5 pr-1">
                            <input type="text" wire:model.live.blur="form.{{ $list }}.{{ $i }}.key" aria-label="Key, row {{ $i + 1 }}" autocomplete="off" spellcheck="false"
                                   @class(['w-full rounded border border-zinc-300 bg-surface px-2 py-1 font-mono text-xs', 'text-zinc-400 line-through' => ! $row['enabled']])>
                        </td>
                        <td class="py-0.5 pr-1">
                            <input type="text" wire:model.live.blur="form.{{ $list }}.{{ $i }}.value" aria-label="Value, row {{ $i + 1 }}" autocomplete="off" spellcheck="false"
                                   @class(['w-full rounded border border-zinc-300 bg-surface px-2 py-1 font-mono text-xs', 'text-zinc-400' => ! $row['enabled']])>
                        </td>
                        <td class="py-0.5 text-right">
                            <button type="button" wire:click="removeRow('{{ $list }}', {{ $i }})" class="rounded px-2 py-1 text-red-700 hover:bg-red-50" aria-label="Remove row {{ $i + 1 }}">✕</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <button type="button" wire:click="addRow('{{ $list }}')" class="rounded px-2 py-1 text-xs text-accent-700 hover:bg-zinc-100">+ {{ $addLabel }}</button>
</div>
