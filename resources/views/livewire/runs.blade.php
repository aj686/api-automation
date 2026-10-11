<div @class(['max-w-4xl' => $project]) @if ($hasActiveRun) wire:poll.2s @endif>
    @if ($project)
        <h1 class="text-lg font-semibold">{{ $project->name }}</h1>
        <p class="mb-4 font-mono text-xs text-zinc-500">{{ $project->slug }}</p>

        <x-project-tabs :project="$project" active="runs" />
    @else
        <h1 class="mb-4 text-lg font-semibold">Runs</h1>
    @endif

    <div class="mb-3 flex flex-wrap items-end gap-3 text-sm">
        <fieldset>
            <legend class="sr-only">Status</legend>
            <div class="inline-flex rounded border border-zinc-300 bg-surface">
                @foreach (\App\Livewire\Runs::FILTERS as $key => [$label])
                    <label @class([
                        'cursor-pointer border-zinc-300 px-3 py-1.5 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-accent-600 [&:not(:first-child)]:border-l',
                        'bg-primary font-medium text-white' => $status === $key,
                        'text-zinc-700 hover:bg-zinc-100' => $status !== $key,
                    ])>
                        <input type="radio" name="status" value="{{ $key }}" wire:model.live="status" class="sr-only">{{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        @unless ($project)
            <div>
                <label for="projectFilter" class="sr-only">Project</label>
                <select id="projectFilter" wire:model.live="projectFilter" class="rounded border border-zinc-300 bg-surface px-2 py-1.5">
                    <option value="">All projects</option>
                    @foreach ($projects as $option)
                        <option value="{{ $option->slug }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
    </div>

    @if ($runs->isEmpty())
        <div class="rounded border border-dashed border-zinc-300 bg-surface p-6 text-sm text-zinc-600">
            @if ($status === 'all' && $projectFilter === '')
                <p class="font-medium text-zinc-900">No runs yet.</p>
                <p class="mt-1">Start one from a project's overview page.</p>
            @else
                <p>No runs match this filter.</p>
            @endif
        </div>
    @else
        <table class="w-full border-collapse overflow-hidden rounded border border-zinc-200 bg-surface text-sm">
            <caption class="sr-only">Runs, newest first</caption>
            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium">Status</th>
                    @unless ($project)
                        <th scope="col" class="px-3 py-2 font-medium">Project</th>
                    @endunless
                    <th scope="col" class="px-3 py-2 font-medium">Collection → Environment</th>
                    <th scope="col" class="px-3 py-2 font-medium">Passed</th>
                    <th scope="col" class="px-3 py-2 font-medium">Duration</th>
                    <th scope="col" class="px-3 py-2 font-medium">Started</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($runs as $run)
                    <tr wire:key="run-{{ $run->id }}">
                        <td class="px-3 py-2"><x-status-badge :status="$run->status" :no-tests="$run->hasNoTests()" /></td>
                        @unless ($project)
                            <td class="px-3 py-2">{{ $run->project_name }}</td>
                        @endunless
                        <td class="px-3 py-2">
                            <a href="{{ route('runs.show', $run) }}" class="text-accent-700 hover:underline">{{ $run->collection_name }} → {{ $run->environment_name }}</a>
                            @if (\App\Enums\EnvironmentType::tryFrom($run->environment_type)?->isProduction())
                                <x-environment-badge :type="$run->environment_type" class="ml-1" />
                            @endif
                            @if ($run->trigger === \App\Enums\RunTrigger::Api)
                                <span class="ml-1 text-xs text-zinc-500">via API</span>
                            @endif
                        </td>
                        {{-- NULL counts are "unknown", shown as a dash, never as 0. --}}
                        <td class="px-3 py-2 tabular-nums">
                            {{ $run->total_assertions === null ? '—' : $run->passed_assertions.'/'.$run->total_assertions }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ $run->duration_ms === null ? '—' : number_format($run->duration_ms / 1000, 1).' s' }}
                        </td>
                        <td class="px-3 py-2 text-zinc-600">
                            <time datetime="{{ $run->created_at->toIso8601String() }}" title="{{ $run->created_at->format('Y-m-d H:i:s T') }}">{{ $run->created_at->diffForHumans() }}</time>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-3">{{ $runs->links() }}</div>
    @endif
</div>
