<div class="max-w-4xl">
    <x-flash />

    <h1 class="flex items-center gap-2 text-lg font-semibold">
        {{ $environment->name }} <x-environment-badge :type="$environment->type" />
    </h1>
    <p class="mb-4 text-xs text-zinc-500">
        <a href="{{ route('projects.show', $project) }}" class="text-blue-700 hover:underline">{{ $project->name }}</a>
        / <span class="font-mono">{{ $environment->slug }}</span>
    </p>

    <x-project-tabs :project="$project" active="environments" />

    @if ($environment->isProduction())
        <p role="note" class="mb-6 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">
            <span aria-hidden="true">⚠</span> <strong>PRODUCTION</strong> — tests may modify real data.
            Automation is <strong>{{ $environment->allow_automation ? 'allowed' : 'not allowed' }}</strong>.
        </p>
    @endif

    {{-- Variables --}}
    <section aria-labelledby="variables" class="mb-6 rounded border border-zinc-200 bg-white p-4">
        <h2 id="variables" class="mb-1 font-semibold">Variables</h2>
        <p class="mb-3 text-sm text-zinc-600">
            Everything Postman needs for this environment, used as <code>@{{key}}</code> in collections.
            Secret values are encrypted and never shown again after saving.
        </p>

        @if ($variables->isEmpty())
            <p class="mb-4 text-sm text-zinc-500">No variables yet.</p>
        @else
            <table class="mb-4 w-full border-collapse text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                    <tr class="border-b border-zinc-200">
                        <th scope="col" class="py-2 pr-3 font-medium">Key</th>
                        <th scope="col" class="py-2 pr-3 font-medium">Value</th>
                        <th scope="col" class="py-2 pr-3 font-medium">Status</th>
                        <th scope="col" class="py-2 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($variables as $variable)
                        <tr wire:key="variable-{{ $variable->id }}" @class(['text-zinc-400' => ! $variable->enabled])>
                            <th scope="row" class="py-2 pr-3 text-left font-mono font-normal">{{ $variable->key }}</th>
                            <td class="max-w-xs truncate py-2 pr-3 font-mono">
                                @if ($variable->is_secret)
                                    {{-- The mask is all that is ever rendered for a secret. --}}
                                    @if ($variable->getRawOriginal('value') === null)
                                        <span class="font-sans text-zinc-500">empty</span>
                                    @else
                                        <span aria-label="hidden secret value">{{ \App\Models\EnvironmentVariable::MASK }}</span>
                                    @endif
                                @elseif ($variable->value === null)
                                    <span class="font-sans text-zinc-500">empty</span>
                                @else
                                    {{ $variable->value }}
                                @endif
                            </td>
                            <td class="py-2 pr-3 text-xs">
                                @if ($variable->is_secret)
                                    <span class="rounded bg-zinc-800 px-1.5 py-0.5 font-semibold text-white"><span aria-hidden="true">🔒</span> Secret</span>
                                @endif
                                @unless ($variable->enabled)
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-medium text-zinc-700">Disabled</span>
                                @endunless
                            </td>
                            <td class="whitespace-nowrap py-2 text-right text-xs">
                                <button type="button" wire:click="startEdit({{ $variable->id }})" class="rounded px-2 py-1 text-blue-700 hover:bg-zinc-50">Edit</button>
                                <button type="button" wire:click="toggleEnabled({{ $variable->id }})" class="rounded px-2 py-1 text-zinc-700 hover:bg-zinc-50">{{ $variable->enabled ? 'Disable' : 'Enable' }}</button>
                                <button type="button" wire:click="deleteVariable({{ $variable->id }})" wire:confirm="Delete the variable {{ $variable->key }}?" class="rounded px-2 py-1 text-red-700 hover:bg-red-50">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($editingId)
            <form wire:submit="saveEdit" aria-labelledby="edit-variable" class="mb-4 grid gap-3 rounded border border-blue-200 bg-blue-50/40 p-3 text-sm">
                <h3 id="edit-variable" class="font-semibold">Edit variable</h3>
                <x-field label="Key" for="editKey" :error="$errors->first('editKey')">
                    <input id="editKey" type="text" wire:model="editKey" required maxlength="100" autocomplete="off" class="w-full rounded border border-zinc-300 bg-white px-2 py-1.5 font-mono">
                </x-field>
                <x-field label="Value" for="editValue" :error="$errors->first('editValue')"
                         :hint="$editSecret ? 'Leave empty to keep the stored secret. Type a new value to replace it.' : null">
                    <input id="editValue" type="{{ $editSecret ? 'password' : 'text' }}" wire:model="editValue" autocomplete="off"
                           @if ($editSecret) placeholder="unchanged" @endif
                           class="w-full rounded border border-zinc-300 bg-white px-2 py-1.5 font-mono">
                </x-field>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="editSecret"> Secret (encrypted, never shown, redacted from logs)</label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="editEnabled"> Enabled (sent to Postman)</label>
                <div class="flex gap-2">
                    <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700">Save variable</button>
                    <button type="button" wire:click="cancelEdit" class="rounded px-3 py-1.5 text-zinc-700 hover:bg-zinc-100">Cancel</button>
                </div>
            </form>
        @endif

        <form wire:submit="addVariable" aria-label="Add variable" class="grid grid-cols-[1fr_1fr_auto_auto] items-start gap-2 text-sm">
            <div>
                <label for="newKey" class="sr-only">New key</label>
                <input id="newKey" type="text" wire:model="newKey" placeholder="key, e.g. base_url" maxlength="100" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
                @error('newKey') <p role="alert" class="mt-1 text-xs font-medium text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="newValue" class="sr-only">New value</label>
                <input id="newValue" type="{{ $newSecret ? 'password' : 'text' }}" wire:model="newValue" placeholder="value" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
                @error('newValue') <p role="alert" class="mt-1 text-xs font-medium text-red-700">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-1.5 py-1.5"><input type="checkbox" wire:model.live="newSecret"> Secret</label>
            <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700">Add</button>
        </form>
    </section>

    {{-- Import --}}
    <section aria-labelledby="import" class="mb-6 rounded border border-zinc-200 bg-white p-4">
        <h2 id="import" class="mb-1 font-semibold">Import from Postman</h2>
        <p class="mb-3 text-sm text-zinc-600">
            In Postman: Environments → ⋯ → Export, then choose the file here. Existing keys are updated; an empty value in the file keeps the stored one.
            The uploaded file is deleted right after import.
        </p>
        <form wire:submit="import" class="flex flex-wrap items-start gap-2 text-sm">
            <div>
                <label for="importFile" class="sr-only">Postman environment file</label>
                <input id="importFile" type="file" wire:model="importFile" accept=".json,application/json" class="text-sm">
                @error('importFile') <p role="alert" class="mt-1 text-xs font-medium text-red-700">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700" wire:loading.attr="disabled" wire:target="importFile,import">Import</button>
        </form>
    </section>

    {{-- Settings --}}
    <section aria-labelledby="settings" class="mb-6 rounded border border-zinc-200 bg-white p-4">
        <h2 id="settings" class="mb-3 font-semibold">Settings</h2>

        <form wire:submit="saveSettings" class="grid max-w-lg gap-3 text-sm">
            <x-field label="Name" for="name" :error="$errors->first('name')">
                <input id="name" type="text" wire:model="name" required maxlength="100" autocomplete="off" class="w-full rounded border border-zinc-300 px-2 py-1.5">
            </x-field>
            <x-field label="Slug" for="slug" :error="$errors->first('slug')" hint="n8n selects the environment by this slug.">
                <input id="slug" type="text" wire:model="slug" required maxlength="100" autocomplete="off" class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
            </x-field>
            <x-field label="Type" for="type" :error="$errors->first('type')">
                <select id="type" wire:model.live="type" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ $option->value }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field label="Base URL (for display)" for="base_url_hint" :error="$errors->first('base_url_hint')">
                <input id="base_url_hint" type="url" wire:model="base_url_hint" maxlength="255" autocomplete="off" class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
            </x-field>
            <label class="flex items-start gap-2">
                <input type="checkbox" wire:model="allow_automation" class="mt-0.5">
                <span>
                    Allow automation (n8n and the API may start runs here)
                    @if ($type === 'production')
                        <span class="block text-xs text-red-800"><span aria-hidden="true">⚠</span> This is production. Only allow this if every test in its collections is safe to run against real data.</span>
                    @endif
                </span>
            </label>
            <div>
                <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700">Save settings</button>
            </div>
        </form>
    </section>

    {{-- Delete --}}
    <section aria-labelledby="delete-environment" class="rounded border border-red-200 bg-white p-4">
        <h2 id="delete-environment" class="mb-1 font-semibold text-red-800">Delete environment</h2>
        <p class="mb-3 text-sm text-zinc-700">Deletes it and all its variables, including stored credentials. Run history is kept.</p>
        <form wire:submit="delete" class="grid max-w-lg gap-3 text-sm">
            <x-field label="Type {{ $environment->name }} to confirm" for="confirmName" :error="$errors->first('confirmName')">
                <input id="confirmName" type="text" wire:model="confirmName" autocomplete="off" class="w-full rounded border border-zinc-300 px-2 py-1.5">
            </x-field>
            <div>
                <button type="submit" class="rounded bg-red-700 px-3 py-1.5 font-medium text-white hover:bg-red-600">Delete environment</button>
            </div>
        </form>
    </section>
</div>
