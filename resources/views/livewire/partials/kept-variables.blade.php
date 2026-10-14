{{-- Names (never values) of what "Keep variable values" saved to the environment (D-039). --}}
@if ($run->kept_variables && ($run->kept_variables['saved'] || $run->kept_variables['skipped']))
    <p class="mb-4 rounded border border-zinc-200 bg-surface px-3 py-2 text-sm text-zinc-700">
        @if ($run->kept_variables['saved'])
            <span class="font-medium">Saved to {{ $run->environment_name }}:</span>
            <span class="font-mono text-xs wrap-anywhere">{{ implode(', ', $run->kept_variables['saved']) }}</span>
        @endif
        @if ($run->kept_variables['skipped'])
            <span class="block text-xs text-amber-900 wrap-anywhere">Not saved (name not allowed or value over 8000 characters): {{ implode(', ', $run->kept_variables['skipped']) }}</span>
        @endif
    </p>
@endif
