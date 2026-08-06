<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
        <title>Dot.Files</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Work+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --paper: #fbf7ee;
                --paper-soft: #f3ecd9;
                --ink: #2b2a25;
                --ink-soft: #58564c;
                --ink-faint: #8a8778;
                --gold: #d9a018;
                --gold-deep: #a97710;
                --mint: #3f9c79;
                --mint-deep: #2d7a5d;
                --line: rgba(43, 42, 37, 0.12);
                --font-display: 'Fraunces', Georgia, serif;
                --font-body: 'Work Sans', system-ui, sans-serif;
                --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            body {
                font-family: var(--font-body);
                background: var(--paper);
                color: var(--ink);
                min-height: 100vh;
                margin: 0;
            }
        </style>
    </head>
    <body>
        <div class="antialiased">
            {{ $slot }}
        </div>
    </body>
</html>
