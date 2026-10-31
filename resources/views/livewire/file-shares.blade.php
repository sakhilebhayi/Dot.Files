<div class="space-y-5">
    <div class="fx-panel p-5">
        <h2 class="font-bold">{{ __('Share this file with someone outside your company') }}</h2>
        <p class="mt-1 text-sm" style="color: var(--ink-soft);">
            {{ __('They can view and download only this file, and only while the share lasts. They must sign in through InfoDot with this exact email address. You can remove access at any time, and it ends on its own when the file is deleted or you leave the company.') }}
        </p>

        @if ($link === null)
            <p class="mt-4 text-sm" style="color: var(--oxide);">{{ __('This file has no InfoDot link yet, so it cannot be shared. Files in your company are registered with InfoDot automatically after upload; ask an administrator to run the registration if this one is older.') }}</p>
        @else
            <form wire:submit.prevent="share" class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="share-email" class="block text-sm font-medium">{{ __('Their email') }}</label>
                    <input id="share-email" type="email" wire:model="email" required class="fx-input mt-1 w-72" autocomplete="off">
                </div>
                <div>
                    <label for="share-days" class="block text-sm font-medium">{{ __('Access lasts') }}</label>
                    <select id="share-days" wire:model="days" class="fx-select mt-1">
                        <option value="7">{{ __('7 days') }}</option>
                        <option value="30">{{ __('30 days') }}</option>
                        <option value="60">{{ __('60 days') }}</option>
                        <option value="90">{{ __('90 days (longest)') }}</option>
                    </select>
                </div>
                <button type="submit" class="fx-btn fx-btn--oxide"><x-fx.icon name="share" /> {{ __('Share') }}</button>
            </form>
            @error('email')<p class="mt-2 text-sm" style="color: var(--oxide);">{{ $message }}</p>@enderror
            @error('days')<p class="mt-2 text-sm" style="color: var(--oxide);">{{ $message }}</p>@enderror
            @if ($notice)<p class="mt-3 text-sm" style="color: var(--moss);" role="status">{{ $notice }}</p>@endif

            <div class="mt-5" x-data="{ copied: false }">
                <p class="text-sm font-medium">{{ __('Link to send them') }}</p>
                <div class="mt-1 flex items-center gap-2">
                    <input type="text" aria-label="{{ __('Link to send them') }}" readonly value="{{ $link }}" class="fx-input max-w-xl text-sm" onfocus="this.select()">
                    <button type="button" class="fx-btn"
                            @click="navigator.clipboard.writeText('{{ $link }}').then(() => { copied = true; setTimeout(() => copied = false, 1800) })"
                            x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</button>
                </div>
                <p class="mt-1 text-xs" style="color: var(--ink-faint);">{{ __('The link alone opens nothing: it only works for the people listed below.') }}</p>
            </div>
        @endif
    </div>

    <div class="fx-panel p-5">
        <h3 class="font-bold">{{ __('Shared with') }}</h3>
        @forelse ($shares as $share)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t pt-3" style="border-color: var(--line);">
                <div>
                    <p class="font-medium">{{ $share->email }}</p>
                    <p class="text-sm" style="color: var(--ink-soft);">
                        {{ __('Until') }} {{ $share->expires_at->format('j M Y') }}
                        ·
                        @if ($share->first_opened_at)
                            {{ __('opened') }} {{ $share->open_count }}&times;, {{ __('last') }} {{ $share->last_opened_at->diffForHumans() }}
                        @else
                            {{ __('not opened yet') }}
                        @endif
                    </p>
                </div>
                <button type="button" wire:click="revoke({{ $share->id }})" wire:confirm="{{ __('Remove access for') }} {{ $share->email }}?"
                        class="fx-btn" style="color: var(--oxide); border-color: var(--oxide);">{{ __('Remove access') }}</button>
            </div>
        @empty
            <p class="mt-2 text-sm" style="color: var(--ink-soft);">{{ __('Not shared with anyone outside your company.') }}</p>
        @endforelse
    </div>

    <div class="fx-panel p-5">
        <h3 class="font-bold">{{ __('Activity') }}</h3>
        @forelse ($events as $event)
            <p class="mt-2 text-sm">
                <span class="fx-ref">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('j M Y H:i') }}</span>
                <strong class="ml-1">{{ ucfirst($event->event) }}</strong>
                @if ($event->subject_email) · {{ $event->subject_email }}@endif
                @if ($event->actor_email && $event->actor_email !== $event->subject_email) <span style="color: var(--ink-soft);">({{ __('by') }} {{ $event->actor_email }})</span>@endif
                @if ($event->note) <span style="color: var(--ink-soft);">· {{ $event->note }}</span>@endif
            </p>
        @empty
            <p class="mt-2 text-sm" style="color: var(--ink-soft);">{{ __('Nothing yet.') }}</p>
        @endforelse
    </div>
</div>
