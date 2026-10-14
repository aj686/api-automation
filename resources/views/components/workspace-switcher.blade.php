{{-- The workspace (project) switcher in the top bar (D-040). --}}
@props(['workspace'])

@php($projects = \App\Models\Project::query()->orderBy('name')->get(['id', 'name', 'slug']))

<details class="relative min-w-0" x-data x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false; $el.querySelector('summary').focus()">
    <summary class="flex min-w-[5rem] max-w-[16rem] cursor-pointer list-none items-center gap-1.5 rounded border border-zinc-300 px-2 py-1 text-sm hover:bg-zinc-100 [&::-webkit-details-marker]:hidden"
             title="Switch workspace (project)">
        <x-icon name="folder" class="size-4 text-zinc-500" />
        <span class="truncate font-medium">{{ $workspace?->name ?? 'Choose a project' }}</span>
        <x-icon name="chevron-down" class="size-4 text-zinc-500" />
    </summary>
    <div class="absolute left-0 z-40 mt-1 w-72 max-w-[calc(100vw-1.5rem)] rounded border border-zinc-200 bg-surface py-1 text-sm shadow-lg">
        <p class="px-3 pb-1 pt-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-500">Workspaces</p>
        <ul class="max-h-80 overflow-y-auto">
            @forelse ($projects as $project)
                @php($current = $workspace?->is($project))
                <li>
                    <a href="{{ route('projects.show', $project) }}" @if ($current) aria-current="true" @endif
                       @class(['flex items-center gap-2 px-3 py-1.5', 'bg-accent-50 font-medium text-accent-800' => $current, 'hover:bg-zinc-100' => ! $current])>
                        <span class="min-w-0 flex-1 truncate" title="{{ $project->name }}">{{ $project->name }}</span>
                        @if ($current) <x-icon name="check" class="size-4" /> @endif
                    </a>
                </li>
            @empty
                <li class="px-3 py-1.5 text-zinc-500">No projects yet.</li>
            @endforelse
        </ul>
        <div class="mt-1 border-t border-zinc-200 pt-1">
            <a href="{{ route('projects') }}#new-project" class="flex items-center gap-2 px-3 py-1.5 hover:bg-zinc-100"><x-icon name="plus" class="size-4" /> New project</a>
            <a href="{{ route('home') }}" class="flex items-center gap-2 px-3 py-1.5 hover:bg-zinc-100"><x-icon name="home" class="size-4" /> All projects</a>
        </div>
    </div>
</details>
