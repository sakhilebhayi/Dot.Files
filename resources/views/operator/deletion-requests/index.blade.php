<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
        <div>
            <h1 class="text-xl font-bold">{{ __('Deletion requests') }}</h1>
            <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                {{ __('Nothing a company asked to remove in bulk leaves the record until an operator approves it here.') }}
            </p>
        </div>

        @if (session('status'))
            <div class="fx-note fx-note--good" role="status">{{ session('status') }}</div>
        @endif

        <section class="fx-panel p-5" aria-label="{{ __('Pending team deletions') }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-bold">{{ __('Pending company deletions') }}</h2>
                <span class="fx-tally" aria-hidden="true">{{ $pendingTeamRequests->count() }}</span>
            </div>
            @forelse ($pendingTeamRequests as $request)
                <div class="mt-4 border-t pt-4" style="border-color: var(--line);">
                    <p class="font-semibold">{{ $request->team->name }}</p>
                    <p class="text-sm mb-2" style="color: var(--ink-soft);">{{ __('Requested by') }} {{ $request->requester->name }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('operator.deletion-requests.team.approve', $request) }}">
                            @csrf
                            <button type="submit" class="fx-btn fx-btn--oxide"><x-fx.icon name="check" /> {{ __('Approve') }}</button>
                        </form>
                        <form method="POST" action="{{ route('operator.deletion-requests.team.reject', $request) }}" class="flex items-center gap-2">
                            @csrf
                            <label class="sr-only" for="team-reason-{{ $request->id }}">{{ __('Reason for rejecting') }}</label>
                            <input id="team-reason-{{ $request->id }}" type="text" name="reason" placeholder="{{ __('Reason for rejecting') }}" required class="fx-input !min-h-[40px] w-56 text-sm">
                            <button type="submit" class="fx-btn">{{ __('Reject') }}</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="mt-3 text-sm" style="color: var(--ink-soft);">{{ __('No pending company deletions.') }}</p>
            @endforelse
        </section>

        <section class="fx-panel p-5" aria-label="{{ __('Pending bulk file deletions') }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-bold">{{ __('Pending bulk file deletions') }}</h2>
                <span class="fx-tally" aria-hidden="true">{{ $pendingBulkRequests->count() }}</span>
            </div>
            @forelse ($pendingBulkRequests as $request)
                <div class="mt-4 border-t pt-4" style="border-color: var(--line);">
                    <p class="font-semibold">{{ $request->team->name }} — {{ trans_choice(':count item|:count items', count($request->object_ids), ['count' => count($request->object_ids)]) }}</p>
                    <p class="text-sm mb-2" style="color: var(--ink-soft);">{{ __('Requested by') }} {{ $request->requester->name }}</p>
                    @if (! empty($request->object_names))
                        <ul class="text-sm mb-2 list-disc list-inside" style="color: var(--ink-soft);">
                            @foreach (array_slice($request->object_names, 0, 10) as $name)
                                <li>{{ $name !== '' ? $name : __('unnamed item') }}</li>
                            @endforeach
                            @if (count($request->object_names) > 10)
                                <li>…{{ __('and') }} {{ count($request->object_names) - 10 }} {{ __('more') }}</li>
                            @endif
                        </ul>
                    @endif
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('operator.deletion-requests.bulk.approve', $request) }}">
                            @csrf
                            <button type="submit" class="fx-btn fx-btn--oxide"><x-fx.icon name="check" /> {{ __('Approve') }}</button>
                        </form>
                        <form method="POST" action="{{ route('operator.deletion-requests.bulk.reject', $request) }}" class="flex items-center gap-2">
                            @csrf
                            <label class="sr-only" for="bulk-reason-{{ $request->id }}">{{ __('Reason for rejecting') }}</label>
                            <input id="bulk-reason-{{ $request->id }}" type="text" name="reason" placeholder="{{ __('Reason for rejecting') }}" required class="fx-input !min-h-[40px] w-56 text-sm">
                            <button type="submit" class="fx-btn">{{ __('Reject') }}</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="mt-3 text-sm" style="color: var(--ink-soft);">{{ __('No pending bulk deletions.') }}</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
