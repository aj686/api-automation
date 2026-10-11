@php
    $environmentType = \App\Enums\EnvironmentType::tryFrom($run->environment_type);
    $seconds = fn (?int $ms) => $ms === null ? '—' : number_format($ms / 1000, 1).' s';
    $count = fn (?int $value) => $value === null ? '—' : $value;
    $at = fn ($time) => $time?->format('Y-m-d H:i:s T') ?? '—';
@endphp

<div class="max-w-5xl" @if ($run->status->isActive()) wire:poll.2s @endif>
    <p class="mb-2 text-sm">
        <a href="{{ $run->project ? route('projects.runs', $run->project) : route('runs') }}" class="text-blue-700 hover:underline">← {{ $run->project ? $run->project->name.' runs' : 'All runs' }}</a>
    </p>

    <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
        <h1 class="text-lg font-semibold">{{ $run->collection_name }} → {{ $run->environment_name }}</h1>
        <x-status-badge :status="$run->status" :no-tests="$run->hasNoTests()" />
        @if ($environmentType?->isProduction())
            <x-environment-badge :type="$environmentType" />
        @endif

        @if ($run->status->isActive())
            @if ($run->cancel_requested_at)
                <span class="ml-auto text-sm text-zinc-600">Stopping…</span>
            @else
                <button type="button" wire:click="cancelRun" class="ml-auto rounded px-3 py-1.5 text-sm text-red-700 ring-1 ring-inset ring-red-300 hover:bg-red-50">Cancel run</button>
            @endif
        @endif
    </div>

    @if ($run->error_message)
        <p role="alert" class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <span aria-hidden="true">⚠</span>
            {{ $run->error_message }}
            @if ($run->error_code) <span class="font-mono text-xs text-amber-800">({{ $run->error_code }})</span> @endif
        </p>
    @endif

    @if ($run->hasNoTests())
        <p role="note" class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <span aria-hidden="true">⚠</span> Passed with zero assertions — this run checked nothing. Add tests to the collection.
        </p>
    @endif

    <section aria-labelledby="run-summary" class="mb-6 rounded border border-zinc-200 bg-white p-4">
        <h2 id="run-summary" class="sr-only">Summary</h2>

        {{-- NULL is "unknown" and shown as a dash, never as 0 (plan section 4). --}}
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-zinc-500">Assertions</dt><dd class="text-base font-semibold tabular-nums">{{ $count($run->total_assertions) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">Passed</dt><dd class="text-base font-semibold tabular-nums text-green-800">{{ $count($run->passed_assertions) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">Failed</dt><dd class="text-base font-semibold tabular-nums text-red-800">{{ $count($run->failed_assertions) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">Duration</dt><dd class="text-base font-semibold tabular-nums">{{ $seconds($run->duration_ms) }}</dd></div>

            <div><dt class="text-xs text-zinc-500">Project</dt><dd>
                @if ($run->project)
                    <a href="{{ route('projects.show', $run->project) }}" class="text-blue-700 hover:underline">{{ $run->project_name }}</a>
                @else
                    {{ $run->project_name }} <span class="text-xs text-zinc-500">(deleted)</span>
                @endif
            </dd></div>
            <div><dt class="text-xs text-zinc-500">Environment</dt><dd>{{ $run->environment_name }} <span class="text-xs uppercase text-zinc-500">{{ $run->environment_type }}</span>@unless ($run->environment) <span class="text-xs text-zinc-500">(deleted)</span>@endunless</dd></div>
            <div><dt class="text-xs text-zinc-500">Collection</dt><dd>{{ $run->collection_name }}@unless ($run->collection) <span class="text-xs text-zinc-500">(deleted)</span>@endunless</dd></div>
            <div><dt class="text-xs text-zinc-500">Requests</dt><dd class="tabular-nums">{{ $count($run->total_requests) }}</dd></div>

            <div><dt class="text-xs text-zinc-500">Queued</dt><dd class="tabular-nums"><time datetime="{{ $run->created_at->toIso8601String() }}">{{ $at($run->created_at) }}</time></dd></div>
            <div><dt class="text-xs text-zinc-500">Started</dt><dd class="tabular-nums">{{ $at($run->started_at) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">Finished</dt><dd class="tabular-nums">{{ $at($run->finished_at) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">Triggered by</dt><dd>{{ $run->trigger === \App\Enums\RunTrigger::Api ? 'API' : 'Manual' }}</dd></div>

            <div><dt class="text-xs text-zinc-500">Exit code</dt><dd class="font-mono">{{ $count($run->exit_code) }}</dd></div>
            <div><dt class="text-xs text-zinc-500">Postman CLI</dt><dd class="font-mono">{{ $run->cli_version ?? '—' }}</dd></div>
            <div class="col-span-2"><dt class="text-xs text-zinc-500">Run ID</dt><dd class="font-mono text-xs">{{ $run->id }}</dd></div>
        </dl>
    </section>

    <section aria-labelledby="run-results" class="mb-6">
        <h2 id="run-results" class="mb-2 font-semibold">Results</h2>

        @if ($counts['all'] === 0)
            <p class="rounded border border-dashed border-zinc-300 bg-white p-4 text-sm text-zinc-600">
                @if ($run->status->isActive())
                    Results appear when the run finishes.
                @else
                    No results were recorded{{ $run->error_message ? ' — see the error above and the execution log.' : '.' }}
                @endif
            </p>
        @else
            <fieldset class="mb-3 text-sm">
                <legend class="sr-only">Show results</legend>
                <div class="inline-flex rounded border border-zinc-300 bg-white">
                    @foreach (\App\Livewire\RunShow::FILTERS as $key => $label)
                        <label @class([
                            'cursor-pointer border-zinc-300 px-3 py-1.5 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-blue-600 [&:not(:first-child)]:border-l',
                            'bg-zinc-900 font-medium text-white' => $filter === $key,
                            'text-zinc-700 hover:bg-zinc-50' => $filter !== $key,
                        ])>
                            <input type="radio" name="resultFilter" value="{{ $key }}" wire:model.live="resultFilter" class="sr-only">{{ $label }} <span class="tabular-nums opacity-75">{{ $counts[$key] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if ($results->isEmpty())
                <p class="rounded border border-dashed border-zinc-300 bg-white p-4 text-sm text-zinc-600">No results match this filter.</p>
            @else
                <table class="w-full border-collapse overflow-hidden rounded border border-zinc-200 bg-white text-sm">
                    <caption class="sr-only">Results in execution order</caption>
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">Result</th>
                            <th scope="col" class="px-3 py-2 font-medium">Request</th>
                            <th scope="col" class="px-3 py-2 font-medium">Assertion</th>
                            <th scope="col" class="px-3 py-2 font-medium">HTTP</th>
                            <th scope="col" class="px-3 py-2 font-medium">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 align-top">
                        @foreach ($results as $result)
                            <tr wire:key="result-{{ $result->id }}">
                                <td class="whitespace-nowrap px-3 py-2">
                                    @if ($result->passed)
                                        <span class="font-semibold text-green-800"><span aria-hidden="true">✓</span> Passed</span>
                                    @elseif ($result->response_code === null)
                                        <span class="font-semibold text-amber-900"><span aria-hidden="true">⚠</span> Error</span>
                                    @else
                                        <span class="font-semibold text-red-800"><span aria-hidden="true">✕</span> Failed</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ $result->item_name }}</span>
                                    @if ($result->url)
                                        <span class="block break-all font-mono text-xs text-zinc-500">{{ $result->method }} {{ $result->url }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    {{ $result->assertion ?? '—' }}
                                    @if ($result->error_message)
                                        <span @class(['block text-xs', 'text-zinc-500' => $result->passed, 'text-red-800' => ! $result->passed])>{{ $result->error_message }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono tabular-nums">{{ $result->response_code ?? '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $result->response_time_ms === null ? '—' : $result->response_time_ms.' ms' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="mt-3">{{ $results->links() }}</div>
            @endif
        @endif
    </section>

    {{-- Secondary view for debugging (master prompt section 80). Redacted and size-capped before storage. --}}
    @if ($run->log !== null && $run->log !== '')
        <details class="rounded border border-zinc-200 bg-white">
            <summary class="cursor-pointer px-4 py-2 text-sm font-medium">Execution log <span class="font-normal text-zinc-500">(secrets redacted)</span></summary>
            <pre class="max-h-[32rem] overflow-auto border-t border-zinc-200 bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-100">{{ $run->log }}</pre>
        </details>
    @endif
</div>
