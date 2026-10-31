<x-app-layout>
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="fx-panel p-8 text-center">
            <x-fx.icon name="shield" class="w-10 h-10 mx-auto" style="color: var(--ink-faint);" />
            <h1 class="mt-3 text-xl font-bold">{{ __('This file belongs to a company you have not joined') }}</h1>
            <p class="mt-3" style="color: var(--ink-soft);">
                {{ __('The link is real, but the record cannot be shown: a company’s files are visible only to its own people and to those its owners have approved. Ask an owner or administrator of that company to add you in InfoDot — or to share the file with your email — then open the link again.') }}
            </p>
            <a href="{{ route('files') }}" class="fx-btn fx-btn--oxide mt-6">{{ __('Back to my records') }}</a>
        </div>
    </div>
</x-app-layout>
