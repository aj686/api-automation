<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        {{--
            Theme: System / Light / Dark, remembered per browser. Inline and
            blocking so the page never paints in the wrong theme first.
            data-theme = what is shown, data-theme-choice = what was picked.
        --}}
        <script>
            (() => {
                const root = document.documentElement;
                const media = matchMedia('(prefers-color-scheme: dark)');
                const valid = (value) => ['system', 'light', 'dark'].includes(value) ? value : 'system';
                let choice = 'system';
                try { choice = valid(localStorage.getItem('theme')); } catch (e) {}

                const apply = () => {
                    root.dataset.themeChoice = choice;
                    root.dataset.theme = choice === 'dark' || (choice === 'system' && media.matches) ? 'dark' : 'light';
                    document.querySelectorAll('[data-theme-option]').forEach((button) => {
                        button.setAttribute('aria-pressed', String(button.dataset.themeOption === choice));
                    });
                };

                const change = (update) => {
                    const run = () => { root.classList.add('theme-switching'); update(); apply(); };
                    // Flush styles while transitions are still off, then turn them back on.
                    const done = () => { void root.offsetHeight; root.classList.remove('theme-switching'); };
                    if (document.startViewTransition && ! matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        document.startViewTransition(run).finished.finally(done);
                    } else {
                        run(); done();
                    }
                };

                apply();
                media.addEventListener('change', () => { if (choice === 'system') change(() => {}); });
                document.addEventListener('DOMContentLoaded', apply);
                addEventListener('storage', (event) => {
                    if (event.key === 'theme') change(() => { choice = valid(event.newValue); });
                });
                document.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-theme-option]');
                    if (! button || button.dataset.themeOption === choice) return;
                    change(() => {
                        choice = button.dataset.themeOption;
                        try { localStorage.setItem('theme', choice); } catch (e) {}
                    });
                });
            })();
        </script>

        {{--
            Projects sidebar: open/closed as data-sidebar on <html>. From md up
            it collapses in place and the choice is remembered; below md it is a
            drawer that always starts closed. Styles in app.css (.sidebar).
        --}}
        <script>
            (() => {
                const root = document.documentElement;
                const wide = matchMedia('(min-width: 48rem)');
                const stored = () => { try { return localStorage.getItem('sidebar') === 'closed' ? 'closed' : 'open'; } catch (e) { return 'open'; } };
                const set = (state) => {
                    const was = root.dataset.sidebar;
                    root.dataset.sidebar = state;
                    document.querySelectorAll('[aria-controls="project-sidebar"]').forEach((button) => {
                        button.setAttribute('aria-expanded', String(state === 'open'));
                    });
                    // Below md the sidebar is a modal drawer: the page behind it is inert,
                    // focus moves into it and back to the toggle when it closes (D-040).
                    const drawer = ! wide.matches && state === 'open';
                    document.querySelectorAll('[data-behind-drawer]').forEach((el) => { el.inert = drawer; });
                    if (! wide.matches && was && was !== state) {
                        if (drawer) document.querySelector('#project-sidebar a, #project-sidebar button')?.focus();
                        else document.querySelector('[data-panel-toggle]')?.focus();
                    }
                };

                set(wide.matches ? stored() : 'closed');
                wide.addEventListener('change', () => set(wide.matches ? stored() : 'closed'));
                document.addEventListener('DOMContentLoaded', () => set(root.dataset.sidebar));
                document.addEventListener('click', (event) => {
                    if (event.target.closest('[aria-controls="project-sidebar"]')) {
                        const next = root.dataset.sidebar === 'open' ? 'closed' : 'open';
                        set(next);
                        if (wide.matches) { try { localStorage.setItem('sidebar', next); } catch (e) {} }
                    } else if (event.target.closest('.sidebar-backdrop')) {
                        set('closed');
                    }
                });
                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && ! wide.matches && root.dataset.sidebar === 'open') set('closed');
                });
            })();
        </script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <a href="#main" class="sr-only rounded bg-surface px-3 py-2 focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50">
            Skip to content
        </a>

        @php($workspace = \App\Support\Workspace::current())

        {{-- Top bar, Postman-style (D-040): workspace switcher left, active environment right. --}}
        <header data-behind-drawer class="sticky top-0 z-30 flex h-12 items-center gap-2 border-b border-zinc-200 bg-surface px-3">
            {{-- Below md the sidebar is a drawer; this opens it. From md up it folds the panel. --}}
            <button type="button" aria-controls="project-sidebar" aria-expanded="false" data-panel-toggle title="Show or hide the side panel"
                    class="rounded p-1.5 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900">
                <x-icon name="menu" class="md:hidden" />
                <x-icon name="panel" class="hidden md:block" />
                <span class="sr-only">Sidebar</span>
            </button>
            <a href="{{ route('home') }}" class="hidden font-semibold tracking-tight sm:block">{{ config('app.name') }}</a>

            <x-workspace-switcher :workspace="$workspace" />

            <div class="ml-auto flex min-w-0 items-center gap-3">
                @if ($workspace)
                    <livewire:environment-switcher :project="$workspace" :key="'env-'.$workspace->id" />
                @endif
                <livewire:runner-status />
            </div>
        </header>

        <livewire:runner-status :banner="true" />

        <div class="flex">
            <x-project-sidebar />
            <div class="sidebar-backdrop" aria-hidden="true"></div>

            <div class="min-w-0 flex-1" data-behind-drawer>
                @if ($workspace)
                    <x-workspace-tabs :workspace="$workspace" />
                @endif
                <main id="main" tabindex="-1" class="min-w-0 p-4 lg:p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
