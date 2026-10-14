{{--
    Open tabs, Postman-style (D-040). Pages announce themselves with a
    `tab-open` event; the list lives in the browser per workspace
    (resources/js/app.js, workspaceTabs). Middle-click or × closes a tab.
--}}
@props(['workspace'])

<div x-data="workspaceTabs({{ $workspace->id }}, @js(route('projects.show', $workspace)))"
     x-on:tab-open.window="open($event.detail)"
     class="sticky top-12 z-20 flex h-10 items-stretch border-b border-zinc-200 bg-surface text-sm" x-show="tabs.length" x-cloak>
    <button type="button" x-on:click="scroll(-1)" class="shrink-0 px-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900" aria-label="Scroll tabs left">‹</button>
    <div x-ref="strip" role="tablist" aria-label="Open tabs" class="tab-strip flex min-w-0 flex-1 overflow-x-auto">
        <template x-for="tab in tabs" :key="tab.key">
            <div class="group relative flex max-w-[13rem] shrink-0 items-center border-r border-zinc-200"
                 x-bind:data-active="tab.key === active"
                 x-bind:class="tab.key === active ? 'bg-zinc-50 text-zinc-900' : 'text-zinc-600 hover:bg-zinc-50'">
                <span x-show="tab.key === active" class="absolute inset-x-0 top-0 h-0.5 bg-accent-600"></span>
                <a x-bind:href="tab.url" role="tab" x-bind:aria-selected="tab.key === active" x-bind:title="tab.label"
                   x-on:click.prevent="go(tab)" x-on:auxclick.prevent="$event.button === 1 && close(tab)"
                   class="flex min-w-0 items-center gap-1.5 py-2 pl-3 pr-1">
                    <template x-if="tab.kind === 'request'">
                        <span class="shrink-0 font-mono text-[0.6rem] font-semibold"
                              x-bind:class="{ GET: 'text-green-800', POST: 'text-amber-900', PUT: 'text-accent-700', PATCH: 'text-accent-700', DELETE: 'text-red-800' }[tab.method] ?? 'text-zinc-700'"
                              x-text="tab.method"></span>
                    </template>
                    <template x-if="tab.kind === 'runner'"><x-icon name="play" class="size-4 text-zinc-500" /></template>
                    <template x-if="tab.kind === 'collection'"><x-icon name="collection" class="size-4 text-zinc-500" /></template>
                    <template x-if="tab.kind === 'environment'"><x-icon name="environment" class="size-4 text-zinc-500" /></template>
                    <template x-if="tab.kind === 'run'"><x-icon name="history" class="size-4 text-zinc-500" /></template>
                    <span class="truncate" x-text="tab.label"></span>
                </a>
                <span x-show="tab.unsaved" class="size-2 shrink-0 rounded-full bg-orange-500" title="Unsaved changes" aria-label="Unsaved changes"></span>
                <button type="button" x-on:click="close(tab)" x-bind:aria-label="'Close ' + tab.label"
                        x-bind:class="tab.key === active ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus:opacity-100'"
                        class="mx-1 shrink-0 rounded px-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900">×</button>
            </div>
        </template>
    </div>
    <button type="button" x-on:click="scroll(1)" class="shrink-0 px-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900" aria-label="Scroll tabs right">›</button>
    <details class="relative shrink-0 border-l border-zinc-200" x-on:click.outside="$el.open = false">
        <summary class="flex h-full cursor-pointer list-none items-center px-2 text-zinc-500 hover:bg-zinc-100 [&::-webkit-details-marker]:hidden" aria-label="Tab actions"><x-icon name="chevron-down" class="size-4" /></summary>
        <div class="absolute right-0 z-40 mt-1 grid w-44 rounded border border-zinc-200 bg-surface py-1 text-xs shadow-lg">
            <button type="button" x-on:click="closeOthers(); $el.closest('details').open = false" class="px-3 py-1.5 text-left hover:bg-zinc-100">Close other tabs</button>
            <button type="button" x-on:click="closeAll()" class="px-3 py-1.5 text-left hover:bg-zinc-100">Close all tabs</button>
        </div>
    </details>
</div>
