<div class="max-w-4xl">
    <x-flash />

    <h1 class="text-lg font-semibold">{{ $project->name }}</h1>
    <p class="mb-4 font-mono text-xs text-zinc-500">{{ $project->slug }}</p>

    <x-project-tabs :project="$project" active="overview" />

    <dl class="mb-8 grid grid-cols-3 gap-4 text-sm">
        <div class="rounded border border-zinc-200 bg-white p-3">
            <dt class="text-zinc-500">Environments</dt>
            <dd class="mt-1 flex flex-wrap gap-1">
                @forelse ($environments as $environment)
                    <a href="{{ route('projects.environments.show', [$project, $environment]) }}" class="inline-flex items-center gap-1 text-blue-700 hover:underline">{{ $environment->name }} <x-environment-badge :type="$environment->type" /></a>
                @empty
                    <span class="text-zinc-500">None yet</span>
                @endforelse
            </dd>
        </div>
        <div class="rounded border border-zinc-200 bg-white p-3">
            <dt class="text-zinc-500">Collections</dt>
            <dd class="mt-1 tabular-nums">{{ $collectionCount }}</dd>
        </div>
        <div class="rounded border border-zinc-200 bg-white p-3">
            <dt class="text-zinc-500">Latest run</dt>
            <dd class="mt-1">
                @if ($latestRun)
                    <x-status-badge :status="$latestRun->status" :no-tests="$latestRun->hasNoTests()" />
                    <span class="text-zinc-500">{{ $latestRun->created_at->diffForHumans() }} · {{ $runCount }} {{ Str::plural('run', $runCount) }}</span>
                @else
                    <span class="text-zinc-500">Not run yet</span>
                @endif
            </dd>
        </div>
    </dl>

    <section aria-labelledby="edit-project" class="mb-6 rounded border border-zinc-200 bg-white p-4">
        <h2 id="edit-project" class="mb-3 font-semibold">Details</h2>

        <form wire:submit="save" class="grid max-w-lg gap-3 text-sm">
            <x-field label="Name" for="name" :error="$errors->first('name')">
                <input id="name" type="text" wire:model="name" required maxlength="100" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5">
            </x-field>

            <x-field label="Slug" for="slug" :error="$errors->first('slug')" hint="Changing it changes this page's URL and what the n8n API expects.">
                <input id="slug" type="text" wire:model="slug" required maxlength="100" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
            </x-field>

            <x-field label="Description" for="description" :error="$errors->first('description')">
                <textarea id="description" wire:model="description" rows="2" maxlength="2000"
                          class="w-full rounded border border-zinc-300 px-2 py-1.5"></textarea>
            </x-field>

            <div>
                <button type="submit" class="rounded bg-zinc-900 px-3 py-1.5 font-medium text-white hover:bg-zinc-700">Save</button>
            </div>
        </form>
    </section>

    <section aria-labelledby="delete-project" class="rounded border border-red-200 bg-white p-4">
        <h2 id="delete-project" class="mb-1 font-semibold text-red-800">Delete project</h2>
        <p class="mb-3 text-sm text-zinc-700">
            Deletes its environments, their stored credentials, and its collections. Run history is kept, still showing this project's name.
        </p>

        <form wire:submit="delete" class="grid max-w-lg gap-3 text-sm">
            <x-field label="Type {{ $project->name }} to confirm" for="confirmName" :error="$errors->first('confirmName')">
                <input id="confirmName" type="text" wire:model="confirmName" autocomplete="off"
                       class="w-full rounded border border-zinc-300 px-2 py-1.5">
            </x-field>

            <div>
                <button type="submit" class="rounded bg-red-700 px-3 py-1.5 font-medium text-white hover:bg-red-600">Delete project</button>
            </div>
        </form>
    </section>
</div>
