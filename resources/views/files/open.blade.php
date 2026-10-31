<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-4">
        @if (session('status'))
            <div class="fx-note fx-note--good" role="status">{{ session('status') }}</div>
        @endif

        @if ($trashed)
            <div class="fx-note fx-note--bad" role="status">
                {{ __('This document is in the trash. No one outside the company can open it, and it will be removed for good after the holding window.') }}
                <a href="{{ route('trash') }}" class="font-semibold underline">{{ __('Restore it from the trash') }}</a>
            </div>
        @endif

        <article class="fx-panel fx-reveal overflow-clip">
            @if ($file->kind() === 'pdf' && ! $trashed)
                {{-- The document itself, rendered by the browser's own PDF viewer — pages, zoom
                     and all — streamed inline through the same policy as the download. --}}
                <iframe src="{{ route('files.inline', $file) }}" title="{{ $file->name }}"
                        class="w-full h-[70vh] border-b" style="border-color: var(--line); background: var(--panel-soft);"></iframe>
            @elseif ($file->hasPreview())
                <div class="border-b" style="border-color: var(--line); background: var(--panel-soft);">
                    <img src="{{ route('files.preview', $file) }}" alt="{{ __('First page of') }} {{ $file->name }}"
                         class="w-full max-h-[28rem] object-contain" style="object-position: top;">
                </div>
            @elseif ($excerpt !== null)
                <div class="border-b px-6 py-5" style="border-color: var(--line); background: var(--panel-soft);">
                    <pre class="whitespace-pre-wrap text-sm leading-relaxed max-h-[24rem] overflow-y-auto" style="font-family: var(--font-body); color: var(--ink-soft);">{{ $excerpt }}</pre>
                </div>
            @else
                <div class="grid place-items-center py-14 border-b" style="border-color: var(--line); background: var(--panel-soft); color: var(--ink-faint);">
                    <x-fx.icon :name="$file->kind()" class="w-12 h-12" />
                    <p class="mt-2 text-sm">{{ __('This format keeps its contents for the download — everything else about it is here.') }}</p>
                </div>
            @endif

            <div class="p-6">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($file->infodot_reference)
                        <span class="fx-ref" title="{{ __('InfoDot reference — paste it into InfoDot search to open this file') }}">{{ $file->infodot_reference }}</span>
                        <span class="text-xs" style="color: var(--ink-faint);">{{ __('registered with InfoDot') }}</span>
                    @endif
                    @if ($pinned)
                        <span class="fx-stamp fx-stamp--active">{{ __('Pinned') }}</span>
                    @endif
                </div>

                <h1 class="mt-2 text-2xl font-bold break-words">{{ $file->name }}</h1>
                <p class="mt-1" style="color: var(--ink-soft);">
                    {{ $file->kindLabel() }} · {{ $file->sizeForHumans() }} · {{ __('filed') }} {{ $file->created_at->format('j M Y') }}
                    @if ($uploader) {{ __('by') }} {{ $uploader->name }} @endif
                    @if ($folderName)
                        · {{ __('in') }} <a href="{{ route('files', ['uuid' => $folderUuid]) }}" class="underline">{{ $folderName }}</a>
                    @endif
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <a href="{{ route('files.download', $file) }}" class="fx-btn fx-btn--oxide"><x-fx.icon name="download" /> {{ __('Download') }}</a>
                    <form method="POST" action="{{ route('files.pin', $file) }}">
                        @csrf
                        <button type="submit" class="fx-btn"><x-fx.icon name="pin" /> {{ $pinned ? __('Unpin') : __('Pin to the front') }}</button>
                    </form>
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

        @if ($events->isNotEmpty())
            <section class="fx-panel p-5" aria-label="{{ __('Sharing trail') }}">
                <h2 class="font-bold">{{ __('Sharing trail') }}</h2>
                <p class="mt-1 text-sm" style="color: var(--ink-soft);">{{ __('Every sharing decision and use of this document, newest first. Owners and administrators see this; members do not.') }}</p>
                @foreach ($events as $event)
                    <p class="mt-2 text-sm">
                        <span style="color: var(--ink-soft); font-variant-numeric: tabular-nums;">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('j M Y H:i') }}</span>
                        <strong class="ml-1">{{ ucfirst($event->event) }}</strong>
                        @if ($event->subject_email) · {{ $event->subject_email }}@endif
                        @if ($event->note) <span style="color: var(--ink-soft);">· {{ $event->note }}</span>@endif
                    </p>
                @endforeach
            </section>
        @endif
    </div>
</x-app-layout>
