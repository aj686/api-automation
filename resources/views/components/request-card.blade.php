{{--
    One executed request, Postman-style (decisions.md D-037). Everything shown
    was redacted before it was stored. The response body is visible straight
    away; failed and errored requests open with every section stacked. Tabs,
    "Show all" and "Copy" are Alpine (bundled with Livewire): no server round
    trip. Reviewed by the ui-reviewer agent (worst-case data, light/dark).
--}}
@props(['request'])

@php
    $errored = $request->error_message !== null;
    $tests = $request->results->whereNotNull('assertion');
    $testsTotal = $tests->count();
    $testsPassed = $tests->where('passed', true)->count();
    $passed = $request->passed();
    $noTests = $passed && $testsTotal === 0;
    // Without tests nothing fails the request, so an HTTP error would pass silently.
    $httpError = $request->response_code !== null && $request->response_code >= 400;
    $open = ! $passed;
    $bytes = fn (?int $n) => match (true) {
        $n === null => null,
        $n < 1024 => $n.' B',
        $n < 1_048_576 => number_format($n / 1024, 1).' KB',
        default => number_format($n / 1_048_576, 1).' MB',
    };
    $responseBody = $request->prettyResponseBody();
    $requestBody = $request->prettyRequestBody();
    // Capped when tall or long: a body cut at 64 KB is often one minified line.
    $collapsible = $responseBody !== null && (substr_count($responseBody, "\n") >= 30 || mb_strlen($responseBody) > 4000);
    $purged = $request->payload_purged_at !== null;
    $methodColour = match (strtoupper((string) $request->method)) {
        'GET' => 'text-green-800',
        'POST' => 'text-amber-900',
        'PUT', 'PATCH' => 'text-accent-700',
        'DELETE' => 'text-red-800',
        default => 'text-zinc-700',
    };
    $tabs = ['body' => 'Response body', 'headers' => 'Response headers', 'request' => 'Request'];
    $id = 'rq'.$request->id;
    $pre = 'whitespace-pre-wrap wrap-anywhere rounded bg-code p-3 font-mono text-xs leading-relaxed text-code-fg';
@endphp

