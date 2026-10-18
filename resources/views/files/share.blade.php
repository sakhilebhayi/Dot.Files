<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Share') }}: {{ $file->name }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <livewire:file-shares :file="$file" />
            <p class="mt-6"><a href="{{ route('files') }}" class="text-sm text-gray-600 underline">{{ __('Back to files') }}</a></p>
        </div>
    </div>
</x-app-layout>
