<aside aria-label="Projects" class="w-56 shrink-0 border-r border-zinc-200 bg-white p-4 text-sm">
    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Projects</h2>

    @if ($projects->isEmpty())
        <p class="text-zinc-500">No projects yet.</p>
    @else
        <ul class="space-y-0.5">
            @foreach ($projects as $project)
                @php($active = request()->route('project')?->is($project))
                <li>
                    <a href="{{ route('projects.show', $project) }}"
                       @if ($active) aria-current="page" @endif
                       @class([
                           'block truncate rounded px-2 py-1',
                           'bg-zinc-100 font-medium text-zinc-900' => $active,
                           'text-zinc-700 hover:bg-zinc-50' => ! $active,
                       ])>{{ $project->name }}</a>
                </li>
            @endforeach
        </ul>
    @endif

    <a href="{{ route('projects') }}#new-project" class="mt-3 inline-block rounded px-2 py-1 text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900">+ New project</a>
</aside>
