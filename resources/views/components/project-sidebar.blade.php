{{--
    App sidebar, n8n-style: brand + collapse button, main navigation, the
    project list, and Settings + theme pinned to the bottom. Collapsed (md and
    up) it is an icon rail; below md it is a drawer. See .sidebar in app.css.
--}}
<aside aria-label="Sidebar" id="project-sidebar" class="sidebar flex w-56 shrink-0 flex-col border-r border-zinc-200 bg-surface text-sm">
    <div class="flex h-12 shrink-0 items-center gap-2 px-3">
        <a href="{{ route('dashboard') }}" class="sidebar-label truncate font-semibold tracking-tight">{{ config('app.name') }}</a>
        <button type="button" aria-controls="project-sidebar" aria-expanded="true" title="Collapse or expand the sidebar"
                class="ml-auto rounded p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900">
            <x-icon name="panel" />
            <span class="sr-only">Sidebar</span>
        </button>
    </div>

    <nav aria-label="Main" class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-2 pb-2">
        @foreach ($main as $route => [$label, $icon])
            <x-nav-link :route="$route" :icon="$icon" :title="$label">{{ $label }}</x-nav-link>
        @endforeach

        <section aria-labelledby="sidebar-projects" class="sidebar-section mt-5">
            <h2 id="sidebar-projects" class="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Projects</h2>

            @if ($projects->isEmpty())
                <p class="px-2 text-zinc-500">No projects yet.</p>
            @else
                <ul class="space-y-0.5">
                    @foreach ($projects as $project)
                        @php($active = request()->route('project')?->is($project))
                        <li>
                            <a href="{{ route('projects.show', $project) }}"
                               @if ($active) aria-current="page" @endif
                               @class([
                                   'block truncate rounded px-2 py-1',
                                   'bg-accent-50 font-medium text-accent-800' => $active,
                                   'text-zinc-700 hover:bg-zinc-100' => ! $active,
                               ])>{{ $project->name }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ route('projects') }}#new-project" class="mt-1 flex items-center gap-1.5 rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900">
                <x-icon name="plus" class="size-4" /> New project
            </a>
        </section>

        <div class="mt-auto flex flex-col gap-0.5 border-t border-zinc-200 pt-2">
            @foreach ($footer as $route => [$label, $icon])
                <x-nav-link :route="$route" :icon="$icon" :title="$label">{{ $label }}</x-nav-link>
            @endforeach

            <div role="group" aria-label="Theme" class="theme-switch mt-1 flex gap-0.5 rounded-md border border-zinc-200 p-0.5">
                @foreach (['system' => ['System', 'monitor'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $option => [$label, $icon])
                    <button type="button" data-theme-option="{{ $option }}" aria-pressed="false" title="{{ $label }} theme"
                            class="flex flex-1 items-center justify-center rounded py-1 text-zinc-500 hover:text-zinc-900 aria-pressed:bg-accent-50 aria-pressed:text-accent-800">
                        <x-icon :name="$icon" class="size-4" />
                        <span class="sr-only">{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </nav>
</aside>
