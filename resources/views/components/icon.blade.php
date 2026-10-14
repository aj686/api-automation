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
        @case('collection')
            <rect x="3" y="7.5" width="14" height="9" rx="1.5" /><path d="M5 5h10M7 2.5h6" />
            @break
        @case('environment')
            <circle cx="10" cy="10" r="7" /><path d="M3 10h14M10 3c2 2.2 2.8 4.5 2.8 7s-.8 4.8-2.8 7c-2-2.2-2.8-4.5-2.8-7S8 5.2 10 3z" />
            @break
        @case('history')
            <circle cx="10" cy="10" r="7" /><path d="M10 6v4l2.5 1.5" />
            @break
        @case('capture')
            <path d="M3 11.5h3.5l1.5 2.5h4l1.5-2.5H17" /><path d="M4.5 5.5h11L17 11.5V16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-4.5z" />
            @break
        @case('overview')
            <rect x="3" y="3" width="6" height="6" rx="1" /><rect x="11" y="3" width="6" height="6" rx="1" /><rect x="3" y="11" width="6" height="6" rx="1" /><rect x="11" y="11" width="6" height="6" rx="1" />
            @break
        @case('search')
            <circle cx="9" cy="9" r="5" /><path d="m13 13 3.5 3.5" />
            @break
        @case('check')
            <path d="m4.5 10.5 3.5 3.5 7.5-8" />
            @break
        @case('chevron-down')
            <path d="m5.5 8 4.5 4.5L14.5 8" />
            @break
        @case('dots')
            <circle cx="5" cy="10" r=".75" fill="currentColor" /><circle cx="10" cy="10" r=".75" fill="currentColor" /><circle cx="15" cy="10" r=".75" fill="currentColor" />
            @break
    @endswitch
</svg>
