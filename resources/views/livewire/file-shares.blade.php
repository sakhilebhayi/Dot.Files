<div class="space-y-8">
    <div class="bg-white shadow-xl sm:rounded-lg p-6">
        <h2 class="text-lg font-bold text-gray-900">{{ __('Share this file with someone outside your company') }}</h2>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('They can view and download only this file, and only while the share lasts. They must sign in through InfoDot with this exact email address. You can remove access at any time, and it ends on its own when the file is deleted or you leave the company.') }}
        </p>

        @if ($link === null)
            <p class="mt-4 text-sm text-amber-700">{{ __('This file has no InfoDot link yet, so it cannot be shared. Files in your company are registered with InfoDot automatically after upload; ask an administrator to run the registration if this one is older.') }}</p>
        @else
            <form wire:submit.prevent="share" class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="share-email" class="block text-sm font-medium text-gray-700">{{ __('Their email') }}</label>
                    <input id="share-email" type="email" wire:model="email" required class="mt-1 block w-72 rounded-md border-gray-300 shadow-sm" autocomplete="off">
                </div>
                <div>
                    <label for="share-days" class="block text-sm font-medium text-gray-700">{{ __('Access lasts') }}</label>
                    <select id="share-days" wire:model="days" class="mt-1 block rounded-md border-gray-300 shadow-sm">
                        <option value="7">7 days</option>
                        <option value="30">30 days</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days (longest)</option>
                    </select>
                </div>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">{{ __('Share') }}</button>
            </form>
            @error('email')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            @error('days')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            @if ($notice)<p class="mt-3 text-sm text-green-700" role="status">{{ $notice }}</p>@endif

            <div class="mt-5" x-data="{ copied: false }">
                <p class="text-sm font-medium text-gray-700">{{ __('Link to send them') }}</p>
                <div class="mt-1 flex items-center gap-2">
                    <input type="text" readonly value="{{ $link }}" class="block w-full max-w-xl rounded-md border-gray-300 bg-gray-50 text-sm" onfocus="this.select()">
                    <button type="button" class="px-3 py-2 border border-gray-300 rounded-md text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50"
                            @click="navigator.clipboard.writeText('{{ $link }}').then(() => { copied = true; setTimeout(() => copied = false, 1800) })"
                            x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                </div>
                <p class="mt-1 text-xs text-gray-500">{{ __('The link alone opens nothing: it only works for the people listed below.') }}</p>
            </div>
        @endif
    </div>

    <div class="bg-white shadow-xl sm:rounded-lg p-6">
        <h3 class="text-base font-bold text-gray-900">{{ __('Shared with') }}</h3>
        @forelse ($shares as $share)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3">
                <div>
                    <p class="font-medium text-gray-900">{{ $share->email }}</p>
                    <p class="text-sm text-gray-500">
                        {{ __('Until') }} {{ $share->expires_at->format('j M Y') }}
                        &middot;
                        @if ($share->first_opened_at)
                            {{ __('opened') }} {{ $share->open_count }}&times;, {{ __('last') }} {{ $share->last_opened_at->diffForHumans() }}
                        @else
                            {{ __('not opened yet') }}
                        @endif
                    </p>
                </div>
                <button type="button" wire:click="revoke({{ $share->id }})" wire:confirm="Remove access for {{ $share->email }}?" class="px-3 py-2 border border-red-300 rounded-md text-xs font-semibold uppercase tracking-widest text-red-700 hover:bg-red-50">{{ __('Remove access') }}</button>
            </div>
        @empty
            <p class="mt-2 text-sm text-gray-500">{{ __('Not shared with anyone outside your company.') }}</p>
        @endforelse
    </div>

    <div class="bg-white shadow-xl sm:rounded-lg p-6">
        <h3 class="text-base font-bold text-gray-900">{{ __('Activity') }}</h3>
        @forelse ($events as $event)
            <p class="mt-2 text-sm text-gray-700">
                <span class="font-mono text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('j M Y H:i') }}</span>
                &nbsp;<strong>{{ ucfirst($event->event) }}</strong>
                @if ($event->subject_email) &middot; {{ $event->subject_email }}@endif
                @if ($event->actor_email && $event->actor_email !== $event->subject_email) <span class="text-gray-500">({{ __('by') }} {{ $event->actor_email }})</span>@endif
                @if ($event->note) <span class="text-gray-500">&middot; {{ $event->note }}</span>@endif
            </p>
        @empty
            <p class="mt-2 text-sm text-gray-500">{{ __('Nothing yet.') }}</p>
        @endforelse
    </div>
</div>
