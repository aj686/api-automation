<div class="w-full">
    {{-- A poller that only exists while needed, keyed so each wait starts a fresh poll: adding and removing wire:poll on an element that stays was seen to stop polling (D-040). --}}
    @if ($viewing?->enabled) <span hidden wire:poll.5s wire:key="poll-{{ $viewing->id }}"></span> @endif
    <x-flash />

    <h1 class="mb-4 text-lg font-semibold">Capture URLs</h1>

    <p class="mb-4 text-sm text-zinc-600">
        For testing calls <em>your</em> API makes to others (webhooks, payment gateways). Point your API's outgoing URL
        at a capture URL in a test environment, trigger the action, then assert on what arrived — in Postman via
        <code>GET /api/v1/captures/{{ $project->slug }}/&lt;bucket&gt;?since=@{{run_started_at}}</code>.
        Never use this for production.
    </p>

    @if ($revealedPath)
        <section role="alert" class="mb-6 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
            <p class="mb-2 font-semibold"><span aria-hidden="true">⚠</span> Copy this URL now — it is shown only once (only a hash of its token is stored).</p>
            <dl class="grid grid-cols-[11rem_1fr] gap-x-3 gap-y-2">
                <dt class="text-amber-900">From your PC / browser</dt>
                <dd><code class="break-all rounded bg-surface px-1.5 py-0.5">{{ url($revealedPath) }}</code></dd>
                <dt class="text-amber-900">From a Docker container</dt>
                <dd><code class="break-all rounded bg-surface px-1.5 py-0.5">http://nginx{{ $revealedPath }}</code> <span class="text-xs">with header <code>Host: {{ request()->getHost() }}</code>, or the URL above if the container has <code>extra_hosts: {{ request()->getHost() }}:host-gateway</code></span></dd>
                <dt class="text-amber-900">Path only</dt>
                <dd><code class="break-all rounded bg-surface px-1.5 py-0.5">{{ $revealedPath }}</code> <span class="text-xs">— e.g. a secret <code>capture_path</code> variable for Postman</span></dd>
            </dl>
            <p class="mt-2 text-xs">Anything after the token is kept as the captured path, e.g. <code>{{ $revealedPath }}/orders/paid</code>.</p>
        </section>
    @endif

    @if ($buckets->isNotEmpty())
        <table class="mb-6 w-full border-collapse rounded border border-zinc-200 bg-surface text-sm">
            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr>
                    <th scope="col" class="px-3 py-2 font-medium">Bucket</th>
                    <th scope="col" class="px-3 py-2 font-medium">Environment</th>
                    <th scope="col" class="px-3 py-2 font-medium">State</th>
                    <th scope="col" class="px-3 py-2 font-medium">Received</th>
                    <th scope="col" class="px-3 py-2 font-medium"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($buckets as $bucket)
                    <tr wire:key="bucket-{{ $bucket->id }}" @class(['bg-accent-50 shadow-[inset_3px_0_0_var(--color-accent-600)]' => $viewing?->is($bucket)]) @if ($viewing?->is($bucket)) aria-current="true" @endif>
                        <th scope="row" class="px-3 py-2 text-left font-medium">
                            <button type="button" wire:click="view({{ $bucket->id }})" class="text-accent-700 hover:underline">{{ $bucket->name }}</button>
                            <p class="font-mono text-xs font-normal text-zinc-500">{{ $bucket->slug }} · replies {{ $bucket->response_status }} · keeps {{ $bucket->expires_after_hours }} h</p>
                        </th>
                        <td class="px-3 py-2">{{ $bucket->environment->name }} <x-environment-badge :type="$bucket->environment->type" /></td>
                        <td class="px-3 py-2">
                            @if ($bucket->enabled)
                                <span class="rounded bg-green-50 px-1.5 py-0.5 text-xs font-semibold text-green-800 ring-1 ring-inset ring-green-600/30"><span aria-hidden="true">●</span> Enabled</span>
                            @else
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-semibold text-zinc-700 ring-1 ring-inset ring-zinc-500/30">Disabled</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ $bucket->requests_count }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right text-xs">
                            <button type="button" wire:click="setEnabled({{ $bucket->id }}, {{ $bucket->enabled ? 'false' : 'true' }})" class="rounded px-2 py-1 text-zinc-700 hover:bg-zinc-100">{{ $bucket->enabled ? 'Disable' : 'Enable' }}</button>
                            <button type="button" wire:click="reissueToken({{ $bucket->id }})" wire:confirm="Issue a new URL for {{ $bucket->name }}? The current URL stops working." class="rounded px-2 py-1 text-accent-700 hover:bg-zinc-100">New URL</button>
                            <button type="button" wire:click="deleteBucket({{ $bucket->id }})" wire:confirm="Delete {{ $bucket->name }} and everything it received?" class="rounded px-2 py-1 text-red-700 hover:bg-red-50">Delete</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($viewing)
        <section aria-labelledby="received" class="mb-6 rounded border border-zinc-200 bg-surface p-4 text-sm">
            <div class="mb-3 flex items-center gap-3">
                <h2 id="received" class="font-semibold">Received by {{ $viewing->name }}</h2>
                <span class="text-xs text-zinc-500">latest 20, newest first{{ $viewing->enabled ? ' · refreshing every 5 s' : '' }}</span>
                @if ($requests->isNotEmpty())
                    <button type="button" wire:click="clearRequests({{ $viewing->id }})" wire:confirm="Delete everything {{ $viewing->name }} received?" class="ml-auto rounded px-2 py-1 text-xs text-red-700 hover:bg-red-50">Clear</button>
                @endif
            </div>

            @forelse ($requests as $captured)
                <details wire:key="captured-{{ $captured->id }}" class="border-t border-zinc-100 py-2">
                    <summary class="cursor-pointer">
                        <span class="font-mono font-semibold">{{ $captured->method }}</span>
                        <span class="font-mono">{{ $captured->path }}{{ $captured->query ? '?'.$captured->query : '' }}</span>
                        <time datetime="{{ $captured->received_at->toIso8601String() }}" class="ml-2 text-zinc-500">{{ $captured->received_at->diffForHumans() }}</time>
                    </summary>
                    <div class="mt-2 grid gap-2">
                        <table class="w-full text-xs">
                            @foreach ($captured->headers as $header => $value)
                                <tr><th scope="row" class="w-56 py-0.5 pr-3 text-left align-top font-mono font-normal text-zinc-500">{{ $header }}</th><td class="break-all font-mono">{{ $value }}</td></tr>
                            @endforeach
                        </table>
                        @if ($captured->body !== null)
                            <pre class="max-h-80 overflow-auto rounded bg-code p-3 text-xs text-code-fg">{{ $captured->body }}</pre>
                            @if ($captured->body_truncated)
                                <p class="text-xs text-amber-900">Body cut at {{ number_format(\App\Services\CaptureRecorder::MAX_BODY_BYTES / 1024) }} KB.</p>
                            @endif
                        @endif
                    </div>
                </details>
            @empty
                <p class="text-zinc-500">Nothing received yet.{{ $viewing->enabled ? '' : ' This bucket is disabled: calls to it get 404 and are not stored.' }}</p>
            @endforelse
        </section>
    @endif

    <section aria-labelledby="new-bucket" class="rounded border border-zinc-200 bg-surface p-4">
        <h2 id="new-bucket" class="mb-3 font-semibold">New capture URL</h2>

        @if ($environments->isEmpty())
            <p class="text-sm text-zinc-600">This project has no non-production environment. Capture URLs cannot be created for production.</p>
        @else
            <form wire:submit="createBucket" class="grid max-w-lg gap-3 text-sm">
                <x-field label="Name" for="name" :error="$errors->first('name')">
                    <input id="name" type="text" wire:model="name" required maxlength="100" autocomplete="off" placeholder="Payment webhook" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                </x-field>
                <x-field label="Slug" for="slug" :error="$errors->first('slug')" hint="Used in the captures API. Leave empty to build it from the name.">
                    <input id="slug" type="text" wire:model="slug" maxlength="100" autocomplete="off" class="w-full rounded border border-zinc-300 px-2 py-1.5 font-mono">
                </x-field>
                <x-field label="Environment" for="environmentId" :error="$errors->first('environmentId')" hint="Its secret values are redacted from what is captured. Production is not offered.">
                    <x-dropdown id="environmentId" wire:model="environmentId" :options="$environments->mapWithKeys(fn ($e) => [$e->id => ['label' => $e->name, 'hint' => $e->type->value]])->all()" />
                </x-field>
                <div class="grid grid-cols-2 gap-3">
                    <x-field label="Reply with status" for="responseStatus" :error="$errors->first('responseStatus')">
                        <input id="responseStatus" type="number" min="200" max="599" wire:model="responseStatus" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                    </x-field>
                    <x-field label="Keep for (hours)" for="expiresAfterHours" :error="$errors->first('expiresAfterHours')">
                        <input id="expiresAfterHours" type="number" min="1" max="168" wire:model="expiresAfterHours" class="w-full rounded border border-zinc-300 px-2 py-1.5">
                    </x-field>
                </div>
                <div>
                    <button type="submit" class="rounded bg-primary px-3 py-1.5 font-medium text-white hover:bg-primary-hover">Create capture URL</button>
                </div>
            </form>
        @endif
    </section>
</div>
