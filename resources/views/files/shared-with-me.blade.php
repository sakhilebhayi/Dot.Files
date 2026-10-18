<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Shared with me') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <p class="text-sm text-gray-600">{{ __('Files other companies have shared with you, matched to your verified email. Each one ends on its date or sooner if they remove it.') }}</p>
                @forelse ($shares as $share)
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3">
                        <div>
                            <p class="font-medium text-gray-900 break-words">{{ $share->file->name }}</p>
                            <p class="text-sm text-gray-500">{{ $share->file->sizeForHumans() }} &middot; {{ __('until') }} {{ $share->expires_at->format('j M Y') }}</p>
                        </div>
                        <a href="{{ route('shared.download', $share) }}" class="px-3 py-2 border border-gray-300 rounded-md text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">{{ __('Download') }}</a>
                    </div>
                @empty
                    <p class="mt-4 text-sm text-gray-500">{{ __('Nothing is shared with you right now.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
