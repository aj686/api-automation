<div>
    <h1 class="mb-4 text-lg font-semibold">Dashboard</h1>

    @if ($projects->isEmpty())
        <div class="rounded border border-dashed border-zinc-300 bg-white p-6 text-sm text-zinc-600">
            <p class="font-medium text-zinc-900">No projects yet.</p>
            <p class="mt-1">Create a project, add its environments and import a Postman collection to run your first test.</p>
        </div>
    @else
        <table class="w-full border-collapse overflow-hidden rounded border border-zinc-200 bg-white text-sm">
            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium">Project</th>
                    <th scope="col" class="px-3 py-2 font-medium">Environment</th>
                    <th scope="col" class="px-3 py-2 font-medium">Last run</th>
                    <th scope="col" class="px-3 py-2 font-medium">Passed</th>
                    <th scope="col" class="px-3 py-2 font-medium">Duration</th>
                    <th scope="col" class="px-3 py-2 font-medium">Executed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($projects as $project)
                    @php($run = $project->latestRun)
                    <tr>
                        <th scope="row" class="px-3 py-2 text-left font-medium">{{ $project->name }}</th>
                        @if ($run)
                            <td class="px-3 py-2">{{ $run->environment_name }}</td>
                            <td class="px-3 py-2"><x-status-badge :status="$run->status" :no-tests="$run->hasNoTests()" /></td>
                            {{-- NULL counts are "unknown", shown as a dash, never as 0. --}}
                            <td class="px-3 py-2 tabular-nums">
                                {{ $run->total_assertions === null ? '—' : $run->passed_assertions.'/'.$run->total_assertions }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ $run->duration_ms === null ? '—' : number_format($run->duration_ms / 1000, 1).' s' }}
                            </td>
                            <td class="px-3 py-2 text-zinc-600">
                                <time datetime="{{ $run->created_at->toIso8601String() }}">{{ $run->created_at->diffForHumans() }}</time>
                            </td>
                        @else
                            <td colspan="5" class="px-3 py-2 text-zinc-500">Not run yet</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
