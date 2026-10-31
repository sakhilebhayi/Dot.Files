<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <article class="fx-panel fx-reveal overflow-clip">
            @if ($file->hasPreview())
                <div class="border-b" style="border-color: var(--line); background: var(--panel-soft);">
                    <img src="{{ route('shared.preview', $share) }}" alt="{{ __('First page of') }} {{ $file->name }}"
                         class="w-full max-h-[28rem] object-contain" style="object-position: top;">
                </div>
            @else
                <div class="grid place-items-center py-14 border-b" style="border-color: var(--line); background: var(--panel-soft); color: var(--ink-faint);">
                    <x-fx.icon :name="$file->kind()" class="w-12 h-12" />
                </div>
            @endif

            <div class="p-6">
                <span class="fx-stamp fx-stamp--active">{{ __('Shared with you') }}</span>
                <h1 class="mt-3 text-2xl font-bold break-words">{{ $file->name }}</h1>
                <p class="mt-1" style="color: var(--ink-soft);">
                    {{ $file->kindLabel() }} · {{ $file->sizeForHumans() }} · {{ __('available until') }} {{ $share->expires_at->format('j M Y') }}
                </p>
                <p class="mt-2 text-sm" style="color: var(--ink-soft);">
                    @if ($share->canDownload())
                        {{ __('Another company shared this one file with you. You can view and download it; nothing else of theirs is visible to you, and access ends on the date above or sooner if they remove it.') }}
                    @else
                        {{ __('Another company shared this one file with you, to view only — no copy leaves this page. Nothing else of theirs is visible to you, and access ends on the date above or sooner if they remove it.') }}
                    @endif
                </p>
                <div class="mt-5 flex flex-wrap items-center gap-2">
                    @if ($share->canDownload())
                        <a href="{{ route('shared.download', $share) }}" class="fx-btn fx-btn--oxide"><x-fx.icon name="download" /> {{ __('Download') }}</a>
                    @else
                        <span class="fx-stamp">{{ __('View only') }}</span>
                    @endif
                    <a href="{{ route('shared') }}" class="fx-btn fx-btn--quiet">{{ __('All files shared with me') }}</a>
                </div>
            </div>
        </article>
    </div>
</x-app-layout>
