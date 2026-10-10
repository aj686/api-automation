{{-- Production is badged everywhere it appears (plan section 6). --}}
@props(['type'])

@php
    $type = $type instanceof \App\Enums\EnvironmentType ? $type : \App\Enums\EnvironmentType::from($type);
@endphp

@if ($type->isProduction())
    <span {{ $attributes->class('inline-flex items-center gap-1 rounded bg-red-600 px-1.5 py-0.5 text-xs font-bold text-white') }}>
        <span aria-hidden="true">⚠</span>PRODUCTION
    </span>
@else
    <span {{ $attributes->class('inline-flex items-center rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium uppercase text-zinc-700 ring-1 ring-inset ring-zinc-500/20') }}>
        {{ $type->value }}
    </span>
@endif
