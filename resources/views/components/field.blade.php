{{-- A labelled form field; the error is tied to the input for screen readers. --}}
@props(['label', 'for', 'error' => null, 'hint' => null])

<div>
    <label for="{{ $for }}" class="mb-1 block font-medium text-zinc-800">{{ $label }}</label>

    {{ $slot }}

    @if ($hint)
        <p class="mt-1 text-xs text-zinc-500">{{ $hint }}</p>
    @endif

    @if ($error)
        <p id="{{ $for }}-error" role="alert" class="mt-1 text-xs font-medium text-red-700">{{ $error }}</p>
    @endif
</div>
