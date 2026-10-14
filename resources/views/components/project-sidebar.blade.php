{{--
    Icon rail + side panel (D-040). md and up: under the top bar, sticky; the
    panel folds away (data-sidebar="closed") and the rail stays. Below md the
    whole thing is a drawer. Styles: .sidebar in app.css.
--}}
<aside aria-label="Sidebar" id="project-sidebar" class="sidebar flex shrink-0 border-r border-zinc-200 bg-surface text-sm"
       x-data="{
           section: @js($routeSection) ?? (() => { try { return localStorage.getItem('panel') || 'collections' } catch (e) { return 'collections' } })(),
           pick(name) {
               this.section = name;
               try { localStorage.setItem('panel', name) } catch (e) {}
               if (document.documentElement.dataset.sidebar === 'closed') document.querySelector('[data-panel-toggle]')?.click();
           },
       }">
    <nav aria-label="Main" class="flex w-[4.75rem] shrink-0 flex-col items-stretch gap-0.5 border-r border-zinc-200 px-1 py-2">
        @if ($workspace)
            @php($onOverview = request()->routeIs('projects.show'))
            <a href="{{ route('projects.show', $workspace) }}" title="Overview: run tests, project details"
               @if ($onOverview) aria-current="page" @endif
               @class(['rail-item', 'rail-item-active' => $onOverview])>
                <x-icon name="overview" /><span>Overview</span>
            </a>
            @foreach ($sections as $key => [$label, $icon])
                <button type="button" x-on:click="pick('{{ $key }}')" title="{{ $label }}"
                        x-bind:aria-pressed="section === '{{ $key }}'"
                        x-bind:class="section === '{{ $key }}' ? 'rail-item rail-item-active' : 'rail-item'"
                        class="rail-item" aria-controls="sidebar-panel">
                    <x-icon :name="$icon" /><span>{{ $label }}</span>
                </button>
            @endforeach
        @endif

        <div @class(['flex flex-col gap-0.5', 'mt-auto border-t border-zinc-200 pt-2' => $workspace])>
            @foreach ($main as $route => [$label, $icon])
                @php($active = request()->routeIs($route))
                <a href="{{ route($route) }}" title="{{ $label }}" @if ($active) aria-current="page" @endif @class(['rail-item', 'rail-item-active' => $active])>
                    <x-icon :name="$icon" /><span>{{ $label }}</span>
                </a>
            @endforeach
        </div>

        <div @class(['flex flex-col gap-0.5', 'mt-auto' => ! $workspace, 'border-t border-zinc-200 pt-2'])>
            @foreach ($footer as $route => [$label, $icon])
                @php($active = request()->routeIs($route))
                <a href="{{ route($route) }}" title="{{ $label }}" @if ($active) aria-current="page" @endif @class(['rail-item', 'rail-item-active' => $active])>
                    <x-icon :name="$icon" /><span>{{ $label }}</span>
                </a>
            @endforeach

            <div role="group" aria-label="Theme" class="theme-switch mt-1 flex flex-col gap-0.5 rounded-md border border-zinc-200 p-0.5">
                @foreach (['system' => ['System', 'monitor'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $option => [$label, $icon])
                    <button type="button" data-theme-option="{{ $option }}" aria-pressed="false" title="{{ $label }} theme"
                            class="flex items-center justify-center rounded py-1 text-zinc-500 hover:text-zinc-900 aria-pressed:bg-accent-50 aria-pressed:text-accent-800">
                        <x-icon :name="$icon" class="size-4" />
                        <span class="sr-only">{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </nav>

    <div class="relative flex min-w-0" x-data="panelResize" x-bind:style="'--panel-width: ' + width + 'px'">
    <div id="sidebar-panel" x-ref="panel" class="sidebar-panel flex min-w-0 flex-col pt-3" x-bind:class="closing && 'opacity-50'">
        @if ($workspace)
            <livewire:workspace-panel :project="$workspace" :current-collection-id="$currentCollectionId" :key="'panel-'.$workspace->id" />
        @else
            <section aria-labelledby="sidebar-projects" class="flex min-h-0 flex-1 flex-col px-2">
                <h2 id="sidebar-projects" class="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Projects</h2>
                @if ($projects->isEmpty())
                    <p class="px-2 text-zinc-500">No projects yet.</p>
                @else
                    <ul class="space-y-0.5 overflow-y-auto">
                        @foreach ($projects as $project)
                            <li><a href="{{ route('projects.show', $project) }}" class="block truncate rounded px-2 py-1 text-zinc-700 hover:bg-zinc-100" title="{{ $project->name }}">{{ $project->name }}</a></li>
                        @endforeach
                    </ul>
                @endif
                <a href="{{ route('projects') }}#new-project" class="mt-1 flex items-center gap-1.5 rounded px-2 py-1 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900">
                    <x-icon name="plus" class="size-4" /> New project
                </a>
            </section>
        @endif
    </div>
    {{-- Drag to widen or narrow; drag almost shut to close. Arrow keys work too. --}}
    <div role="separator" aria-orientation="vertical" aria-label="Resize the side panel" tabindex="0"
         x-bind:aria-valuenow="width" aria-valuemin="200" aria-valuemax="560"
         x-on:pointerdown="start($event)" x-on:keydown.left.prevent="nudge(-16)" x-on:keydown.right.prevent="nudge(16)"
         class="panel-resizer" title="Drag to resize · drag far left to close"></div>
    </div>
</aside>
