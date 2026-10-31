<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

        <div class="flex items-center gap-3">
            <span style="color: var(--ink-soft);"><x-fx.icon :name="$file->kind()" class="w-7 h-7" /></span>
            <div class="min-w-0">
                <h1 class="text-xl font-bold break-words">{{ $file->name }}</h1>
                <p class="text-sm" style="color: var(--ink-soft);">
                    {{ $file->kindLabel() }} · {{ $file->sizeForHumans() }}
                    @if ($file->infodot_reference)
                        · <span class="fx-ref">{{ $file->infodot_reference }}</span>
                    @endif
                </p>
            </div>
        </div>

        @if ($canShare)
            <livewire:file-shares :file="$file" />
        @else
            <div class="fx-panel p-5">
                <h2 class="font-bold">{{ __('Ask to share this file outside the company') }}</h2>
                <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                    {{ __('You can ask; an owner or administrator decides. Until they approve, the person you name sees nothing — the gate is on the server, not in this page.') }}
                </p>

                <form method="POST" action="{{ route('files.share-request', $file) }}" class="mt-4 flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="share-email" class="block text-sm font-medium">{{ __('Their email') }}</label>
                        <input id="share-email" type="email" name="email" value="{{ old('email') }}" required class="fx-input mt-1 w-72" autocomplete="off">
                    </div>
                    <div>
                        <label for="share-days" class="block text-sm font-medium">{{ __('Access lasts') }}</label>
                        <select id="share-days" name="days" class="fx-select mt-1">
                            <option value="7">{{ __('7 days') }}</option>
                            <option value="30" selected>{{ __('30 days') }}</option>
                            <option value="60">{{ __('60 days') }}</option>
                            <option value="90">{{ __('90 days (longest)') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="share-permission" class="block text-sm font-medium">{{ __('They may') }}</label>
                        <select id="share-permission" name="permission" class="fx-select mt-1">
                            <option value="download" @selected(old('permission', 'download') === 'download')>{{ __('View and download') }}</option>
                            <option value="view" @selected(old('permission') === 'view')>{{ __('View only') }}</option>
                        </select>
                    </div>
                    <button type="submit" class="fx-btn fx-btn--oxide"><x-fx.icon name="share" /> {{ __('Send the request') }}</button>
                </form>
                @error('email')<p class="mt-2 text-sm" style="color: var(--danger);">{{ $message }}</p>@enderror
                @error('days')<p class="mt-2 text-sm" style="color: var(--danger);">{{ $message }}</p>@enderror

                <p class="mt-4 text-sm" style="color: var(--ink-soft);">
                    {{ __('They must sign in through InfoDot with that exact verified email, and they will see this one file only — never the folder, never the company.') }}
                </p>
            </div>
        @endif

        <p><a href="{{ route('files') }}" class="fx-btn fx-btn--quiet">← {{ __('Back to the records') }}</a></p>
    </div>
</x-app-layout>
