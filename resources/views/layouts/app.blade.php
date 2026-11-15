<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <title>Dot.Files</title>

        {{-- Roboto is the face of every Dot platform: self-hosted from this site (public/dot-ui/fonts/roboto), no font CDN. --}}
        <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
        {{-- Shared Dot UI 0.6.1 (typography, tokens, search pill, switcher, header in ONE minified stylesheet), served from this site's own public/dot-ui. Loaded before the app CSS so the host mapping block in app.css wins. --}}
        <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
        {{-- FilePond is bundled by Vite (resources/js/filepond.js): no third-party CDN, so upload works offline and under a strict CSP. --}}
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles

        {{-- Alpine.js is bundled with Livewire v3 — do NOT load it separately --}}
    </head>
    <body class="antialiased">
        <div class="min-h-screen flex flex-col @if (($shell ?? null) === 'desk') fx-shell-desk @endif">
            @livewire('navigation-dropdown')

            @isset($header)
                <header class="border-b" style="border-color: var(--line); background: var(--panel);">
                    <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <main class="flex-1 @if (($shell ?? null) === 'desk') fx-desk-main @endif">
                {{ $slot }}
            </main>

            @if (($shell ?? null) !== 'desk')
            <footer class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 text-sm" style="color: var(--ink-faint);">
                Dot.Files — every file here belongs to
                {{ auth()->user()?->currentTeam?->name ?? 'your company' }} and is visible to no other business.
            </footer>
            @endif
        </div>

        @stack('modals')

        <script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" defer></script>
        @livewireScripts
    </body>
</html>
