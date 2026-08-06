<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-[var(--gold)] border border-transparent rounded-md font-semibold text-xs text-[var(--ink)] uppercase tracking-widest hover:bg-[var(--gold-deep)] active:bg-[var(--gold-deep)] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[var(--gold-deep)] disabled:opacity-40 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
