{{--
    Collection workbench, Postman-style (decisions.md D-038): tree on the left,
    the open request's tabs on the right, environment picker top right.
    Send and Run go through Postman CLI like every other run.
--}}
@php
    $methodColour = fn (?string $method) => match (strtoupper((string) $method)) {
        'GET' => 'text-green-800',
        'POST' => 'text-amber-900',
        'PUT', 'PATCH' => 'text-accent-700',
        'DELETE' => 'text-red-800',
        default => 'text-zinc-700',
    };
    $input = 'w-full rounded border border-zinc-300 bg-surface px-2 py-1.5';
    $mono = 'w-full rounded border border-zinc-300 bg-surface px-2 py-1.5 font-mono text-xs';
    // A {{name}} reference as text; Blade would read it as an echo inside a component attribute.
    $variable = fn (string $name) => '{'.'{'.$name.'}'.'}';
    $requestCount = collect($tree)->where('folder', false)->count();
    $checkedCount = collect($sequence)->where('checked', true)->count();
@endphp

<div class="max-w-7xl"
     x-data
     x-on:keydown.ctrl.s.window.prevent="if ($wire.pane === 'request') { document.activeElement?.blur(); $wire.saveRequest() }"
     x-on:keydown.meta.s.window.prevent="if ($wire.pane === 'request') { document.activeElement?.blur(); $wire.saveRequest() }"
     x-on:beforeunload.window="if ($wire.unsaved) { $event.preventDefault(); $event.returnValue = ''; }">
    <x-flash />

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="wrap-anywhere text-xs text-zinc-500">
                <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->name }}</a> /
                <a href="{{ route('projects.collections', $project) }}" class="hover:underline">Collections</a>
            </p>
            <h1 class="wrap-anywhere text-lg font-semibold">{{ $collection->name }}</h1>
            <p class="font-mono text-xs text-zinc-500">{{ $collection->slug }} · {{ Str::plural('request', $requestCount, true) }}</p>
        </div>

        <div class="flex flex-wrap items-end gap-2 text-sm">
            <div>
                <label for="environmentId" class="mb-1 block text-xs font-medium text-zinc-600">Environment</label>
                @if ($environments->isEmpty())
                    <a href="{{ route('projects.environments', $project) }}" class="text-accent-700 hover:underline">Create an environment →</a>
                @else
                    <select id="environmentId" wire:model.live="environmentId" class="max-w-[16rem] rounded border border-zinc-300 bg-surface px-2 py-1.5">
                        @foreach ($environments as $environment)
                            <option value="{{ $environment->id }}">{{ $environment->name }}{{ $environment->isProduction() ? ' — PRODUCTION' : '' }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <a href="{{ route('projects.collections.download', [$project, $collection]) }}" class="rounded px-3 py-1.5 text-accent-700 hover:bg-zinc-100" title="The collection file as stored, for importing back into Postman">Download</a>
        </div>
    </div>

    @if ($selectedEnvironment?->isProduction())
        <div class="mb-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">
            <p class="mb-2"><span aria-hidden="true">⚠</span> <strong>PRODUCTION</strong> — Send and Run may change real data.</p>
            <x-field label="Type {{ $selectedEnvironment->name }} to confirm" for="confirmProduction" :error="$errors->first('confirmProduction')">
                <input id="confirmProduction" type="text" wire:model="confirmProduction" autocomplete="off" class="{{ $input }} max-w-sm">
            </x-field>
        </div>
    @endif

    <x-project-tabs :project="$project" active="collections" />

    @error('collection') <p role="alert" class="mb-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $message }}</p> @enderror
    @error('run') <p role="alert" class="mb-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $message }}</p> @enderror
    @if ($changedElsewhere)
        <p role="alert" class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            This collection was changed elsewhere since you opened this request. Saving is blocked; <a href="{{ request()->url() }}" class="font-medium underline">reload</a> to see the current version.
        </p>
    @endif
    @if ($warnings)
        <div role="alert" class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
            <p class="font-semibold"><span aria-hidden="true">⚠</span> Check this collection</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($warnings as $warning) <li>{{ $warning }}</li> @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
        {{-- Tree --}}
        <aside aria-label="Requests" class="min-w-0 rounded lg:sticky lg:top-4 lg:self-start border border-zinc-200 bg-surface p-2 text-sm"
               x-data="{ closed: {} }">
            <div class="mb-2 flex flex-wrap gap-1">
                <button type="button" wire:click="showPane('overview')" @class(['rounded px-2 py-1', 'bg-accent-50 font-medium text-accent-700' => $pane === 'overview', 'hover:bg-zinc-100' => $pane !== 'overview']) @if ($pane === 'overview') aria-current="page" @endif>Overview</button>
                <button type="button" wire:click="showPane('runner')" @class(['rounded px-2 py-1', 'bg-accent-50 font-medium text-accent-700' => $pane === 'runner', 'hover:bg-zinc-100' => $pane !== 'runner']) @if ($pane === 'runner') aria-current="page" @endif><span aria-hidden="true">▶</span> Runner</button>
                <span class="flex-1"></span>
                <button type="button" wire:click="addRequest" class="rounded px-2 py-1 text-accent-700 hover:bg-zinc-100" title="Add a request at the top level">+ Request</button>
                <button type="button" wire:click="addFolder" class="rounded px-2 py-1 text-accent-700 hover:bg-zinc-100" title="Add a folder at the top level">+ Folder</button>
            </div>

            @if ($tree === [])
                <p class="px-2 py-3 text-zinc-600">No requests yet. Add one above.</p>
            @endif

            @php $ancestors = []; @endphp
            <ul class="max-h-[40vh] space-y-px overflow-y-auto lg:max-h-[calc(100dvh-6rem)]">
                @foreach ($tree as $row)
                    @php
                        $ancestors = array_slice($ancestors, 0, $row['depth']);
                        $hiddenIf = $ancestors ? implode(' || ', array_map(fn ($a) => 'closed['.Js::from($a).']', $ancestors)) : 'false';
                        if ($row['folder']) { $ancestors[$row['depth']] = $row['id']; }
                        $selected = ! $row['folder'] && $row['id'] === $selectedId;
                    @endphp
                    <li wire:key="tree-{{ $row['id'] }}" x-show="!({{ $hiddenIf }})"
                        class="group flex items-center gap-1 rounded {{ $selected ? 'bg-accent-50' : 'hover:bg-zinc-50' }}"
                        style="padding-left: {{ min($row['depth'], 5) * 0.75 + 0.25 }}rem">
                        @if ($renamingId === $row['id'])
                            <form wire:submit="saveRename" class="flex min-w-0 flex-1 items-center gap-1 py-0.5">
                                <label for="renameValue" class="sr-only">New name</label>
                                <input id="renameValue" type="text" wire:model="renameValue" maxlength="250" x-init="$el.focus(); $el.select()" x-on:keydown.escape="$wire.cancelRename()"
                                       class="min-w-0 flex-1 rounded border border-zinc-300 bg-surface px-1.5 py-0.5">
                                <button type="submit" class="rounded px-1.5 py-0.5 text-accent-700 hover:bg-zinc-100">OK</button>
                            </form>
                        @elseif ($row['folder'])
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-1 py-1 text-left"
                                    x-on:click="closed[@js($row['id'])] = !closed[@js($row['id'])]"
                                    x-bind:aria-expanded="!closed[@js($row['id'])]">
                                <span aria-hidden="true" class="w-3 text-zinc-500" x-text="closed[@js($row['id'])] ? '▸' : '▾'">▾</span>
                                <span class="truncate font-medium" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                            </button>
                        @else
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-2 py-1 pl-4 text-left"
                                    @if ($selected) aria-current="true" @endif
                                    x-on:click="if ($wire.unsaved && @js($row['id']) !== $wire.selectedId && ! confirm('Discard your unsaved changes to this request?')) return; $wire.selectItem(@js($row['id']))">
                                <span class="min-w-12 shrink-0 font-mono text-[0.65rem] font-semibold {{ $methodColour($row['method']) }}">{{ $row['method'] }}</span>
                                <span class="truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                            </button>
                        @endif

                        @if ($renamingId !== $row['id'])
                            <details class="relative shrink-0" x-data x-on:click.outside="$el.open = false">
                                <summary class="cursor-pointer list-none rounded [&::-webkit-details-marker]:hidden px-1.5 py-0.5 text-zinc-500 hover:bg-zinc-100" aria-label="Actions for {{ $row['name'] }}">⋯</summary>
                                <div class="absolute right-0 z-10 mt-1 grid w-40 rounded border border-zinc-200 bg-surface py-1 text-xs shadow-lg">
                                    @if ($row['folder'])
                                        <button type="button" wire:click="addRequest(@js($row['id']))" class="px-3 py-1.5 text-left hover:bg-zinc-100">Add request</button>
                                        <button type="button" wire:click="addFolder(@js($row['id']))" class="px-3 py-1.5 text-left hover:bg-zinc-100">Add folder</button>
                                    @endif
                                    <button type="button" wire:click="startRename(@js($row['id']))" class="px-3 py-1.5 text-left hover:bg-zinc-100">Rename</button>
                                    <button type="button" wire:click="duplicateItem(@js($row['id']))" class="px-3 py-1.5 text-left hover:bg-zinc-100">Duplicate</button>
                                    <button type="button" wire:click="moveItem(@js($row['id']), -1)" class="px-3 py-1.5 text-left hover:bg-zinc-100">Move up</button>
                                    <button type="button" wire:click="moveItem(@js($row['id']), 1)" class="px-3 py-1.5 text-left hover:bg-zinc-100">Move down</button>
                                    <button type="button" wire:click="deleteItem(@js($row['id']))"
                                            wire:confirm="Delete {{ $row['folder'] ? 'the folder '.$row['name'].' and everything in it' : $row['name'] }}? This changes the stored collection."
                                            class="px-3 py-1.5 text-left text-red-700 hover:bg-red-50">Delete</button>
                                </div>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        </aside>

        {{-- Pane --}}
        <section class="min-w-0">
            @if ($pane === 'request' && $selectedId !== null)
                <div class="rounded border border-zinc-200 bg-surface p-4" aria-labelledby="request-heading">
                    <div class="mb-3 flex items-center gap-2">
                        <h2 id="request-heading" class="sr-only">Request</h2>
                        <label for="form-name" class="sr-only">Request name</label>
                        <input id="form-name" type="text" wire:model.live.blur="form.name" maxlength="250" autocomplete="off"
                               class="min-w-0 flex-1 rounded border border-transparent bg-transparent px-1 py-0.5 font-semibold hover:border-zinc-300 focus:border-zinc-300">
                        @if ($unsaved)
                            <span class="shrink-0 text-xs text-amber-800"><span aria-hidden="true">●</span> Unsaved</span>
                        @endif
                    </div>
                    @error('form.name') <p role="alert" class="-mt-2 mb-2 text-xs font-medium text-red-700">{{ $message }}</p> @enderror

                    <form wire:submit="sendRequest" class="mb-1 flex flex-wrap gap-2 sm:flex-nowrap">
                        <label for="form-method" class="sr-only">Method</label>
                        <select id="form-method" wire:model.live="form.method" class="rounded border border-zinc-300 bg-surface px-2 py-1.5 font-mono text-sm font-semibold {{ $methodColour($form['method'] ?? null) }}">
                            @foreach (array_unique([...$methods, $form['method'] ?? 'GET']) as $method)
                                <option value="{{ $method }}">{{ $method }}</option>
                            @endforeach
                        </select>
                        <label for="form-url" class="sr-only">URL</label>
                        <input id="form-url" type="text" wire:model.live.blur="form.url" placeholder="@{{base_url}}/path" autocomplete="off" spellcheck="false"
                               class="order-first min-w-0 flex-1 basis-full sm:order-none sm:basis-0 rounded border border-zinc-300 bg-surface px-2 py-1.5 font-mono text-sm">
                        <button type="button" wire:click="saveRequest" wire:loading.attr="disabled" wire:target="saveRequest" class="rounded border border-zinc-300 px-3 py-1.5 text-sm hover:bg-zinc-100 disabled:opacity-60" title="Ctrl+S">Save</button>
                        <button type="submit" class="rounded bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary-hover disabled:opacity-60"
                                wire:loading.attr="disabled" wire:target="sendRequest" @disabled($environments->isEmpty())>Send</button>
                    </form>
                    @error('form.method') <p role="alert" class="text-xs font-medium text-red-700">{{ $message }}</p> @enderror
                    @error('form.url') <p role="alert" class="text-xs font-medium text-red-700">{{ $message }}</p> @enderror
                    <p class="mb-3 text-xs text-zinc-500">Send saves first, then runs this one request with Postman CLI against the selected environment. Values its scripts set (pm.environment.set) are saved to that environment.</p>

                    @php
                        $tabs = [
                            'params' => 'Params'.(count($form['params']) ? ' ('.count($form['params']).')' : ''),
                            'auth' => 'Authorization',
                            'headers' => 'Headers'.(count($form['headers']) ? ' ('.count($form['headers']).')' : ''),
                            'body' => 'Body',
                            'scripts' => 'Scripts'.(trim($form['prerequest'].$form['test']) !== '' ? ' ●' : ''),
                        ];
                    @endphp
                    <div x-data="{ tab: 'params',
                                   move(step) { const k = @js(array_keys($tabs)); this.tab = k[(k.indexOf(this.tab) + step + k.length) % k.length]; this.$nextTick(() => this.$root.querySelector('[data-tab=' + this.tab + ']').focus()); } }">
                        <div role="tablist" aria-label="Request parts" class="mb-3 flex gap-1 overflow-x-auto border-b border-zinc-200 text-sm"
                             x-on:keydown.right.prevent="move(1)" x-on:keydown.left.prevent="move(-1)">
                            @foreach ($tabs as $key => $label)
                                <button type="button" role="tab" id="tab-{{ $key }}" data-tab="{{ $key }}" aria-controls="panel-{{ $key }}"
                                        x-on:click="tab = '{{ $key }}'"
                                        x-bind:aria-selected="tab === '{{ $key }}'"
                                        x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                                        x-bind:class="tab === '{{ $key }}' ? 'border-accent-600 font-medium text-accent-700' : 'border-transparent text-zinc-600 hover:text-zinc-900'"
                                        class="-mb-px shrink-0 whitespace-nowrap border-b-2 px-3 py-2">{{ $label }}</button>
                            @endforeach
                        </div>

                        {{-- Params --}}
                        <div role="tabpanel" id="panel-params" aria-labelledby="tab-params" x-show="tab === 'params'">
                            @include('livewire.partials.key-value-rows', ['list' => 'params', 'rows' => $form['params'], 'addLabel' => 'Add parameter', 'empty' => 'No query parameters.'])
                        </div>

                        {{-- Authorization --}}
                        <div role="tabpanel" id="panel-auth" aria-labelledby="tab-auth" x-show="tab === 'auth'" x-cloak class="grid max-w-xl gap-3 text-sm">
                            @if ($form['auth']['type'] === 'other')
                                <p class="rounded border border-zinc-200 bg-zinc-50 px-3 py-2 text-zinc-700">
                                    This request uses <strong>{{ $form['auth']['other'] }}</strong> auth, which this editor does not change. It is kept as it is and still works in runs; edit it in Postman and use Replace file.
                                </p>
                            @else
                                <x-field label="Type" for="auth-type">
                                    <select id="auth-type" wire:model.live="form.auth.type" class="{{ $input }}">
                                        <option value="inherit">Inherit from parent</option>
                                        <option value="noauth">No auth</option>
                                        <option value="bearer">Bearer token</option>
                                        <option value="basic">Basic auth</option>
                                        <option value="apikey">API key</option>
                                    </select>
                                </x-field>
                                @if ($form['auth']['type'] === 'bearer')
                                    <x-field label="Token" for="auth-token" :hint="'Use a variable such as '.$variable('token').'; a script can set it, e.g. after Login.'">
                                        <input id="auth-token" type="text" wire:model.live.blur="form.auth.token" autocomplete="off" spellcheck="false" class="{{ $mono }}">
                                    </x-field>
                                @elseif ($form['auth']['type'] === 'basic')
                                    <x-field label="Username" for="auth-username">
                                        <input id="auth-username" type="text" wire:model.live.blur="form.auth.username" autocomplete="off" spellcheck="false" class="{{ $mono }}">
                                    </x-field>
                                    <x-field label="Password" for="auth-password" :hint="'Use a secret environment variable such as '.$variable('password').'.'">
                                        <input id="auth-password" type="password" wire:model.live.blur="form.auth.password" autocomplete="new-password" class="{{ $mono }}">
                                    </x-field>
                                @elseif ($form['auth']['type'] === 'apikey')
                                    <x-field label="Key" for="auth-key">
                                        <input id="auth-key" type="text" wire:model.live.blur="form.auth.key" autocomplete="off" spellcheck="false" class="{{ $mono }}" placeholder="X-Api-Key">
                                    </x-field>
                                    <x-field label="Value" for="auth-value" :hint="'Use a secret environment variable such as '.$variable('api_key').'.'">
                                        <input id="auth-value" type="password" wire:model.live.blur="form.auth.value" autocomplete="new-password" class="{{ $mono }}">
                                    </x-field>
                                    <x-field label="Add to" for="auth-in">
                                        <select id="auth-in" wire:model.live="form.auth.in" class="{{ $input }}">
                                            <option value="header">Header</option>
                                            <option value="query">Query params</option>
                                        </select>
                                    </x-field>
                                @elseif ($form['auth']['type'] === 'inherit')
                                    <p class="text-zinc-600">Uses the folder's or the collection's auth{{ $summary['auth'] ? ' (collection: '.$summary['auth'].')' : '' }}.</p>
                                @endif
                            @endif
                        </div>

                        {{-- Headers --}}
                        <div role="tabpanel" id="panel-headers" aria-labelledby="tab-headers" x-show="tab === 'headers'" x-cloak>
                            @include('livewire.partials.key-value-rows', ['list' => 'headers', 'rows' => $form['headers'], 'addLabel' => 'Add header', 'empty' => 'No headers. Postman CLI adds User-Agent, Accept and the like itself.'])
                        </div>

                        {{-- Body --}}
                        <div role="tabpanel" id="panel-body" aria-labelledby="tab-body" x-show="tab === 'body'" x-cloak class="text-sm">
                            @if ($form['body']['mode'] === 'other')
                                <p class="rounded border border-zinc-200 bg-zinc-50 px-3 py-2 text-zinc-700">
                                    This body is <strong>{{ $form['body']['other'] }}</strong>, which this editor does not change. It is kept as it is; edit it in Postman and use Replace file.
                                </p>
                            @else
                                <fieldset class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <legend class="sr-only">Body type</legend>
                                    @foreach (['none' => 'none', 'raw' => 'raw', 'urlencoded' => 'x-www-form-urlencoded', 'formdata' => 'form-data (text)'] as $mode => $label)
                                        <label class="inline-flex items-center gap-1.5">
                                            <input type="radio" wire:model.live="form.body.mode" value="{{ $mode }}"> {{ $label }}
                                        </label>
                                    @endforeach
                                    @if ($form['body']['mode'] === 'raw')
                                        <label for="body-language" class="sr-only">Language</label>
                                        <select id="body-language" wire:model.live="form.body.language" class="rounded border border-zinc-300 bg-surface px-2 py-1 text-xs">
                                            @foreach ($languages as $language)
                                                <option value="{{ $language }}">{{ strtoupper($language) }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </fieldset>
                                @if ($form['body']['mode'] === 'raw')
                                    <label for="body-raw" class="sr-only">Raw body</label>
                                    <textarea id="body-raw" wire:model.live.blur="form.body.raw" rows="12" spellcheck="false" class="{{ $mono }} resize-y leading-relaxed"></textarea>
                                @elseif (in_array($form['body']['mode'], ['urlencoded', 'formdata'], true))
                                    @include('livewire.partials.key-value-rows', ['list' => 'body.fields', 'rows' => $form['body']['fields'], 'addLabel' => 'Add field', 'empty' => 'No fields.'])
                                @else
                                    <p class="text-zinc-600">This request sends no body.</p>
                                @endif
                            @endif
                        </div>

                        {{-- Scripts --}}
                        <div role="tabpanel" id="panel-scripts" aria-labelledby="tab-scripts" x-show="tab === 'scripts'" x-cloak class="grid gap-3 text-sm lg:grid-cols-2">
                            <div>
                                <label for="script-prerequest" class="mb-1 block font-medium">Pre-request</label>
                                <textarea id="script-prerequest" wire:model.live.blur="form.prerequest" rows="14" spellcheck="false" class="{{ $mono }} resize-y leading-relaxed" placeholder="pm.environment.set('now', Date.now());"></textarea>
                            </div>
                            <div>
                                <label for="script-test" class="mb-1 block font-medium">Post-response</label>
                                <textarea id="script-test" wire:model.live.blur="form.test" rows="14" spellcheck="false" class="{{ $mono }} resize-y leading-relaxed" placeholder="pm.test('status is 200', () => pm.response.to.have.status(200));"></textarea>
                            </div>
                            <p class="text-xs text-zinc-500 lg:col-span-2">
                                Postman CLI runs these scripts (pm.*). Values set with <code>pm.environment.set()</code> are saved to the selected environment after Send (and after a Runner run with "Keep variable values"),
                                so Send Login once and the next requests can use <code>@{{token}}</code>. <code>pm.collectionVariables.set()</code> and <code>pm.globals.set()</code> are not kept — use <code>pm.environment.set()</code>.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Send result --}}
                @if ($sendRun)
                    <div class="mt-4" @if ($sendRun->status->isActive()) wire:poll.1s @endif>
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-sm" aria-live="polite">
                            <h3 class="font-semibold">Response</h3>
                            <x-status-badge :status="$sendRun->status" :no-tests="$sendRun->hasNoTests()" />
                            @if ($sendRun->status->isActive())
                                <span class="text-zinc-600">Sending… (the runner starts Postman CLI, about 2 s)</span>
                            @endif
                            <a href="{{ route('runs.show', $sendRun) }}" class="ml-auto text-accent-700 hover:underline">Open run →</a>
                        </div>
                        @include('livewire.partials.kept-variables', ['run' => $sendRun])
                        @if ($sendRun->error_message)
                            <p role="alert" class="mb-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $sendRun->error_message }}</p>
                        @endif
                        @foreach ($sendRun->requests as $sent)
                            <x-request-card :request="$sent" />
                        @endforeach
                    </div>
                @endif
            @elseif ($pane === 'runner')
                <div class="rounded border border-zinc-200 bg-surface p-4 text-sm">
                    <h2 class="mb-1 font-semibold">Run sequence</h2>
                    <p class="mb-3 text-zinc-600">
                        Every request is ticked, in collection order. Untick what you don't need and drag (or use ↑ ↓) to change the order.
                        This applies to this run only; the collection is not changed.
                    </p>

                    @if ($sequence === [])
                        <p class="text-zinc-600">This collection has no requests.</p>
                    @else
                        <div class="mb-2 flex flex-wrap gap-1 text-xs">
                            <button type="button" wire:click="tickAll(true)" class="rounded px-2 py-1 text-accent-700 hover:bg-zinc-100">Select all</button>
                            <button type="button" wire:click="tickAll(false)" class="rounded px-2 py-1 text-accent-700 hover:bg-zinc-100">Deselect all</button>
                            <button type="button" wire:click="resetSequence" class="rounded px-2 py-1 text-accent-700 hover:bg-zinc-100">Reset</button>
                        </div>

                        <ol wire:sort="sortSequence" class="mb-4 divide-y divide-zinc-100 rounded border border-zinc-200" aria-label="Requests in run order">
                            @foreach ($sequence as $i => $row)
                                <li wire:key="seq-{{ $row['id'] }}" wire:sort:item="{{ $row['id'] }}" class="flex items-center gap-2 bg-surface px-2 py-1.5">
                                    <span wire:sort:handle class="cursor-grab touch-none select-none px-2 py-1 text-zinc-400" aria-hidden="true" title="Drag to reorder">⠿</span>
                                    <span class="min-w-8 shrink-0 text-right tabular-nums text-zinc-500">{{ $i + 1 }}</span>
                                    <input type="checkbox" id="seq-{{ $i }}" wire:model.live="sequence.{{ $i }}.checked" class="shrink-0">
                                    <label for="seq-{{ $i }}" class="flex min-w-0 flex-1 items-center gap-2">
                                        <span class="min-w-12 shrink-0 font-mono text-[0.65rem] font-semibold {{ $methodColour($row['method']) }}">{{ $row['method'] }}</span>
                                        <span class="line-clamp-2 min-w-0 flex-1 wrap-anywhere lg:truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                        @if ($row['folder'])
                                            <span class="hidden max-w-[40%] shrink-0 truncate text-xs text-zinc-500 sm:inline" title="{{ $row['folder'] }}">in {{ $row['folder'] }}</span>
                                        @endif
                                    </label>
                                    <button type="button" wire:click="moveInSequence({{ $i }}, -1)" @disabled($i === 0) class="rounded px-1.5 py-0.5 text-zinc-600 hover:bg-zinc-100 disabled:opacity-30" aria-label="Move {{ $row['name'] }} up">↑</button>
                                    <button type="button" wire:click="moveInSequence({{ $i }}, 1)" @disabled($i === count($sequence) - 1) class="rounded px-1.5 py-0.5 text-zinc-600 hover:bg-zinc-100 disabled:opacity-30" aria-label="Move {{ $row['name'] }} down">↓</button>
                                </li>
                            @endforeach
                        </ol>

                        <div class="sticky bottom-0 -mx-4 -mb-4 border-t border-zinc-200 bg-surface px-4 py-3">
                        <label class="mb-2 flex items-start gap-2">
                            <input type="checkbox" wire:model="keepVariables" class="mt-1">
                            <span><span class="font-medium">Keep variable values</span><br><span class="text-xs text-zinc-600">Saves what scripts set with pm.environment.set() — e.g. a login token — to {{ $selectedEnvironment?->name ?? 'the environment' }}, so later Sends can use it.</span></span>
                        </label>
                        <label class="mb-3 flex items-start gap-2">
                            <input type="checkbox" wire:model="bail" class="mt-1">
                            <span><span class="font-medium">Stop run if an error occurs</span><br><span class="text-xs text-zinc-600">Stops after the first request whose test fails or that cannot be sent.</span></span>
                        </label>

                        @error('sequence') <p role="alert" class="mb-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        @error('confirmProduction') <p role="alert" class="mb-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        @error('run') <p role="alert" class="mb-2 text-sm font-medium text-red-700">{{ $message }}</p> @enderror
                        <button type="button" wire:click="runSequence" @disabled($environments->isEmpty() || $checkedCount === 0)
                                class="rounded bg-primary px-4 py-1.5 font-medium text-white hover:bg-primary-hover disabled:opacity-60" wire:loading.attr="disabled" wire:target="runSequence">
                            <span aria-hidden="true">▶</span> Run {{ $checkedCount }} of {{ count($sequence) }} {{ Str::plural('request', count($sequence)) }}
                        </button>
                        <span class="ml-2 wrap-anywhere text-xs text-zinc-500">on {{ $selectedEnvironment?->name ?? 'no environment' }}</span>
                        </div>
                    @endif
                </div>
            @else
                <div class="rounded border border-zinc-200 bg-surface p-4 text-sm">
                    <h2 class="mb-3 font-semibold">Overview</h2>
                    <dl class="mb-4 grid grid-cols-[8rem_minmax(0,1fr)] gap-x-3 gap-y-1">
                        <dt class="text-zinc-500">File</dt><dd class="wrap-anywhere">{{ $collection->original_filename }} ({{ $collection->schema_version }})</dd>
                        <dt class="text-zinc-500">Requests</dt><dd>{{ $requestCount }}</dd>
                        <dt class="text-zinc-500">Auth</dt><dd>{{ $summary['auth'] ?? 'none' }}</dd>
                        <dt class="text-zinc-500">Updated</dt><dd><time datetime="{{ $collection->updated_at->toIso8601String() }}" title="{{ $collection->updated_at->format('Y-m-d H:i:s T') }}">{{ $collection->updated_at->diffForHumans() }}</time></dd>
                    </dl>
                    <p class="mb-4 text-xs text-zinc-500">Collection-level auth, scripts and variables are shown here read-only. Change them in Postman and use Replace file on the Collections page.</p>

                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">Variables</h3>
                    @if ($summary['variables'] === [])
                        <p class="mb-4 text-zinc-600">None. Environment variables are set per environment.</p>
                    @else
                        <table class="mb-4 w-full table-fixed border-collapse text-xs">
                            <tbody class="divide-y divide-zinc-100">
                                @foreach ($summary['variables'] as $variable)
                                    <tr>
                                        <th scope="row" class="w-1/3 py-1 pr-2 text-left font-mono font-medium wrap-anywhere">{{ $variable['key'] }}</th>
                                        <td class="py-1 font-mono wrap-anywhere">{{ preg_match(\App\Models\EnvironmentVariable::SECRET_KEY_PATTERN, $variable['key']) && $variable['value'] !== '' ? '********' : $variable['value'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @foreach (['prerequest' => 'Pre-request script', 'test' => 'Post-response script'] as $key => $label)
                        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</h3>
                        @if (trim($summary[$key]) === '')
                            <p class="mb-4 text-zinc-600">None.</p>
                        @else
                            <pre class="mb-4 max-h-64 overflow-auto whitespace-pre-wrap wrap-anywhere rounded bg-code p-3 font-mono text-xs text-code-fg">{{ $summary[$key] }}</pre>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
