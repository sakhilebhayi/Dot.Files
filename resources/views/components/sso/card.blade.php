{{-- R-54: the shell of the sign-in claim and error pages, in Dot.Files' own Achromatic Ledger: paper ground, one white panel,
     hairline border, near-black primary action, Roboto. No new colour, no new face. --}}
<x-guest-layout>
    <style>
        .sso-wrap { min-height: 100dvh; display: grid; place-items: center; padding: 24px 16px; box-sizing: border-box; }
        .sso-panel { width: 100%; max-width: 28rem; background: var(--panel); border: 1px solid var(--line); border-radius: var(--r-panel); padding: 32px 28px; box-sizing: border-box; }
        .sso-logo { display: block; height: 72px; width: 72px; object-fit: contain; margin: 0 0 8px; }
        .sso-logo--dark { display: none; }
        :root[data-theme='dark'] .sso-logo--light { display: none; }
        :root[data-theme='dark'] .sso-logo--dark { display: block; }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme='light']) .sso-logo--light { display: none; }
            :root:not([data-theme='light']) .sso-logo--dark { display: block; }
        }
        .sso-panel h1 { font-size: 1.5rem; line-height: 1.25; font-weight: 700; letter-spacing: -0.01em; color: var(--ink); margin: 0 0 8px; text-wrap: balance; }
        .sso-lede { font-size: 15px; line-height: 1.55; color: var(--ink-soft); margin: 0 0 20px; max-width: 65ch; }
        .sso-lede strong { color: var(--ink); font-weight: 600; overflow-wrap: anywhere; }
        .sso-note { font-size: 14px; color: var(--moss); margin: 0 0 16px; }
        .sso-label { display: block; font-size: 14px; font-weight: 500; color: var(--ink); margin: 0 0 6px; }
        .sso-input { display: block; width: 100%; box-sizing: border-box; min-height: 44px; padding: 0 12px; font: inherit; font-size: 22px; font-weight: 500; letter-spacing: 0.3em; text-align: center; font-variant-numeric: tabular-nums; color: var(--ink); background: var(--panel); border: 1px solid var(--line-strong); border-radius: var(--r-control); }
        .sso-input[aria-invalid='true'] { border-color: var(--danger); }
        .sso-help { font-size: 13px; color: var(--ink-soft); margin: 6px 0 0; }
        .sso-error { font-size: 14px; color: var(--danger); margin: 6px 0 0; }
        .sso-error:empty { display: none; }
        .sso-btn { display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; min-height: 44px; padding: 0 20px; font: inherit; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; border-radius: var(--r-control); border: 1px solid var(--line-strong); background: var(--panel-soft); color: var(--ink); transition: background-color 160ms var(--ease-out), transform 160ms var(--ease-out); }
        .sso-btn:hover { background: var(--line); }
        .sso-btn:active { transform: scale(0.98); }
        .sso-btn--primary { background: var(--oxide); border-color: var(--oxide); color: var(--oxide-ink); }
        .sso-btn--primary:hover { background: var(--oxide-deep); }
        .sso-btn--quiet { background: transparent; border-color: transparent; color: var(--ink-soft); }
        .sso-btn--quiet:hover { background: var(--oxide-wash); color: var(--ink); }
        .sso-btn--block { width: 100%; margin-top: 16px; }
        .sso-aside { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 12px; }
        .sso-aside form { margin: 0; }
        .sso-actions { display: grid; gap: 8px; margin-top: 8px; }
        .sso-ref { font-size: 12px; color: var(--ink-faint); margin: 20px 0 0; }
        .sso-panel :focus-visible { outline: 2px solid var(--data); outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) { .sso-btn { transition: none; } .sso-btn:active { transform: none; } }
        @media (max-width: 380px) { .sso-panel { padding: 24px 16px; } }
    </style>
    <main class="sso-wrap">
        <div class="sso-panel">
            <img class="sso-logo sso-logo--light" src="{{ asset('images/logo.png') }}" alt="Dot.Files" width="72" height="72">
            <img class="sso-logo sso-logo--dark" src="{{ asset('images/logo-light.png') }}" alt="" width="72" height="72" aria-hidden="true">
            {{ $slot }}
        </div>
    </main>
</x-guest-layout>
