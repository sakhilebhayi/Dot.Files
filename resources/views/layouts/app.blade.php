<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <title>Dot.Files</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&family=Big+Shoulders+Stencil+Display:wght@700;800&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles

        {{-- Alpine.js is bundled with Livewire v3 — do NOT load it separately --}}
        <link rel="stylesheet" href="https://unpkg.com/filepond/dist/filepond.min.css">
        <script src="https://unpkg.com/filepond/dist/filepond.min.js"></script>
    </head>
    <body class="antialiased">
        <div class="min-h-screen flex flex-col">
            @livewire('navigation-dropdown')

            @isset($header)
                <header class="border-b" style="border-color: var(--line); background: var(--panel);">
                    <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main class="flex-1">
                {{ $slot }}
            </main>

            <footer class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 text-sm" style="color: var(--ink-faint);">
                Dot.Files — your company's records, in its own room. Every file here belongs to
                {{ auth()->user()?->currentTeam?->name ?? 'your company' }} and is visible to no other business.
            </footer>
        </div>

        @stack('modals')

        @livewireScripts
    </body>
</html>
