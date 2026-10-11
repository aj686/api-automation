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
                    root.dataset.sidebar = state;
                    document.querySelectorAll('[aria-controls="project-sidebar"]').forEach((button) => {
                        button.setAttribute('aria-expanded', String(state === 'open'));
                    });
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
    <body class="flex min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <a href="#main" class="sr-only rounded bg-surface px-3 py-2 focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50">
            Skip to content
        </a>

        <x-project-sidebar />
        <div class="sidebar-backdrop" aria-hidden="true"></div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-12 shrink-0 items-center gap-3 border-b border-zinc-200 bg-surface px-4">
                {{-- Below md the sidebar is a drawer; this opens it. --}}
                <button type="button" aria-controls="project-sidebar" aria-expanded="false"
                        class="-ml-1.5 rounded p-1.5 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 md:hidden">
                    <x-icon name="menu" />
                    <span class="sr-only">Menu</span>
                </button>
                <a href="{{ route('dashboard') }}" class="font-semibold tracking-tight md:hidden">{{ config('app.name') }}</a>

                <div class="ml-auto">
                    <livewire:runner-status />
                </div>
            </header>

            <livewire:runner-status :banner="true" />

            <main id="main" tabindex="-1" class="min-w-0 flex-1 p-6">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
