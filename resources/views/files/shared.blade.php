<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Shared with you') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-8">
                <h1 class="text-2xl font-bold text-gray-900 break-words">{{ $file->name }}</h1>
                <p class="mt-2 text-gray-600">{{ $file->sizeForHumans() }} &middot; {{ __('available until') }} {{ $share->expires_at->format('j M Y') }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('This file was shared with you by another company. You can view and download it; nothing else of theirs is visible to you.') }}</p>
                <div class="mt-6">
                    <a href="{{ route('shared.download', $share) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">{{ __('Download') }}</a>
                    <a href="{{ route('shared') }}" class="ml-3 text-sm text-gray-600 underline">{{ __('All files shared with me') }}</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
