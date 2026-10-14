{{--
    A styled select, like the workspace switcher (D-040): native <select>
    lists cannot be styled. Works with wire:model / wire:model.live through
    x-modelable. Options: [value => label] or [value => ['label' => …,
    'danger' => bool, 'hint' => …, 'class' => …]]. Keyboard: ↑ ↓ Home End,
    Enter or Space to choose, Esc to close — listened for on the window while
    open, so it works even before focus has moved into the list (WAI-ARIA listbox).
--}}
@props(['id', 'options', 'placeholder' => 'Choose…', 'align' => 'left', 'buttonClass' => ''])

@php
    $items = collect($options)->map(fn ($option, $value) => is_array($option)
        ? ['value' => $option['value'] ?? $value, 'label' => (string) $option['label'], 'danger' => (bool) ($option['danger'] ?? false), 'hint' => $option['hint'] ?? null, 'class' => $option['class'] ?? '']
        : ['value' => $value, 'label' => (string) $option, 'danger' => false, 'hint' => null, 'class' => ''])->values()->all();
@endphp

<div x-data="dropdown(@js($items), @js($placeholder), @js($id), @js($align))" x-modelable="value"
     {{ $attributes->whereStartsWith('wire:model') }}
     wire:key="dropdown-{{ $id }}-{{ md5(json_encode($items)) }}"
     x-on:click.outside="open && ! document.getElementById(@js($id.'-list'))?.contains($event.target) && close()"
     x-on:keydown.escape.window="open && ($event.stopPropagation(), close(true))"
     x-on:resize.window="open && close()" x-on:scroll.window.capture="open && ! document.getElementById(@js($id.'-list'))?.contains($event.target) && close()"
     {{ $attributes->whereDoesntStartWith('wire:model')->class('relative min-w-0') }}>
    <button type="button" id="{{ $id }}" x-ref="button" aria-haspopup="listbox" aria-describedby="{{ $id }}-value" x-bind:title="current?.label" x-bind:aria-expanded="open" x-bind:aria-controls="open ? @js($id.'-list') : null"
            x-on:click="toggle()" x-on:keydown.down.prevent="show(1)" x-on:keydown.up.prevent="show(-1)"
            x-bind:class="current?.danger ? 'border-red-300 text-red-900' : 'border-zinc-300'"
            class="flex w-full min-w-0 items-center gap-2 rounded-lg border bg-surface px-2.5 py-1.5 text-left hover:bg-zinc-100 {{ $buttonClass }}">
        <span x-show="current?.danger" x-cloak class="size-2 shrink-0 rounded-full bg-red-600" aria-hidden="true"></span>
        <span id="{{ $id }}-value" class="min-w-0 flex-1 truncate" x-bind:class="current ? current.class : 'text-zinc-500'" x-text="current ? current.label : placeholder"></span>
        <x-icon name="chevron-down" class="size-4 shrink-0 text-zinc-500 transition-transform duration-150" x-bind:class="open && 'rotate-180'" />
    </button>

    {{--
        The open list is teleported to <body>: outside the component, a Livewire
        redraw (e.g. an upload finishing) cannot undo x-show (it did: the list
        stayed visible after a choice), and no card or scrolling panel clips it.
        Positioned under (or above) the button with fixed coordinates.
    --}}
    <template x-teleport="body">
    {{-- Visibility via a style binding, not x-show: x-show hides a frame later, so a quick choice or Esc saw the list still open. --}}
    <ul style="display: none" id="{{ $id }}-list" role="listbox" tabindex="-1" aria-labelledby="{{ $id }}"
        x-bind:style="open ? position : 'display: none'"
        x-bind:aria-activedescendant="active >= 0 ? @js($id.'-opt-') + active : null"
        x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.home.prevent="active = 0" x-on:keydown.end.prevent="active = items.length - 1"
        x-on:keydown.enter.prevent="choose(items[active])" x-on:keydown.space.prevent="choose(items[active])" x-on:keydown.tab="close()"
        class="fixed z-50 max-h-72 w-max max-w-[min(28rem,calc(100vw-1rem))] overflow-y-auto rounded-xl border border-zinc-300 bg-surface py-1 text-sm text-zinc-900 shadow-lg">
        <template x-for="(item, index) in items" :key="String(item.value)">
            <li role="option" x-bind:id="@js($id.'-opt-') + index" x-bind:aria-selected="same(item.value)"
                x-on:click="choose(item)" x-on:mousemove="active = index" x-bind:title="item.label"
                x-bind:class="index === active ? 'bg-accent-50 ring-1 ring-inset ring-accent-600' : ''"
                class="flex cursor-pointer select-none items-center gap-2 whitespace-nowrap px-3 py-1.5">
                <span x-show="item.danger" class="size-2 shrink-0 rounded-full bg-red-600" aria-hidden="true"></span>
                <span class="min-w-0 flex-1 truncate" x-bind:class="[item.class, same(item.value) ? 'font-medium text-accent-800' : ''].join(' ')" x-text="item.label"></span>
                <span x-show="item.danger" class="shrink-0 rounded bg-red-50 px-1 text-[0.65rem] font-semibold text-red-800">PROD</span>
                <span x-show="item.hint" class="shrink-0 text-xs text-zinc-500" x-text="item.hint"></span>
                <x-icon name="check" class="size-4 shrink-0 text-accent-700" x-bind:class="same(item.value) ? '' : 'invisible'" />
            </li>
        </template>
    </ul>
    </template>
</div>