<article {{ $attributes->class(['rounded border bg-surface', 'border-zinc-200' => $passed, 'border-red-300' => ! $passed && ! $errored, 'border-amber-300' => $errored]) }}
         wire:key="request-{{ $request->id }}"
         x-on:cards-fold.window="folded = $event.detail.folded"
         x-data="{
             tab: 'body', open: @js($open), expanded: @js($open), copied: false, folded: false,
             move(step) {
                 const keys = @js(array_keys($tabs));
                 this.tab = keys[(keys.indexOf(this.tab) + step + keys.length) % keys.length];
                 this.$nextTick(() => this.$root.querySelector('[data-tab=' + this.tab + ']').focus());
             },
             copy() {
                 const text = this.$refs.body.innerText;
                 const done = () => { this.copied = true; setTimeout(() => this.copied = false, 1500); };
                 if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
                 {{-- http:// is not a secure context: fall back to selecting the text and copying it. --}}
                 const range = document.createRange(); range.selectNodeContents(this.$refs.body);
                 const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
                 document.execCommand('copy'); sel.removeAllRanges(); done();
             },
         }">
    {{-- Accordion trigger: the whole header line folds the card (all start open). --}}
    <h3 class="text-sm">
    <button type="button" class="flex w-full flex-wrap items-baseline gap-x-3 gap-y-1 rounded px-4 py-3 text-left hover:bg-zinc-50"
            aria-expanded="true" aria-controls="{{ $id }}-content"
            x-bind:aria-expanded="! folded" x-on:click="folded = ! folded">
        <span aria-hidden="true" class="inline-block w-3 text-zinc-500 transition-transform duration-150" x-bind:class="folded ? '-rotate-90' : ''">▾</span>
        @if ($noTests && $httpError)
            <span class="text-sm font-semibold text-amber-900" title="This request has no pm.test(), so nothing failed it — but the server answered {{ $request->response_code }}."><span aria-hidden="true">⚠</span> No tests · HTTP {{ $request->response_code }}</span>
        @elseif ($noTests)
            <span class="text-sm font-semibold text-zinc-700" title="This request has no pm.test() in its Post-response script, so nothing checked the response."><span aria-hidden="true">○</span> No tests</span>
        @elseif ($passed)
            <span class="text-sm font-semibold text-green-800"><span aria-hidden="true">✓</span> Passed</span>
        @elseif ($errored)
            <span class="text-sm font-semibold text-amber-900"><span aria-hidden="true">⚠</span> Error</span>
        @else
            <span class="text-sm font-semibold text-red-800"><span aria-hidden="true">✕</span> Failed</span>
        @endif
        <span class="font-mono text-xs font-semibold {{ $methodColour }}">{{ $request->method }}</span>
        <span class="min-w-0 flex-1">
            <span class="block font-medium wrap-anywhere">{{ $request->item_name }}</span>
            <span class="line-clamp-2 break-all font-mono text-xs font-normal text-zinc-500" title="{{ $request->url }}">{{ $request->url }}</span>
        </span>
        <span class="flex flex-wrap gap-x-3 font-mono text-xs tabular-nums text-zinc-600">
            <span>{{ $request->response_code ? $request->response_code.' '.$request->response_status : 'no response' }}</span>
            @if ($request->response_time_ms !== null)<span>{{ $request->response_time_ms }} ms</span>@endif
            @if ($request->response_size !== null)<span>{{ $bytes($request->response_size) }}</span>@endif
            @if ($testsTotal)<span>{{ $testsPassed }}/{{ $testsTotal }} tests</span>@endif
        </span>
    </button>
    </h3>

    <div id="{{ $id }}-content" x-show="! folded">

    @if ($noTests)
        <p class="border-t border-zinc-100 px-4 py-2 text-xs {{ $httpError ? 'text-amber-900' : 'text-zinc-600' }}">
            No tests: this request's Post-response script has no <code>pm.test(…)</code>, so nothing checked the response{{ $httpError ? ' and the HTTP '.$request->response_code.' did not fail it' : '' }}.
            Add one, e.g. <code>pm.test("status is 200", () => pm.response.to.have.status(200));</code>
        </p>
    @endif

    @if ($testsTotal || $errored)
        @php($longPassingList = ! $open && $testsTotal > 5)
        <div class="border-t border-zinc-100 px-4 py-2 text-sm">
            @if ($longPassingList)<details><summary class="cursor-pointer text-zinc-600">Tests ({{ $testsTotal }}, all passed)</summary>@endif
            <ul class="space-y-1 wrap-anywhere @if ($longPassingList) mt-1 @endif">
                @if ($errored)
                    <li class="text-amber-900"><span aria-hidden="true">⚠</span><span class="sr-only">Error:</span> {{ $request->error_message }}</li>
                @endif
                @foreach ($tests as $result)
                    <li>
                        @if ($result->passed)
                            <span class="text-green-800" aria-hidden="true">✓</span><span class="sr-only">Passed:</span> {{ $result->assertion }}
                        @else
                            <span class="text-red-800" aria-hidden="true">✕</span><span class="sr-only">Failed:</span> {{ $result->assertion }}
                            @if ($result->error_message)
                                <span class="block pl-5 text-xs text-red-800">{{ $result->error_message }}</span>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($longPassingList)</details>@endif
        </div>
    @endif

    @if ($purged)
        <p class="border-t border-zinc-100 px-4 py-2 text-xs text-zinc-500">Payload deleted after {{ config('automation.payload_retention_days') }} days.</p>
    @else
        <div class="border-t border-zinc-100">
            @unless ($open)
                {{-- WAI-ARIA tabs: arrow keys move between tabs, only the active tab is in the Tab order.
                     Active style is server-rendered (data-active) so nothing flashes before Alpine starts. --}}
                <div role="tablist" aria-label="Payload" class="flex gap-1 px-4 pt-2 text-xs"
                     x-on:keydown.right.prevent="move(1)" x-on:keydown.left.prevent="move(-1)">
                    @foreach ($tabs as $key => $label)
                        <button type="button" role="tab" id="{{ $id }}-tab-{{ $key }}" aria-controls="{{ $id }}-{{ $key }}"
                                data-tab="{{ $key }}" data-active="{{ $key === 'body' ? 'true' : 'false' }}"
                                aria-selected="{{ $key === 'body' ? 'true' : 'false' }}" tabindex="{{ $key === 'body' ? 0 : -1 }}"
                                x-on:click="tab = '{{ $key }}'"
                                x-bind:data-active="tab === '{{ $key }}'" x-bind:aria-selected="tab === '{{ $key }}'"
                                x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                                class="rounded px-2 py-1 font-medium data-[active=true]:bg-primary data-[active=true]:text-white data-[active=false]:text-zinc-700 data-[active=false]:hover:bg-zinc-200">{{ $label }}</button>
                    @endforeach
                </div>
            @endunless

            {{-- Response body: visible at once; tall or long bodies are capped until "Show all". --}}
            <section x-show="open || tab === 'body'" class="px-4 py-2"
                     @if ($open) aria-label="Response body" @else role="tabpanel" id="{{ $id }}-body" aria-labelledby="{{ $id }}-tab-body" @endif>
                @if ($open)<h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">Response body</h4>@endif
                @if ($request->response_body_binary)
                    <p class="text-xs text-zinc-500">Binary body ({{ $request->response_content_type ?? 'unknown type' }}{{ $request->response_size !== null ? ', '.$bytes($request->response_size) : '' }}) — not stored.</p>
                @elseif ($responseBody === null || $responseBody === '')
                    <p class="text-xs text-zinc-500">No body.</p>
                @else
                    <div class="group" @if ($collapsible) data-expanded="{{ $open ? 'true' : 'false' }}" x-bind:data-expanded="expanded" @endif>
                        <pre x-ref="body" class="{{ $pre }} overflow-auto @if ($collapsible) group-data-[expanded=false]:max-h-[calc(30lh+1.5rem)] group-data-[expanded=false]:overflow-hidden group-data-[expanded=false]:mask-b-from-80% group-data-[expanded=true]:max-h-[40rem] @else max-h-[40rem] @endif">{{ $responseBody }}</pre>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-3 text-xs text-zinc-500">
                        <button type="button" x-on:click="copy()" x-text="copied ? 'Copied' : 'Copy'" class="text-accent-700 hover:underline">Copy</button>
                        @if ($collapsible)
                            <button type="button" x-on:click="expanded = ! expanded"
                                    x-text="expanded ? 'Show less' : @js('Show all ('.$bytes(strlen($responseBody)).')')"
                                    class="text-accent-700 hover:underline">Show all ({{ $bytes(strlen($responseBody)) }})</button>
                        @endif
                        @if ($request->response_body_truncated)
                            <span>Cut at {{ $bytes(config('automation.payload_max_bytes')) }}.</span>
                        @endif
                    </div>
                @endif
            </section>

            <section x-show="open || tab === 'headers'" @unless ($open) x-cloak role="tabpanel" id="{{ $id }}-headers" aria-labelledby="{{ $id }}-tab-headers" @else aria-label="Response headers" @endunless class="px-4 py-2">
                @if ($open)<h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">Response headers</h4>@endif
                <x-header-table :headers="$request->response_headers" />
            </section>

            <section x-show="open || tab === 'request'" @unless ($open) x-cloak role="tabpanel" id="{{ $id }}-request" aria-labelledby="{{ $id }}-tab-request" @else aria-label="Request" @endunless class="px-4 py-2">
                <h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">Request</h4>
                <p class="mb-2 break-all font-mono text-xs"><span class="font-semibold {{ $methodColour }}">{{ $request->method }}</span> {{ $request->url }}</p>
                <h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500">Request headers</h4>
                <x-header-table :headers="$request->request_headers" />
                <h4 class="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">Request body</h4>
                @if ($requestBody === null || $requestBody === '')
                    <p class="text-xs text-zinc-500">No body.</p>
                @else
                    <pre class="{{ $pre }} max-h-[40rem] overflow-auto">{{ $requestBody }}</pre>
                    @if ($request->request_body_truncated)
                        <p class="mt-1 text-xs text-zinc-500">Cut at {{ $bytes(config('automation.payload_max_bytes')) }}.</p>
                    @endif
                @endif
            </section>
        </div>
    @endif
    </div>
</article>
