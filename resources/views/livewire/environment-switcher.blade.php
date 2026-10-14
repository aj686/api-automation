<div class="flex min-w-0 items-center gap-2 text-sm">
    @if ($environments->isEmpty())
        <a href="{{ route('projects.environments', $project) }}" class="truncate rounded px-2 py-1 text-accent-700 hover:bg-zinc-100">No environment — create one</a>
    @else
        @php($active = $environments->firstWhere('id', $environmentId))
        <label for="active-environment" class="sr-only">Active environment</label>
        <x-dropdown id="active-environment" wire:model.live="environmentId" :options="$environments->mapWithKeys(fn ($e) => [$e->id => ['label' => $e->name, 'danger' => $e->isProduction()]])->all()" align="right" class="w-[15rem] max-w-[40vw]" button-class="py-1" title="Active environment: Send and Run use it" />
    @endif
</div>
