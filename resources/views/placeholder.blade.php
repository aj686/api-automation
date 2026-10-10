{{-- Navigation target for a page a later phase builds; replaced, not extended. --}}
<x-layouts::app :title="$title">
    <h1 class="mb-2 text-lg font-semibold">{{ $title }}</h1>
    <p class="text-sm text-zinc-600">Not built yet — arrives in phase {{ $phase }}.</p>
</x-layouts::app>
