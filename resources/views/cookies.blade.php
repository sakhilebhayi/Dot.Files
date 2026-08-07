<x-guest-layout>
    <div class="pt-4 pb-12 bg-[var(--paper)]">
        <div class="min-h-screen flex flex-col items-center pt-6 sm:pt-0 px-4">
            <div>
                <x-jet-authentication-card-logo />
            </div>

            <div class="w-full sm:max-w-2xl mt-6 p-6 sm:p-8 bg-[var(--paper-soft)] border border-[var(--line)] shadow-sm overflow-hidden rounded-2xl prose prose-headings:font-display prose-headings:text-[var(--ink)] prose-p:text-[var(--ink-soft)] prose-li:text-[var(--ink-soft)] prose-a:text-[var(--mint-deep)] prose-strong:text-[var(--ink)]">
                {!! $cookies !!}
            </div>
        </div>
    </div>
</x-guest-layout>
