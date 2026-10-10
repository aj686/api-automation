@props(['route'])

@php($active = request()->routeIs($route))

<a href="{{ route($route) }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->class([
       'rounded px-2.5 py-1.5',
       'bg-zinc-100 font-medium text-zinc-900' => $active,
       'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900' => ! $active,
   ]) }}>{{ $slot }}</a>
