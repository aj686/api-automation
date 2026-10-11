@props(['route', 'icon'])

@php($active = request()->routeIs($route))

<a href="{{ route($route) }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->class([
       'sidebar-item flex items-center gap-3 rounded px-2 py-1.5',
       'bg-accent-50 font-medium text-accent-800' => $active,
       'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900' => ! $active,
   ]) }}>
    <x-icon :name="$icon" />
    <span class="sidebar-label truncate">{{ $slot }}</span>
</a>
