{{--
    A styled select, like the workspace switcher (D-040): native <select>
    lists cannot be styled. Works with wire:model / wire:model.live through
    x-modelable. Options: [value => label] or [value => ['label' => …,
    'danger' => bool, 'hint' => …, 'class' => …]]. Keyboard: ↑ ↓ Home End,
    Enter or Space to choose, Esc to close (WAI-ARIA listbox).
--}}
@props(['id', 'options', 'placeholder' => 'Choose…', 'align' => 'left', 'buttonClass' => ''])

@php
    $items = collect($options)->map(fn ($option, $value) => is_array($option)
        ? ['value' => $option['value'] ?? $value, 'label' => (string) $option['label'], 'danger' => (bool) ($option['danger'] ?? false), 'hint' => $option['hint'] ?? null, 'class' => $option['class'] ?? '']
        : ['value' => $value, 'label' => (string) $option, 'danger' => false, 'hint' => null, 'class' => ''])->values()->all();
@endphp

<div x-data="dropdown(@js($items), @js($placeholder), @js($id))" x-modelable="value"
     {{ $attributes->whereStartsWith('wire:model') }}
     wire:key="dropdown-{{ $id }}-{{ md5(json_encode($items)) }}"
     x-on:click.outside="close()" x-on:keydown.escape="open && ($event.stopPropagation(), close(true))"
     {{ $attributes->whereDoesntStartWith('wire:model')->class('relative min-w-0') }}>
    <button type="button" id="{{ $id }}" x-ref="button" aria-haspopup="listbox" x-bind:aria-expanded="open" x-bind:aria-controls="open ? @js($id.'-list') : null"
            x-on:click="toggle()" x-on:keydown.down.prevent="show(1)" x-on:keydown.up.prevent="show(-1)"
            x-bind:class="current?.danger ? 'border-red-300 text-red-900' : 'border-zinc-300'"
            class="flex w-full min-w-0 items-center gap-2 rounded-lg border bg-surface px-2.5 py-1.5 text-left hover:bg-zinc-50 {{ $buttonClass }}">
        <span x-show="current?.danger" x-cloak class="size-2 shrink-0 rounded-full bg-red-600" aria-hidden="true"></span>
        <span class="min-w-0 flex-1 truncate" x-bind:class="current ? current.class : 'text-zinc-500'" x-text="current ? current.label : placeholder"></span>
        <x-icon name="chevron-down" class="size-4 shrink-0 text-zinc-500 transition-transform duration-150" x-bind:class="open && 'rotate-180'" />
    </button>

    <ul x-show="open" x-cloak x-ref="list" id="{{ $id }}-list" role="listbox" tabindex="-1" aria-labelledby="{{ $id }}"
        x-bind:aria-activedescendant="active >= 0 ? @js($id.'-opt-') + active : null"
        x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.home.prevent="active = 0" x-on:keydown.end.prevent="active = items.length - 1"
        x-on:keydown.enter.prevent="choose(items[active])" x-on:keydown.space.prevent="choose(items[active])" x-on:keydown.tab="close()"
        x-transition:enter="transition duration-100 ease-out" x-transition:enter-start="opacity-0 -translate-y-1"
        @class([
            'absolute z-40 mt-1 max-h-72 min-w-full overflow-y-auto rounded-xl border border-zinc-200 bg-surface py-1 text-sm shadow-lg',
            'left-0' => $align === 'left',
            'right-0' => $align === 'right',
        ])>
        <template x-for="(item, index) in items" :key="String(item.value)">
            <li role="option" x-bind:id="@js($id.'-opt-') + index" x-bind:aria-selected="same(item.value)"
                x-on:click="choose(item)" x-on:mousemove="active = index"
                x-bind:class="index === active ? 'bg-zinc-100' : ''"
                class="flex cursor-pointer select-none items-center gap-2 whitespace-nowrap px-3 py-1.5">
                <span x-show="item.danger" class="size-2 shrink-0 rounded-full bg-red-600" aria-hidden="true"></span>
                <span class="min-w-0 flex-1 truncate" x-bind:class="[item.class, same(item.value) ? 'font-medium text-accent-800' : ''].join(' ')" x-text="item.label"></span>
                <span x-show="item.danger" class="shrink-0 rounded bg-red-50 px-1 text-[0.65rem] font-semibold text-red-800">PROD</span>
                <span x-show="item.hint" class="shrink-0 text-xs text-zinc-500" x-text="item.hint"></span>
                <x-icon name="check" class="size-4 shrink-0 text-accent-700" x-bind:class="same(item.value) ? '' : 'invisible'" />
            </li>
        </template>
    </ul>
</div>
