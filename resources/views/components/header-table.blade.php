{{-- Header list as stored: [{name, value}], already redacted. On phones the name
     column shrinks to a third, so long values (CSP, cookies) keep room to wrap. --}}
@props(['headers'])

@if (empty($headers))
    <p class="text-xs text-zinc-500">No headers.</p>
@else
    <table class="w-full table-fixed text-xs">
        @foreach ($headers as $header)
            <tr>
                <th scope="row" class="w-1/3 break-all py-0.5 pr-3 text-left align-top font-mono font-normal text-zinc-500 sm:w-56">{{ $header['name'] }}</th>
                <td class="break-all font-mono">{{ $header['value'] }}</td>
            </tr>
        @endforeach
    </table>
@endif
