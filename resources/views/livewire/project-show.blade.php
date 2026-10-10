<div class="max-w-4xl">
    <x-flash />

    <h1 class="text-lg font-semibold">{{ $project->name }}</h1>
    <p class="mb-4 font-mono text-xs text-zinc-500">{{ $project->slug }}</p>

    <x-project-tabs :project="$project" active="overview" />

    <section aria-labelledby="run-panel" class="mb-8 rounded border border-zinc-200 bg-white p-4" @if ($hasActiveRun) wire:poll.2s @endif>
        <h2 id="run-panel" class="mb-3 font-semibold">Run tests</h2>

        @if ($environments->isEmpty() || $collections->isEmpty())
            <p class="text-sm text-zinc-600">
                To run, this project needs at least one
                <a href="{{ route('projects.environments', $project) }}" class="text-blue-700 hover:underline">environment</a> and one
                <a href="{{ route('projects.collections', $project) }}" class="text-blue-700 hover:underline">collection</a>.
            </p>
        @else
            <form wire:submit="startRun" class="grid gap-3 text-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <label for="runEnvironmentId" class="mb-1 block font-medium">Environment</label>
                    <select id="runEnvironmentId" wire:model.live="runEnvironmentId" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                        @foreach ($environments as $environment)
                            <option value="{{ $environment->id }}">{{ $environment->name }}{{ $environment->isProduction() ? ' — PRODUCTION' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="runCollectionId" class="mb-1 block font-medium">Collection</label>
                    <select id="runCollectionId" wire:model="runCollectionId" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                        @foreach ($collections as $collection)
                            <option value="{{ $collection->id }}">{{ $collection->name }} ({{ $collection->kind->value }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded bg-zinc-900 px-4 py-1.5 font-medium text-white hover:bg-zinc-700" wire:loading.attr="disabled" wire:target="startRun">
                    <span aria-hidden="true">▶</span> Run
                </button>

                @if ($selectedEnvironment?->isProduction())
                    <div class="sm:col-span-3">
                        <p role="note" class="mb-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-red-900">
                            <span aria-hidden="true">⚠</span> <strong>PRODUCTION</strong> — tests may modify real data.
                        </p>
                        <x-field label="Type {{ $selectedEnvironment->name }} to confirm" for="confirmProduction" :error="$errors->first('confirmProduction')">
                            <input id="confirmProduction" type="text" wire:model="confirmProduction" autocomplete="off" class="w-full max-w-sm rounded border border-zinc-300 px-2 py-1.5">
                        </x-field>
                    </div>
                @endif
            </form>
            @error('run') <p role="alert" class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
        @endif

        @if ($recentRuns->isNotEmpty())
            <h3 class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-zinc-500">Recent runs</h3>
            <ul class="divide-y divide-zinc-100 text-sm">
                @foreach ($recentRuns as $run)
                    <li wire:key="run-{{ $run->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <x-status-badge :status="$run->status" :no-tests="$run->hasNoTests()" />
                        <span>{{ $run->collection_name }} → {{ $run->environment_name }}</span>
                        <span class="tabular-nums text-zinc-600">
                            @if ($run->total_assertions !== null) {{ $run->passed_assertions }}/{{ $run->total_assertions }} passed @endif
                            @if ($run->duration_ms !== null) · {{ number_format($run->duration_ms / 1000, 1) }} s @endif
                        </span>
                        <time datetime="{{ $run->created_at->toIso8601String() }}" class="text-zinc-500">{{ $run->created_at->diffForHumans() }}</time>
                        @if ($run->status->isActive())
                            @if ($run->cancel_requested_at)
                                <span class="text-zinc-600">Stopping…</span>
                            @else
                                <button type="button" wire:click="cancelRun('{{ $run->id }}')" class="ml-auto rounded px-2 py-1 text-xs text-red-700 hover:bg-red-50">Cancel</button>
                            @endif
                        @endif
                        @if ($run->error_message)
                            <p class="w-full text-xs text-red-800">{{ $run->error_message }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-1 text-xs text-zinc-500">{{ $runCount }} {{ Str::plural('run', $runCount) }} in total. Run details arrive in phase 11.</p>
        @endif
    </section>

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
