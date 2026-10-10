<div class="max-w-4xl">
    <h1 class="mb-4 text-lg font-semibold">Projects</h1>

    @if ($projects->isNotEmpty())
        <table class="mb-8 w-full border-collapse rounded border border-zinc-200 bg-white text-sm">
            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium">Name</th>
                    <th scope="col" class="px-3 py-2 font-medium">Environments</th>
                    <th scope="col" class="px-3 py-2 font-medium">Collections</th>
                    <th scope="col" class="px-3 py-2 font-medium">Last run</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($projects as $project)
                    <tr>
                        <th scope="row" class="px-3 py-2 text-left font-medium">
                            <a href="{{ route('projects.show', $project) }}" class="text-blue-700 hover:underline">{{ $project->name }}</a>
                            @if ($project->description)
                                <p class="font-normal text-zinc-500">{{ Str::limit($project->description, 80) }}</p>
                            @endif
                        </th>
                        <td class="px-3 py-2 tabular-nums">{{ $project->environments_count }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ $project->collections_count }}</td>
                        <td class="px-3 py-2">
                            @if ($project->latestRun)
                                <x-status-badge :status="$project->latestRun->status" :no-tests="$project->latestRun->hasNoTests()" />
                            @else
                                <span class="text-zinc-500">Not run yet</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <section aria-labelledby="new-project" class="rounded border border-zinc-200 bg-white p-4">
        <h2 id="new-project" class="mb-3 font-semibold">New project</h2>

        <form wire:submit="create" class="grid max-w-lg gap-3 text-sm">
            <x-field label="Name" for="name" :error="$errors->first('name')">
                <input id="name" type="text" wire:model="name" required maxlength="100" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5">
            </x-field>

            <x-field label="Slug" for="slug" :error="$errors->first('slug')" hint="Used in URLs and by the n8n API. Leave empty to build it from the name.">
                <input id="slug" type="text" wire:model="slug" maxlength="100" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
            </x-field>

            <x-field label="Description" for="description" :error="$errors->first('description')">
                <textarea id="description" wire:model="description" rows="2" maxlength="2000"
                          class="w-full rounded border border-zinc-300 px-2 py-1.5"></textarea>
            </x-field>

            <div>
                <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700">Create project</button>
            </div>
        </form>
    </section>
</div>
