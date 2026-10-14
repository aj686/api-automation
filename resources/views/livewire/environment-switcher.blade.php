<div class="flex min-w-0 items-center gap-2 text-sm">
    @if ($environments->isEmpty())
        <a href="{{ route('projects.environments', $project) }}" class="truncate rounded px-2 py-1 text-accent-700 hover:bg-zinc-100">No environment — create one</a>
    @else
        @php($active = $environments->firstWhere('id', $environmentId))
        <label for="active-environment" class="sr-only">Active environment</label>
        <span aria-hidden="true" @class(['size-2 shrink-0 rounded-full', 'bg-red-600' => $active?->isProduction(), 'bg-green-600' => $active && ! $active->isProduction()])></span>
        <select id="active-environment" wire:model.live="environmentId" title="Active environment: Send and Run use it"
                @class(['max-w-[14rem] truncate rounded border bg-surface py-1 pl-2 pr-7 text-sm', 'border-red-300 text-red-900' => $active?->isProduction(), 'border-zinc-300' => ! $active?->isProduction()])>
            @foreach ($environments as $environment)
                <option value="{{ $environment->id }}">{{ $environment->name }}{{ $environment->isProduction() ? ' — PRODUCTION' : '' }}</option>
            @endforeach
        </select>
    @endif
</div>
