<div class="max-w-4xl">
    <x-flash />

    <h1 class="text-lg font-semibold">{{ $project->name }}</h1>
    <p class="mb-4 font-mono text-xs text-zinc-500">{{ $project->slug }}</p>

    <x-project-tabs :project="$project" active="collections" />

    @if ($warnings)
        <div role="alert" class="mb-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <p class="font-semibold"><span aria-hidden="true">⚠</span> Check this collection</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($collections->isEmpty())
        <p class="mb-6 text-sm text-zinc-600">No collections yet. Export one from Postman (⋯ → Export → Collection v2.1) and import it below.</p>
    @else
        <table class="mb-8 w-full border-collapse rounded border border-zinc-200 bg-white text-sm">
            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium">Collection</th>
                    <th scope="col" class="px-3 py-2 font-medium">Kind</th>
                    <th scope="col" class="px-3 py-2 font-medium">File</th>
                    <th scope="col" class="px-3 py-2 font-medium"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($collections as $collection)
                    <tr wire:key="collection-{{ $collection->id }}">
                        <th scope="row" class="px-3 py-2 text-left font-medium">
                            {{ $collection->name }}
                            <p class="font-mono text-xs font-normal text-zinc-500">{{ $collection->slug }}</p>
                        </th>
                        <td class="px-3 py-2">{{ $collection->kind->value }}</td>
                        <td class="px-3 py-2 text-xs text-zinc-600">
                            {{ $collection->original_filename }}
                            <p>{{ $collection->schema_version }} · <span class="font-mono" title="SHA-256 {{ $collection->sha256 }}">{{ substr($collection->sha256, 0, 12) }}</span> · {{ $collection->updated_at->diffForHumans() }}</p>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right text-xs">
                            <button type="button" wire:click="startReplace({{ $collection->id }})" class="rounded px-2 py-1 text-blue-700 hover:bg-zinc-50">Replace file</button>
                            <button type="button" wire:click="delete({{ $collection->id }})" wire:confirm="Delete the collection {{ $collection->name }}? Its run history is kept." class="rounded px-2 py-1 text-red-700 hover:bg-red-50">Delete</button>
                        </td>
                    </tr>
                    @if ($replacingId === $collection->id)
                        <tr wire:key="replace-{{ $collection->id }}">
                            <td colspan="4" class="bg-blue-50/40 px-3 py-3">
                                <form wire:submit="replace" class="flex flex-wrap items-start gap-2 text-sm">
                                    <div>
                                        <label for="replacementFile" class="mb-1 block font-medium">New export of {{ $collection->name }}</label>
                                        <input id="replacementFile" type="file" wire:model="replacementFile" accept=".json,application/json">
                                        @error('replacementFile') <p role="alert" class="mt-1 text-xs font-medium text-red-700">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700" wire:loading.attr="disabled" wire:target="replacementFile,replace">Replace</button>
                                    <button type="button" wire:click="cancelReplace" class="rounded px-3 py-1.5 text-zinc-700 hover:bg-zinc-100">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif

    <section aria-labelledby="import-collection" class="rounded border border-zinc-200 bg-white p-4">
        <h2 id="import-collection" class="mb-1 font-semibold">Import a collection</h2>
        <p class="mb-3 text-sm text-zinc-600">
            Postman v2.0 or v2.1 export, up to 5 MB. Use variables such as <code>@{{base_url}}</code> and <code>@{{api_key}}</code> for anything that differs per environment —
            never real credentials. A copy is stored privately; the upload itself is deleted right away.
        </p>

        <form wire:submit="upload" class="grid max-w-lg gap-3 text-sm">
            <x-field label="Collection file" for="collectionFile" :error="$errors->first('collectionFile')">
                <input id="collectionFile" type="file" wire:model="collectionFile" accept=".json,application/json">
            </x-field>

            <x-field label="Name" for="name" :error="$errors->first('name')" hint="Leave empty to use the name inside the file.">
                <input id="name" type="text" wire:model="name" maxlength="150" autocomplete="off" class="w-full rounded border border-zinc-300 px-2 py-1.5">
            </x-field>

            <x-field label="Slug" for="slug" :error="$errors->first('slug')" hint="n8n selects the collection by this slug. Leave empty to build it from the name.">
                <input id="slug" type="text" wire:model="slug" maxlength="150" autocomplete="off" class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
            </x-field>

            <x-field label="Kind" for="kind" :error="$errors->first('kind')" hint="A label for filtering. Every kind runs the same way.">
                <select id="kind" wire:model="kind" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                    @foreach ($kinds as $option)
                        <option value="{{ $option->value }}">{{ $option->value }}</option>
                    @endforeach
                </select>
            </x-field>

            <div>
                <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700" wire:loading.attr="disabled" wire:target="collectionFile,upload">Import collection</button>
            </div>
        </form>
    </section>
</div>
