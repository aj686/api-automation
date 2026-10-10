<div class="max-w-4xl">
    <x-flash />

    <h1 class="text-lg font-semibold">{{ $project->name }}</h1>
    <p class="mb-4 font-mono text-xs text-zinc-500">{{ $project->slug }}</p>

    <x-project-tabs :project="$project" active="environments" />

    @if ($environments->isNotEmpty())
        <table class="mb-8 w-full border-collapse rounded border border-zinc-200 bg-white text-sm">
            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium">Environment</th>
                    <th scope="col" class="px-3 py-2 font-medium">Type</th>
                    <th scope="col" class="px-3 py-2 font-medium">Variables</th>
                    <th scope="col" class="px-3 py-2 font-medium">Automation</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($environments as $environment)
                    <tr>
                        <th scope="row" class="px-3 py-2 text-left font-medium">
                            <a href="{{ route('projects.environments.show', [$project, $environment]) }}" class="text-blue-700 hover:underline">{{ $environment->name }}</a>
                            @if ($environment->base_url_hint)
                                <p class="font-mono text-xs font-normal text-zinc-500">{{ $environment->base_url_hint }}</p>
                            @endif
                        </th>
                        <td class="px-3 py-2"><x-environment-badge :type="$environment->type" /></td>
                        <td class="px-3 py-2 tabular-nums">{{ $environment->variables_count }}</td>
                        <td class="px-3 py-2">{{ $environment->allow_automation ? 'Allowed' : 'Manual only' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="mb-6 text-sm text-zinc-600">No environments yet. Add one for each place this API runs — e.g. Local, Staging, Production.</p>
    @endif

    <section aria-labelledby="new-environment" class="rounded border border-zinc-200 bg-white p-4">
        <h2 id="new-environment" class="mb-3 font-semibold">New environment</h2>

        <form wire:submit="create" class="grid max-w-lg gap-3 text-sm">
            <x-field label="Name" for="name" :error="$errors->first('name')">
                <input id="name" type="text" wire:model="name" required maxlength="100" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5">
            </x-field>

            <x-field label="Slug" for="slug" :error="$errors->first('slug')" hint="Leave empty to build it from the name.">
                <input id="slug" type="text" wire:model="slug" maxlength="100" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
            </x-field>

            <x-field label="Type" for="type" :error="$errors->first('type')" hint="Only production behaves differently: it is excluded from automation until you allow it.">
                <select id="type" wire:model.live="type" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ $option->value }}</option>
                    @endforeach
                </select>
            </x-field>

            @if ($type === 'production')
                <p role="note" class="rounded border border-red-300 bg-red-50 px-3 py-2 text-red-900">
                    <span aria-hidden="true">⚠</span> Production: tests may modify real data. It will be excluded from n8n automation until you allow it on its page.
                </p>
            @endif

            <x-field label="Base URL (for display)" for="base_url_hint" :error="$errors->first('base_url_hint')" hint="Shown next to the name. The value Postman uses comes from a variable such as base_url.">
                <input id="base_url_hint" type="url" wire:model="base_url_hint" maxlength="255" autocomplete="off" placeholder="https://staging.example.com"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
            </x-field>

            <div>
                <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700">Create environment</button>
            </div>
        </form>
    </section>
</div>
