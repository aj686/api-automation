{{--
    Side panel of the workspace (D-040). `section` comes from the rail's Alpine
    scope (components/app-sidebar). Request links are real links; on the
    collection already open they only switch the request (`open-request`),
    so unsaved edits get the usual question instead of a page load.
--}}
@php
    $methodColour = fn (?string $method) => match (strtoupper((string) $method)) {
        'GET' => 'text-green-800',
        'POST' => 'text-amber-900',
        'PUT', 'PATCH' => 'text-accent-700',
        'DELETE' => 'text-red-800',
        default => 'text-zinc-700',
    };
    $menuItem = 'w-full px-3 py-1.5 text-left hover:bg-zinc-100';
    $heading = 'text-xs font-semibold uppercase tracking-wide text-zinc-500';
@endphp

<div class="flex min-h-0 flex-1 flex-col text-sm"
     x-data="{
         q: '',
         selected: @js(request()->query('request')),
         status: '',
         open: (() => { try { return JSON.parse(localStorage.getItem('panel-open') || '{}') } catch (e) { return {} } })(),
         isOpen(id, fallback) { return this.open[id] ?? fallback },
         flip(id, fallback) { this.open[id] = ! this.isOpen(id, fallback); try { localStorage.setItem('panel-open', JSON.stringify(this.open)) } catch (e) {} },
         shown(text) { return this.q === '' || text.toLowerCase().includes(this.q.toLowerCase()) },
     }"
     x-on:request-opened.window="selected = $event.detail.id"
     x-on:panel-status="status = $event.detail.message; clearTimeout(window.__panelStatus); window.__panelStatus = setTimeout(() => status = '', 3000)">

    <p role="status" class="mx-2 wrap-anywhere text-xs text-green-900" x-bind:class="status && 'mb-2 rounded border border-green-300 bg-green-50 px-2 py-1'" x-text="status"></p>
    @error('panel') <p role="alert" class="mx-2 mb-2 wrap-anywhere rounded border border-red-300 bg-red-50 px-2 py-1 text-xs text-red-900">{{ $message }}</p> @enderror

    {{-- Collections --}}
    <section x-show="section === 'collections'" aria-labelledby="panel-collections" class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center gap-1 px-3 pb-2">
            <h2 id="panel-collections" class="{{ $heading }} flex-1">Collections</h2>
            <a href="{{ route('projects.collections', $project) }}#import-collection" class="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-accent-700 hover:bg-zinc-100" title="Import a Postman collection">
                <x-icon name="plus" class="size-4" /> Import
            </a>
        </div>
        @if ($collections->isNotEmpty())
            <div class="relative mx-3 mb-2">
                <x-icon name="search" class="pointer-events-none absolute left-2 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                <label for="panel-search" class="sr-only">Search requests</label>
                <input id="panel-search" type="search" x-model.debounce.150ms="q" placeholder="Search requests" autocomplete="off"
                       class="w-full rounded border border-zinc-300 bg-surface py-1 pl-7 pr-2 text-sm">
            </div>
        @endif

        <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
            @if ($collections->isEmpty())
                <div class="mx-1 rounded border border-dashed border-zinc-300 p-3 text-zinc-600">
                    <p class="mb-2">No collections yet.</p>
                    <a href="{{ route('projects.collections', $project) }}#import-collection" class="font-medium text-accent-700 hover:underline">Import a Postman collection →</a>
                </div>
            @endif

            <ul class="space-y-0.5">
                @foreach ($collections as $collection)
                    @php
                        $tree = $trees[$collection->id];
                        $current = $collection->id === $currentCollectionId;
                        $page = route('projects.collections.show', [$project, $collection]);
                        $searchText = $collection->name.' '.collect($tree)->pluck('name')->implode(' ');
                    @endphp
                    <li wire:key="panel-collection-{{ $collection->id }}" x-show="shown(@js($searchText))">
                        <div @class(['group flex items-center gap-1 rounded pr-1', 'bg-zinc-200' => $current, 'hover:bg-zinc-100' => ! $current])>
                            <button type="button" class="rounded p-1 text-zinc-500 hover:text-zinc-900"
                                    x-on:click="flip('c{{ $collection->id }}', @js($current))"
                                    x-bind:aria-expanded="isOpen('c{{ $collection->id }}', @js($current)) || q !== ''"
                                    aria-label="Show or hide the requests of {{ $collection->name }}">
                                <x-icon name="chevron-down" class="size-4 transition-transform duration-150" x-bind:class="isOpen('c{{ $collection->id }}', {{ $current ? 'true' : 'false' }}) || q !== '' ? '' : '-rotate-90'" />
                            </button>
                            <x-icon name="collection" class="size-4 text-zinc-500" />
                            <a href="{{ $page }}?pane=overview" class="min-w-0 flex-1 truncate py-1 font-medium" title="{{ $collection->name }}">{{ $collection->name }}</a>
                            <a href="{{ $page }}?pane=runner" class="rounded p-1 text-zinc-500 opacity-60 hover:bg-zinc-200 hover:text-accent-700 group-hover:opacity-100" title="Run {{ $collection->name }}" aria-label="Run {{ $collection->name }}">
                                <x-icon name="play" class="size-4" />
                            </a>
                            <details class="relative" x-data x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false; $el.querySelector('summary').focus()">
                                <summary class="cursor-pointer list-none rounded p-1 text-zinc-500 hover:bg-zinc-200 [&::-webkit-details-marker]:hidden" aria-label="Actions for {{ $collection->name }}"><x-icon name="dots" class="size-4" /></summary>
                                <div class="absolute right-0 z-20 mt-1 grid w-44 rounded border border-zinc-200 bg-surface py-1 text-xs shadow-lg">
                                    <button type="button" wire:click="addRequest({{ $collection->id }})" class="{{ $menuItem }}">Add request</button>
                                    <button type="button" wire:click="addFolder({{ $collection->id }})" class="{{ $menuItem }}">Add folder</button>
                                    <a href="{{ $page }}?pane=runner" class="{{ $menuItem }}">Run…</a>
                                    <a href="{{ route('projects.collections.download', [$project, $collection]) }}" class="{{ $menuItem }}">Download</a>
                                    <a href="{{ route('projects.collections', $project) }}" class="{{ $menuItem }}">Replace file or delete…</a>
                                </div>
                            </details>
                        </div>

                        @php $ancestors = []; @endphp
                        <ul x-show="isOpen('c{{ $collection->id }}', @js($current)) || q !== ''" class="space-y-px">
                            @if ($tree === [])
                                <li class="py-1 pl-8 text-xs text-zinc-500">No requests yet.</li>
                            @endif
                            @foreach ($tree as $row)
                                @php
                                    $ancestors = array_slice($ancestors, 0, $row['depth']);
                                    $hiddenIf = $ancestors ? implode(' || ', array_map(fn ($a) => '! isOpen('.Js::from('f'.$a).', true)', $ancestors)) : 'false';
                                    if ($row['folder']) { $ancestors[$row['depth']] = $row['id']; }
                                    $indent = 'padding-left: '.(1.5 + min($row['depth'], 5) * 0.75).'rem';
                                @endphp
                                <li wire:key="panel-{{ $collection->id }}-{{ $row['id'] }}"
                                    x-show="q !== '' ? shown(@js($row['name'])) : ! ({{ $hiddenIf }})"
                                    class="group flex items-center gap-1 rounded pr-1"
                                    x-bind:class="selected === @js($row['id']) && @js($current) ? 'bg-accent-50 text-accent-800' : 'hover:bg-zinc-100'"
                                    style="{{ $indent }}">
                                    @if ($renamingId === $row['id'] && $renamingCollectionId === $collection->id)
                                        <form wire:submit="saveRename" class="flex min-w-0 flex-1 items-center gap-1 py-0.5">
                                            <label for="panel-rename" class="sr-only">New name</label>
                                            <input id="panel-rename" type="text" wire:model="renameValue" @error('renameValue') aria-invalid="true" @enderror maxlength="250" x-init="$el.focus(); $el.select()" x-on:keydown.escape="$wire.cancelRename()"
                                                   class="min-w-0 flex-1 rounded border border-zinc-300 bg-surface px-1.5 py-0.5">
                                            <button type="submit" class="rounded px-1.5 py-0.5 text-xs text-accent-700 hover:bg-zinc-100">OK</button>
                                        </form>
                                        @error('renameValue') <p role="alert" class="w-full text-xs text-red-700">{{ $message }}</p> @enderror
                                    @elseif ($row['folder'])
                                        <button type="button" class="flex min-w-0 flex-1 items-center gap-1 py-1 text-left"
                                                x-on:click="flip(@js('f'.$row['id']), true)" x-bind:aria-expanded="isOpen(@js('f'.$row['id']), true)">
                                            <x-icon name="chevron-down" class="size-3.5 text-zinc-500" x-bind:class="isOpen({{ Js::from('f'.$row['id']) }}, true) ? '' : '-rotate-90'" />
                                            <x-icon name="folder" class="size-4 text-zinc-500" />
                                            <span class="truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                        </button>
                                    @else
                                        <a href="{{ $page }}?request={{ urlencode($row['id']) }}" class="flex min-w-0 flex-1 items-center gap-2 py-1"
                                           @if ($current) x-on:click.prevent="$dispatch('open-request', { id: @js($row['id']) }); if (! matchMedia('(min-width: 48rem)').matches) document.querySelector('[data-panel-toggle]')?.click()" @endif>
                                            <span class="min-w-10 shrink-0 font-mono text-[0.65rem] font-semibold {{ $methodColour($row['method']) }}">{{ $row['method'] }}</span>
                                            <span class="truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                        </a>
                                    @endif

                                    @if (! ($renamingId === $row['id'] && $renamingCollectionId === $collection->id))
                                        <details class="relative shrink-0 opacity-0 focus-within:opacity-100 group-hover:opacity-100 pointer-coarse:opacity-100 [&[open]]:opacity-100" x-data x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false; $el.querySelector('summary').focus()">
                                            <summary class="cursor-pointer list-none rounded p-1 text-zinc-500 hover:bg-zinc-200 [&::-webkit-details-marker]:hidden" aria-label="Actions for {{ $row['name'] }}"><x-icon name="dots" class="size-4" /></summary>
                                            <div class="absolute right-0 z-20 mt-1 grid w-40 rounded border border-zinc-200 bg-surface py-1 text-xs shadow-lg">
                                                @if ($row['folder'])
                                                    <button type="button" wire:click="addRequest({{ $collection->id }}, @js($row['id']))" class="{{ $menuItem }}">Add request</button>
                                                    <button type="button" wire:click="addFolder({{ $collection->id }}, @js($row['id']))" class="{{ $menuItem }}">Add folder</button>
                                                @endif
                                                <button type="button" wire:click="startRename({{ $collection->id }}, @js($row['id']))" class="{{ $menuItem }}">Rename</button>
                                                <button type="button" wire:click="duplicateItem({{ $collection->id }}, @js($row['id']))" class="{{ $menuItem }}">Duplicate</button>
                                                <button type="button" wire:click="moveItem({{ $collection->id }}, @js($row['id']), -1)" class="{{ $menuItem }}">Move up</button>
                                                <button type="button" wire:click="moveItem({{ $collection->id }}, @js($row['id']), 1)" class="{{ $menuItem }}">Move down</button>
                                                <button type="button" wire:click="deleteItem({{ $collection->id }}, @js($row['id']))"
                                                        wire:confirm="Delete {{ $row['folder'] ? 'the folder '.$row['name'].' and everything in it' : $row['name'] }}? This changes the stored collection."
                                                        class="{{ $menuItem }} text-red-700 hover:bg-red-50">Delete</button>
                                            </div>
                                        </details>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
            <p x-show="q !== ''" x-cloak class="px-2 pt-2 text-xs text-zinc-600">Showing names that contain “<span x-text="q"></span>”. <button type="button" x-on:click="q = ''" class="text-accent-700 hover:underline">Clear</button></p>
        </div>
    </section>

    {{-- Environments --}}
    <section x-show="section === 'environments'" x-cloak aria-labelledby="panel-environments" class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center gap-1 px-3 pb-2">
            <h2 id="panel-environments" class="{{ $heading }} flex-1">Environments</h2>
            <a href="{{ route('projects.environments', $project) }}#new-environment" class="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-accent-700 hover:bg-zinc-100"><x-icon name="plus" class="size-4" /> New</a>
        </div>
        <ul class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
            @forelse ($environments as $environment)
                @php($active = $environment->id === $activeEnvironmentId)
                <li wire:key="panel-env-{{ $environment->id }}" @class(['group flex items-center gap-1 rounded pr-1', 'bg-accent-50' => $active, 'hover:bg-zinc-100' => ! $active])>
                    <a href="{{ route('projects.environments.show', [$project, $environment]) }}" class="min-w-0 flex-1 truncate py-1 pl-2" title="{{ $environment->name }}">{{ $environment->name }}</a>
                    @if ($environment->isProduction())
                        <span class="shrink-0 rounded bg-red-50 px-1 text-[0.65rem] font-semibold text-red-800 ring-1 ring-inset ring-red-600/30">PROD</span>
                    @endif
                    <button type="button" wire:click="chooseEnvironment({{ $environment->id }})" @disabled($active)
                            @class(['rounded p-1', 'text-accent-700' => $active, 'text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700' => ! $active])
                            aria-pressed="{{ $active ? 'true' : 'false' }}" title="{{ $active ? 'Active environment' : 'Make active' }}" aria-label="{{ $active ? $environment->name.' is active' : 'Make '.$environment->name.' active' }}">
                        <x-icon name="check" class="size-4" />
                    </button>
                </li>
            @empty
                <li class="mx-1 rounded border border-dashed border-zinc-300 p-3 text-zinc-600">
                    No environments yet. <a href="{{ route('projects.environments', $project) }}#new-environment" class="font-medium text-accent-700 hover:underline">Create one →</a>
                </li>
            @endforelse
        </ul>
        <p class="border-t border-zinc-200 px-3 py-2 text-xs text-zinc-500">✓ = the active environment, also shown top right. Send and Run use it.</p>
    </section>

    {{-- History --}}
    <section x-show="section === 'history'" x-cloak aria-labelledby="panel-history" class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center gap-1 px-3 pb-2">
            <h2 id="panel-history" class="{{ $heading }} flex-1">History</h2>
            <a href="{{ route('projects.runs', $project) }}" class="rounded px-1.5 py-0.5 text-xs text-accent-700 hover:bg-zinc-100">All runs →</a>
        </div>
        <ul class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
            @forelse ($runs as $run)
                <li wire:key="panel-run-{{ $run->id }}">
                    <a href="{{ route('runs.show', $run) }}" class="block rounded px-2 py-1.5 hover:bg-zinc-100">
                        <span class="flex items-center gap-2">
                            <x-status-badge :status="$run->status" :no-tests="$run->hasNoTests()" />
                            <span class="min-w-0 flex-1 truncate" title="{{ $run->collection_name }} → {{ $run->environment_name }}">{{ $run->collection_name }}</span>
                        </span>
                        <span class="mt-0.5 flex gap-2 text-xs text-zinc-500">
                            <span class="truncate">{{ $run->trigger === \App\Enums\RunTrigger::Send ? 'Send · '.($run->runOptions()->items[0]['name'] ?? '') : $run->environment_name }}</span>
                            <time class="ml-auto shrink-0" datetime="{{ $run->created_at->toIso8601String() }}">{{ $run->created_at->diffForHumans(short: true) }}</time>
                        </span>
                    </a>
                </li>
            @empty
                <li class="px-2 py-1 text-zinc-600">No runs yet.</li>
            @endforelse
        </ul>
    </section>

    {{-- Captures --}}
    <section x-show="section === 'captures'" x-cloak aria-labelledby="panel-captures" class="flex min-h-0 flex-1 flex-col">
        <div class="flex items-center gap-1 px-3 pb-2">
            <h2 id="panel-captures" class="{{ $heading }} flex-1">Capture URLs</h2>
            <a href="{{ route('projects.captures', $project) }}" class="rounded px-1.5 py-0.5 text-xs text-accent-700 hover:bg-zinc-100">Manage →</a>
        </div>
        <ul class="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
            @forelse ($buckets as $bucket)
                <li wire:key="panel-bucket-{{ $bucket->id }}">
                    <a href="{{ route('projects.captures', $project) }}" class="flex items-center gap-2 rounded px-2 py-1.5 hover:bg-zinc-100">
                        <span aria-hidden="true" @class(['size-2 shrink-0 rounded-full', 'bg-green-600' => $bucket->enabled, 'bg-zinc-300' => ! $bucket->enabled])></span>
                        <span class="min-w-0 flex-1 truncate" title="{{ $bucket->name }}">{{ $bucket->name }}</span>
                        <span class="shrink-0 text-xs text-zinc-500">{{ $bucket->enabled ? 'on' : 'off' }}</span>
                    </a>
                </li>
            @empty
                <li class="px-2 py-1 text-zinc-600">No capture URLs. They receive calls from the system under test.</li>
            @endforelse
        </ul>
    </section>
</div>
