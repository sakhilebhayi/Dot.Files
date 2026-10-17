<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Request access') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-8">
                <h1 class="text-2xl font-bold text-gray-900">{{ __('You are not in the company that owns this file') }}</h1>
                <p class="mt-3 text-gray-600">{{ __('The link is real, but the file belongs to a company you have not joined, so it cannot be shown. Ask an owner or administrator of that company to add you in InfoDot, then open the link again.') }}</p>
                <a href="{{ route('files') }}" class="mt-6 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">{{ __('Back to my files') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
