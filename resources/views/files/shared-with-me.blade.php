<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
        <div>
            <h1 class="text-xl font-bold">{{ __('Shared with me') }}</h1>
            <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                {{ __('Files other companies have shared with you, matched to your verified email. Each one ends on its date, or sooner if they remove it.') }}
            </p>
        </div>

        @if ($shares->isEmpty())
            <div class="fx-panel fx-empty">
                <x-fx.icon name="share" class="w-10 h-10" style="color: var(--ink-faint);" />
                <p class="font-semibold">{{ __('Nothing is shared with you right now.') }}</p>
                <p class="text-sm">{{ __('When another company shares a file with your email, it appears here and in the link they send you.') }}</p>
            </div>
        @else
            <ul class="fx-shelf" role="list">
                @foreach ($shares as $share)
                    <li class="fx-card" wire:key="share-{{ $share->id }}">
                        <a href="{{ route('files.open', $share->file->uuid) }}" class="fx-card-face" tabindex="-1" aria-hidden="true">
                            @if ($share->file->hasPreview())
                                <img src="{{ route('shared.preview', $share) }}" alt="" loading="lazy">
                            @else
                                <x-fx.icon :name="$share->file->kind()" class="w-9 h-9" />
                            @endif
                        </a>
                        <div class="fx-card-body">
                            <a href="{{ route('files.open', $share->file->uuid) }}" class="fx-card-title">{{ $share->file->name }}</a>
                            <p class="fx-card-meta">{{ $share->file->kindLabel() }} · {{ $share->file->sizeForHumans() }} · {{ __('until') }} {{ $share->expires_at->format('j M Y') }}</p>
                            <div class="flex items-center justify-between gap-2 mt-1">
                                <span class="fx-stamp fx-stamp--active">{{ __('Shared') }}</span>
                                <a href="{{ route('shared.download', $share) }}" class="fx-btn fx-btn--quiet" title="{{ __('Download') }}"><x-fx.icon name="download" /><span class="sr-only">{{ __('Download') }}</span></a>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-app-layout>
