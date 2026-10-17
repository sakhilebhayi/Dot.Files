<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('File') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-8">
                @if ($file->infodot_reference)
                    <p class="font-mono text-sm tracking-widest text-gray-500">{{ $file->infodot_reference }}</p>
                @endif
                <h1 class="mt-1 text-2xl font-bold text-gray-900 break-words">{{ $file->name }}</h1>
                <p class="mt-2 text-gray-600">
                    {{ $file->sizeForHumans() }}
                    @if ($folderName) &middot; in <a class="text-blue-600 underline" href="{{ route('files', ['uuid' => $folderUuid]) }}">{{ $folderName }}</a>@endif
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('files.download', $file) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">{{ __('Download') }}</a>
                    @if ($folderUuid)
                        <a href="{{ route('files', ['uuid' => $folderUuid]) }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">{{ __('Open folder') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
