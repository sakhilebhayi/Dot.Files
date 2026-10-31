<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <article class="fx-panel fx-reveal overflow-clip">
            @if ($file->hasPreview())
                <div class="border-b" style="border-color: var(--line); background: var(--panel-soft);">
                    <img src="{{ route('files.preview', $file) }}" alt="{{ __('First page of') }} {{ $file->name }}"
                         class="w-full max-h-[28rem] object-contain" style="object-position: top;">
                </div>
            @else
                <div class="grid place-items-center py-14 border-b" style="border-color: var(--line); background: var(--panel-soft); color: var(--ink-faint);">
                    <x-fx.icon :name="$file->kind()" class="w-12 h-12" />
                    <p class="mt-2 text-sm">{{ __('No snapshot for this format — the document itself is one click away.') }}</p>
                </div>
            @endif

            <div class="p-6">
                @if ($file->infodot_reference)
                    <span class="fx-ref" title="{{ __('InfoDot reference — paste it into InfoDot search to open this file') }}">{{ $file->infodot_reference }}</span>
                @endif
                <h1 class="mt-2 text-2xl font-bold break-words">{{ $file->name }}</h1>
                <p class="mt-1" style="color: var(--ink-soft);">
                    {{ $file->kindLabel() }} · {{ $file->sizeForHumans() }} · {{ __('filed') }} {{ $file->created_at->format('j M Y') }}
                    @if ($folderName)
                        · {{ __('in') }} <a href="{{ route('files', ['uuid' => $folderUuid]) }}" class="underline">{{ $folderName }}</a>
                    @endif
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <a href="{{ route('files.download', $file) }}" class="fx-btn fx-btn--oxide"><x-fx.icon name="download" /> {{ __('Download') }}</a>
                    @php($shareService = app(\App\Services\FileShareService::class))
                    @if ($shareService->canShare(auth()->user(), $file))
                        <a href="{{ route('files.share', $file) }}" class="fx-btn"><x-fx.icon name="share" /> {{ __('Share outside the company') }}</a>
                    @elseif ($shareService->canRequestShare(auth()->user(), $file))
                        <a href="{{ route('files.share', $file) }}" class="fx-btn"><x-fx.icon name="share" /> {{ __('Ask to share') }}</a>
                    @endif
                    @if ($folderUuid)
                        <a href="{{ route('files', ['uuid' => $folderUuid]) }}" class="fx-btn"><x-fx.icon name="folder" /> {{ __('Open folder') }}</a>
                    @endif
                </div>
            </div>
        </article>
    </div>
</x-app-layout>
