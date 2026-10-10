{{--
    Rendered inside each Livewire page, not in the layout: an action that does
    not redirect re-renders only the component, so a message in the layout
    would appear one page load late. Actions that stay on the page use
    session()->now(); actions that redirect use session()->flash().
--}}
@if (session('status'))
    <p role="status" class="mb-4 rounded border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-900">
        {{ session('status') }}
    </p>
@endif
