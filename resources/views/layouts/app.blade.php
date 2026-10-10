<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <a href="#main" class="sr-only rounded bg-white px-3 py-2 focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50">
            Skip to content
        </a>

        <header class="border-b border-zinc-200 bg-white">
            <div class="flex h-12 items-center gap-6 px-4">
                <a href="{{ route('dashboard') }}" class="font-semibold tracking-tight">{{ config('app.name') }}</a>

                <nav aria-label="Main" class="flex items-center gap-1 text-sm">
                    <x-nav-link route="dashboard">Dashboard</x-nav-link>
                    <x-nav-link route="projects">Projects</x-nav-link>
                    <x-nav-link route="runs">Runs</x-nav-link>
                    <x-nav-link route="settings">Settings</x-nav-link>
                </nav>

                <div class="ml-auto">
                    <livewire:runner-status />
                </div>
            </div>
        </header>

        <livewire:runner-status :banner="true" />

        <div class="flex">
            <x-project-sidebar />

            <main id="main" tabindex="-1" class="min-w-0 flex-1 p-6">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
