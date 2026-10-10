{{-- Project sections (master prompt section 16). Unbuilt ones are greyed out, not links. --}}
@props(['project', 'active'])

@php
    $tabs = [
        'overview' => ['Overview', route('projects.show', $project)],
        'environments' => ['Environments', route('projects.environments', $project)],
        'collections' => ['Collections', null, 8],
        'runs' => ['Runs', null, 11],
    ];
@endphp

<nav aria-label="Project sections" class="mb-6 flex gap-1 border-b border-zinc-200 text-sm">
    @foreach ($tabs as $key => $tab)
        @if ($tab[1] === null)
            <span class="px-3 py-2 text-zinc-400" title="Arrives in phase {{ $tab[2] }}">{{ $tab[0] }}</span>
        @else
            <a href="{{ $tab[1] }}"
               @if ($key === $active) aria-current="page" @endif
               @class([
                   '-mb-px border-b-2 px-3 py-2',
                   'border-zinc-900 font-medium text-zinc-900' => $key === $active,
                   'border-transparent text-zinc-600 hover:text-zinc-900' => $key !== $active,
               ])>{{ $tab[0] }}</a>
        @endif
    @endforeach
</nav>
