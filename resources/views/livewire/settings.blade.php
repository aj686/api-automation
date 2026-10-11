@php($appRoot = '/var/www/projects/api-automation/api-automation')
<div class="max-w-3xl space-y-6 text-sm">
    <h1 class="text-lg font-semibold">Settings</h1>
    <p class="text-zinc-600">Read-only. These come from <code>.env</code> in the app root; change them there, then run <code>php artisan config:clear</code>.</p>

    <section aria-labelledby="api" class="rounded border border-zinc-200 bg-white p-4">
        <h2 id="api" class="mb-2 font-semibold">Automation API (n8n)</h2>

        @if ($apiEnabled)
            <p class="mb-2"><span class="rounded bg-green-50 px-1.5 py-0.5 text-xs font-semibold text-green-800 ring-1 ring-inset ring-green-600/30"><span aria-hidden="true">✓</span> Enabled</span>
                <code>/api/v1</code> accepts requests with <code>Authorization: Bearer &lt;token&gt;</code>. The token is not shown here.</p>
            @if ($tokenIsShort)
                <p role="alert" class="mb-2 text-amber-900"><span aria-hidden="true">⚠</span> The token is shorter than {{ \App\Livewire\Settings::MIN_TOKEN_LENGTH }} characters. Generate a new one with <code>--force</code>.</p>
            @endif
        @else
            <p class="mb-2"><span class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-semibold text-zinc-700 ring-1 ring-inset ring-zinc-500/30">Disabled</span>
                No token is set, so every <code>/api/v1</code> request is refused (503).</p>
        @endif

        <p class="mb-1 mt-3 font-medium">{{ $apiEnabled ? 'Replace the token' : 'Turn it on' }} — run in WSL, then paste the printed token into n8n:</p>
        <pre class="overflow-x-auto rounded bg-zinc-900 p-3 text-xs text-zinc-100">docker exec -w {{ $appRoot }} php8.3.fpm php artisan automation:token{{ $apiEnabled ? ' --force' : '' }}
docker exec -w {{ $appRoot }} php8.3.fpm php artisan config:clear</pre>

        <p class="mb-1 mt-3 font-medium">Check it (replace YOUR_ACCESS_TOKEN):</p>
        <pre class="overflow-x-auto rounded bg-zinc-900 p-3 text-xs text-zinc-100">curl -H "Authorization: Bearer YOUR_ACCESS_TOKEN" {{ url('/api/v1/ping') }}</pre>
        <p class="mt-1 text-zinc-600"><code>{"ok":true}</code> means the token works. Calls are limited to 60 per minute.</p>
    </section>

    <section aria-labelledby="runner" class="rounded border border-zinc-200 bg-white p-4">
        <h2 id="runner" class="mb-2 font-semibold">Runner</h2>
        <dl class="grid grid-cols-[12rem_1fr] gap-x-4 gap-y-1">
            <dt class="text-zinc-500">Status</dt>
            <dd>
                {{ $runnerAlive ? 'Responding' : 'Not responding' }}
                @if ($heartbeat) <span class="text-zinc-500">(last seen {{ $heartbeat->beat_at->diffForHumans() }})</span> @endif
            </dd>
            <dt class="text-zinc-500">Container</dt>
            <dd><code>{{ config('automation.worker.name') }}</code></dd>
            <dt class="text-zinc-500">Postman CLI</dt>
            <dd><code>{{ config('automation.postman_cli_path') }}</code> · {{ $cliVersion ? 'version '.$cliVersion.' (last run)' : 'version unknown until the first run' }}</dd>
            <dt class="text-zinc-500">Run time limit</dt>
            <dd>{{ config('automation.run_timeout_seconds') }} s <span class="text-zinc-500">(AUTOMATION_RUN_TIMEOUT_SECONDS)</span></dd>
            <dt class="text-zinc-500">Request time limit</dt>
            <dd>{{ number_format(config('automation.request_timeout_ms') / 1000) }} s per request</dd>
            <dt class="text-zinc-500">Stored log size</dt>
            <dd>up to {{ number_format(config('automation.log_max_bytes') / 1000) }} KB, redacted</dd>
        </dl>
    </section>

    <section aria-labelledby="security" class="rounded border border-zinc-200 bg-white p-4">
        <h2 id="security" class="mb-2 font-semibold">Security</h2>
        <ul class="list-disc space-y-1 pl-5 text-zinc-700">
            <li>Secret variables are encrypted in the database and never shown after saving.</li>
            <li>Run logs and results are redacted before they are stored: the environment's secret values, Authorization and Cookie headers, and values of <code>password=</code>, <code>api_key=</code>, <code>token=</code> and similar. Unknown secrets in other shapes may not be caught.</li>
            <li>The API never returns stack traces or file paths.</li>
            @if ($debug)
                <li class="text-amber-900"><span aria-hidden="true">⚠</span> <code>APP_DEBUG</code> is on: error pages in this UI show stack traces. Fine on your own machine; turn it off anywhere else.</li>
            @endif
            <li class="text-amber-900"><span aria-hidden="true">⚠</span> The UI has no password. Other devices on your network may be able to open it — don't store real credentials until that is settled.</li>
        </ul>
    </section>
</div>
