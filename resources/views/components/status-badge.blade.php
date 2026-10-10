{{--
    Run status as icon + word + colour (plan section 9, master prompt section 76).
    The icon is decorative; the word carries the meaning for screen readers.
--}}
@props(['status', 'noTests' => false])

@php
    $status = $status instanceof \App\Enums\RunStatus ? $status : \App\Enums\RunStatus::from($status);

    $colour = match ($status) {
        \App\Enums\RunStatus::Pass => 'bg-green-50 text-green-800 ring-green-600/30',
        \App\Enums\RunStatus::Fail => 'bg-red-50 text-red-800 ring-red-600/30',
        \App\Enums\RunStatus::Error => 'bg-amber-50 text-amber-900 ring-amber-600/40',
        \App\Enums\RunStatus::Timeout => 'bg-orange-50 text-orange-900 ring-orange-600/30',
        \App\Enums\RunStatus::Running => 'bg-blue-50 text-blue-800 ring-blue-600/30',
        default => 'bg-zinc-100 text-zinc-700 ring-zinc-500/30',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5']) }}>
    <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $colour }}">
        <span aria-hidden="true">{{ $status->icon() }}</span>{{ $status->value }}
    </span>

    @if ($noTests)
        <span class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-semibold text-amber-900 ring-1 ring-inset ring-amber-600/40 bg-amber-50"
              title="Passed with zero assertions — this run checked nothing.">
            <span aria-hidden="true">⚠</span>NO TESTS
        </span>
    @endif
</span>
