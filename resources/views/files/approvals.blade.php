<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

        <div>
            <h1 class="text-xl font-bold">{{ __('Sharing approvals') }}</h1>
            <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                @if ($canDecide)
                    {{ __('Who outside') }} {{ $team->name }} {{ __('can see which file — and the requests waiting on your decision. Nothing leaves the company without a yes from an owner or administrator.') }}
                @else
                    {{ __('Your requests to share a file outside') }} {{ $team->name }}{{ __('. An owner or administrator decides each one; nothing is visible outside until they approve.') }}
                @endif
            </p>
        </div>

        @if (session('status'))
            <div class="fx-note fx-note--good" role="status">{{ session('status') }}</div>
        @endif

        {{-- Governance: unusual activity for owners to judge (R-28 §29). Review-only — the
             evidence is stated plainly, nothing is blocked and no one is accused. --}}
        @if ($canDecide && $flags->isNotEmpty())
            <section class="fx-panel p-5" aria-label="{{ __('Activity to review') }}">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-bold">{{ __('Activity to review') }}</h2>
                    <span class="fx-tally" aria-hidden="true">{{ $flags->count() }}</span>
                </div>
                <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                    {{ __('Patterns worth a look, not accusations. Every version of every record is preserved, so whatever happened can be undone from the history.') }}
                </p>

                @foreach ($flags as $flag)
                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t pt-4" style="border-color: var(--line);">
                        <span style="color: var(--ink-soft);"><x-fx.icon :name="$flag->file?->kind() ?? 'file'" class="w-6 h-6" /></span>
                        <div class="min-w-0 flex-grow">
                            <p class="font-medium break-words">
                                {{ $flag->ruleLabel() }} · {{ $flag->file?->name ?? __('a removed file') }}
                            </p>
                            <p class="text-sm" style="color: var(--ink-soft);">
                                @if ($flag->rule === 'version-churn')
                                    {{ __(':actor saved new content :count times within :minutes minutes (versions :versions).', ['actor' => $flag->actor?->name ?? __('a former member'), 'count' => $flag->evidence['count'] ?? '?', 'minutes' => $flag->evidence['window_minutes'] ?? '?', 'versions' => implode(', ', $flag->evidence['versions'] ?? [])]) }}
                                @elseif ($flag->rule === 'replace-then-trash')
                                    {{ __('Content replaced (version :version), then :actor trashed the record within :minutes minutes. It is still in the trash and every version is in its history.', ['version' => $flag->evidence['replaced_version'] ?? '?', 'actor' => $flag->actor?->name ?? __('a former member'), 'minutes' => $flag->evidence['window_minutes'] ?? '?']) }}
                                @endif
                                · {{ $flag->created_at->diffForHumans() }}
                            </p>
                        </div>
                        @if ($flag->file)
                            <a href="{{ route('files.open', $flag->file->uuid) }}" class="fx-btn fx-btn--quiet">{{ __('Open the record') }}</a>
                        @endif
                        <form method="POST" action="{{ route('approvals.flags.review', $flag) }}">
                            @csrf
                            <button type="submit" class="fx-btn">{{ __('Mark reviewed') }}</button>
                        </form>
                        <form method="POST" action="{{ route('approvals.flags.dismiss', $flag) }}">
                            @csrf
                            <button type="submit" class="fx-btn fx-btn--quiet">{{ __('Dismiss') }}</button>
                        </form>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- Waiting on a decision --}}
        <section class="fx-panel p-5" aria-label="{{ __('Waiting for approval') }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-bold">{{ __('Waiting for approval') }}</h2>
                @if ($pending->isNotEmpty())
                    <span class="fx-tally" aria-hidden="true">{{ $pending->count() }}</span>
                @endif
            </div>

            @forelse ($pending as $share)
                <div class="mt-4 flex flex-wrap items-center gap-3 border-t pt-4" style="border-color: var(--line);">
                    <span style="color: var(--ink-soft);"><x-fx.icon :name="$share->file?->kind() ?? 'file'" class="w-6 h-6" /></span>
                    <div class="min-w-0 flex-grow">
                        <p class="font-semibold break-words">{{ $share->file?->name ?? __('(file no longer exists)') }}</p>
                        <p class="text-sm" style="color: var(--ink-soft);">
                            {{ __('to') }} <span class="font-medium">{{ $share->email }}</span>
                            · {{ strtolower($share->permissionLabel()) }}
                            · {{ trans_choice(':count day|:count days', $share->requestedDays(), ['count' => $share->requestedDays()]) }}
                            · {{ __('asked by') }} {{ $share->requester?->name ?? __('a former member') }}
                            {{ $share->created_at->diffForHumans() }}
                        </p>
                    </div>
                    @if ($canDecide && $share->file)
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('approvals.approve', $share) }}">
                                @csrf
                                <button type="submit" class="fx-btn fx-btn--oxide"><x-fx.icon name="check" /> {{ __('Approve') }}</button>
                            </form>
                            <form method="POST" action="{{ route('approvals.decline', $share) }}" class="flex items-center gap-2">
                                @csrf
                                <label class="sr-only" for="note-{{ $share->id }}">{{ __('Reason (optional)') }}</label>
                                <input id="note-{{ $share->id }}" type="text" name="note" maxlength="255" placeholder="{{ __('Reason (optional)') }}" class="fx-input !min-h-[40px] w-44 text-sm">
                                <button type="submit" class="fx-btn">{{ __('Decline') }}</button>
                            </form>
                        </div>
                    @else
                        <span class="fx-stamp fx-stamp--pending">{{ __('Pending') }}</span>
                    @endif
                </div>
            @empty
                <p class="mt-3 text-sm" style="color: var(--ink-soft);">
                    @if ($canDecide)
                        {{ __('Nothing waits on you. When a member asks to share a file, the request appears here first.') }}
                    @else
                        {{ __('You have no open requests. Ask from any file’s page: Ask to share.') }}
                    @endif
                </p>
            @endforelse
        </section>

        {{-- Currently shared --}}
        <section class="fx-panel p-5" aria-label="{{ __('Currently shared') }}">
            <h2 class="font-bold">{{ __('Currently shared outside the company') }}</h2>
            @forelse ($active as $share)
                <div class="mt-4 flex flex-wrap items-center gap-3 border-t pt-4" style="border-color: var(--line);">
                    <span style="color: var(--ink-soft);"><x-fx.icon :name="$share->file?->kind() ?? 'file'" class="w-6 h-6" /></span>
                    <div class="min-w-0 flex-grow">
                        <p class="font-semibold break-words">{{ $share->file?->name }}</p>
                        <p class="text-sm" style="color: var(--ink-soft);">
                            {{ __('with') }} <span class="font-medium">{{ $share->email }}</span>
                            · {{ strtolower($share->permissionLabel()) }}
                            · {{ __('until') }} {{ $share->expires_at->format('j M Y') }}
                            ·
                            @if ($share->first_opened_at)
                                {{ __('opened') }} {{ $share->open_count }}&times;
                            @else
                                {{ __('not opened yet') }}
                            @endif
                        </p>
                    </div>
                    <span class="fx-stamp fx-stamp--active">{{ __('Active') }}</span>
                    @if ($canDecide && $share->file)
                        <a href="{{ route('files.share', $share->file) }}" class="fx-btn">{{ __('Manage') }}</a>
                    @endif
                </div>
            @empty
                <p class="mt-3 text-sm" style="color: var(--ink-soft);">{{ __('No file is currently visible outside the company.') }}</p>
            @endforelse
        </section>

        {{-- Shared by me --}}
        <section class="fx-panel p-5" aria-label="{{ __('Shared by me') }}">
            <h2 class="font-bold">{{ __('Shared by me') }}</h2>
            @forelse ($mine as $share)
                <div class="mt-3 flex flex-wrap items-center gap-3 border-t pt-3" style="border-color: var(--line);">
                    <div class="min-w-0 flex-grow">
                        <p class="font-medium break-words">{{ $share->file?->name ?? __('(file no longer exists)') }}
                            <span style="color: var(--ink-soft);">· {{ __('to') }} {{ $share->email }}</span></p>
                        <p class="text-sm" style="color: var(--ink-soft); font-variant-numeric: tabular-nums;">
                            {{ $share->created_at->format('j M Y') }}
                            @if ($share->isActive()) · {{ strtolower($share->permissionLabel()) }} · {{ __('until') }} {{ $share->expires_at->format('j M Y') }} @endif
                        </p>
                    </div>
                    @if ($share->isActive())
                        <span class="fx-stamp fx-stamp--active">{{ __('Active') }}</span>
                    @elseif ($share->status === 'pending')
                        <span class="fx-stamp fx-stamp--pending">{{ __('Pending') }}</span>
                    @else
                        <span class="fx-stamp fx-stamp--ended">{{ $share->status === 'declined' ? __('Declined') : ($share->revoked_at ? __('Removed') : __('Expired')) }}</span>
                    @endif
                </div>
            @empty
                <p class="mt-3 text-sm" style="color: var(--ink-soft);">{{ __('You have not shared anything outside the company.') }}</p>
            @endforelse
        </section>

        {{-- Platform connections --}}
        <section class="fx-panel p-5" aria-label="{{ __('Platform connections') }}">
            <h2 class="font-bold">{{ __('Platform connections') }}</h2>
            <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                {{ __('Dot platforms that hold or work with this company’s files, from the audit trail. A platform appears here only once it has really acted.') }}
            </p>
            @forelse ($platformActivity as $row)
                <div class="mt-3 flex flex-wrap items-center gap-3 border-t pt-3" style="border-color: var(--line);">
                    <div class="min-w-0 flex-grow">
                        <p class="font-medium">{{ $row->name }}</p>
                        <p class="text-sm" style="color: var(--ink-soft); font-variant-numeric: tabular-nums;">
                            {{ $row->stored }} {{ __('filed') }} · {{ $row->events }} {{ __('events') }} · {{ __('last') }} {{ \Illuminate\Support\Carbon::parse($row->last_at)->format('j M Y H:i') }}
                        </p>
                    </div>
                    <span class="fx-stamp fx-stamp--active">{{ __('Connected') }}</span>
                </div>
            @empty
                <p class="mt-3 text-sm" style="color: var(--ink-soft);">{{ __('No Dot platform has filed or touched this company’s documents yet. Platforms connect through the platform file API, each with its own key.') }}</p>
            @endforelse
        </section>

        {{-- Settled --}}
        <section class="fx-panel p-5" aria-label="{{ __('Settled') }}">
            <h2 class="font-bold">{{ __('Settled') }}</h2>
            @forelse ($decided as $share)
                <div class="mt-4 flex flex-wrap items-center gap-3 border-t pt-4" style="border-color: var(--line);">
                    <div class="min-w-0 flex-grow">
                        <p class="font-medium break-words">{{ $share->file?->name ?? __('(file no longer exists)') }}
                            <span style="color: var(--ink-soft);">· {{ $share->email }}</span></p>
                        <p class="text-sm" style="color: var(--ink-soft);">
                            @if ($share->status === 'declined')
                                {{ __('declined') }} {{ $share->decided_at?->format('j M Y') }}
                            @elseif ($share->revoked_at)
                                {{ __('access removed') }} {{ $share->revoked_at->format('j M Y') }}
                            @else
                                {{ __('expired') }} {{ $share->expires_at->format('j M Y') }}
                            @endif
                        </p>
                    </div>
                    <span class="fx-stamp fx-stamp--ended">
                        {{ $share->status === 'declined' ? __('Declined') : ($share->revoked_at ? __('Removed') : __('Expired')) }}
                    </span>
                </div>
            @empty
                <p class="mt-3 text-sm" style="color: var(--ink-soft);">{{ __('Nothing settled yet.') }}</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
