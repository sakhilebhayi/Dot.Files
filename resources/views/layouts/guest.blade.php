<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
        <title>Dot.Files</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            /* Guest pages share the product system (see resources/css/app.css); the aliases
               below keep older guest views that reference the landing's token names working. */
            :root {
                --paper-soft: var(--panel-soft);
                --gold: var(--oxide);
                --gold-deep: var(--oxide-deep);
                --mint: var(--moss-fill);
                --mint-deep: var(--moss);
                --font-mono: var(--font-stencil);
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
