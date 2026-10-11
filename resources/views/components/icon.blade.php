@props(['name'])

{{-- Small outline icon set on a 20×20 grid, drawn here so no icon package is needed. --}}
<svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
     {{ $attributes->class('size-5 shrink-0') }}>
    @switch($name)
        @case('home')
            <path d="M3 9.5 10 4l7 5.5V16a1 1 0 0 1-1 1h-3.5v-4.5h-5V17H4a1 1 0 0 1-1-1z" />
            @break
        @case('folder')
            <path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.5 2h7.5a1 1 0 0 1 1 1V15a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1z" />
            @break
        @case('play')
            <circle cx="10" cy="10" r="7.5" /><path d="M8.5 7.2v5.6l4.5-2.8z" />
            @break
        @case('settings')
            <path d="M3.5 6h8M15.5 6h1M3.5 14h1M8.5 14h8" /><circle cx="13.5" cy="6" r="1.75" /><circle cx="6.5" cy="14" r="1.75" />
            @break
        @case('panel')
            <rect x="2.5" y="3.5" width="15" height="13" rx="2" /><path d="M7.5 3.5v13" />
            @break
        @case('menu')
            <path d="M3 5h14M3 10h14M3 15h14" />
            @break
        @case('plus')
            <path d="M10 4.5v11M4.5 10h11" />
            @break
        @case('monitor')
            <rect x="2.5" y="3.5" width="15" height="10" rx="1.5" /><path d="M7 17h6M10 13.5V17" />
            @break
        @case('sun')
            <circle cx="10" cy="10" r="3" /><path d="M10 2.5V4M10 16v1.5M2.5 10H4M16 10h1.5M4.7 4.7l1 1M14.3 14.3l1 1M4.7 15.3l1-1M14.3 5.7l1-1" />
            @break
        @case('moon')
            <path d="M16 12.5A6.5 6.5 0 0 1 7.5 4a6.5 6.5 0 1 0 8.5 8.5z" />
            @break
    @endswitch
</svg>
