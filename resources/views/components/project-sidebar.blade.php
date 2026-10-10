<aside aria-label="Projects" class="w-56 shrink-0 border-r border-zinc-200 bg-white p-4 text-sm">
    <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Projects</h2>

    @if ($projects->isEmpty())
        <p class="text-zinc-500">No projects yet.</p>
    @else
        <ul class="space-y-0.5">
            @foreach ($projects as $project)
                <li class="truncate rounded px-2 py-1 text-zinc-700">{{ $project->name }}</li>
            @endforeach
        </ul>
    @endif

    <a href="{{ route('projects') }}" class="mt-3 inline-block rounded px-2 py-1 text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900">+ New project</a>
</aside>
