{{-- One root element, as Livewire requires; empty when the banner has nothing to say. --}}
<div wire:poll.30s>
    @if ($banner)
        @unless ($alive)
            <div role="alert" class="border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-950">
                <span aria-hidden="true">⚠</span>
                <strong>Runner not responding</strong> — runs will stay QUEUED. Check the runner container
                (<code>docker ps --filter name=api-automation-worker</code>).
                @if ($heartbeat)
                    Last seen {{ $heartbeat->beat_at->diffForHumans() }}.
                @else
                    It has never reported in.
                @endif
            </div>
        @endunless
    @else
        <span class="inline-flex items-center gap-1.5 text-sm {{ $alive ? 'text-green-800' : 'text-amber-900' }}">
            <span aria-hidden="true">{{ $alive ? '●' : '⚠' }}</span>{{ $alive ? 'Runner OK' : 'Runner down' }}
        </span>
    @endif
</div>
