<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
        <div>
            <h1 class="text-xl font-bold">{{ __('Trash') }}</h1>
            <p class="mt-1 text-sm" style="color: var(--ink-soft);">
                {{ __('Deleted records wait here for :days days, then they are removed for good. While a file is in the trash, no one outside the company can open it — not even through an existing share.', ['days' => $days]) }}
            </p>
        </div>

        @if (session('status'))
            <div class="fx-note fx-note--good" role="status">{{ session('status') }}</div>
        @endif

        @if ($items->isEmpty())
            <div class="fx-panel fx-empty">
                <x-fx.icon name="check" class="w-10 h-10" style="color: var(--ink-faint);" />
                <p class="font-semibold">{{ __('The trash is empty.') }}</p>
                <p class="text-sm">{{ __('Anything you delete from the workspace lands here first, with :days days to change your mind.', ['days' => $days]) }}</p>
                <a href="{{ route('files') }}" class="fx-btn">{{ __('Back to the records') }}</a>
            </div>
        @else
            <div class="fx-panel overflow-x-auto">
                <table class="fx-register">
                    <thead>
                        <tr>
                            <th>{{ __('Record') }}</th>
                            <th class="hidden sm:table-cell">{{ __('Kind') }}</th>
                            <th>{{ __('Deleted') }}</th>
                            <th class="hidden sm:table-cell">{{ __('Gone for good') }}</th>
                            <th class="w-56"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span style="color: var(--ink-soft);">
                                            <x-fx.icon :name="$item->objectable_type === 'folder' ? 'folder' : ($item->objectable?->kind() ?? 'file')" />
                                        </span>
                                        <span class="font-semibold break-words">{{ $item->objectable->name ?? __('(unnamed)') }}</span>
                                    </div>
                                </td>
                                <td class="hidden sm:table-cell" style="color: var(--ink-soft);">
                                    {{ $item->objectable_type === 'folder' ? __('Folder') : $item->objectable?->kindLabel() }}
                                </td>
                                <td style="color: var(--ink-soft);">{{ $item->trashed_at->format('j M Y') }}</td>
                                <td class="hidden sm:table-cell" style="color: var(--ink-soft);">{{ $item->trashed_at->addDays($days)->format('j M Y') }}</td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" action="{{ route('trash.restore', $item) }}">
                                            @csrf
                                            <button type="submit" class="fx-btn !min-h-[38px]">{{ __('Restore') }}</button>
                                        </form>
                                        @if ($canPurge)
                                            <div x-data="{ confirming: false }">
                                                <button type="button" class="fx-btn !min-h-[38px]" style="color: var(--oxide); border-color: var(--oxide);"
                                                        @click="confirming = true">{{ __('Delete for good') }}</button>

                                                <div x-cloak x-show="confirming" class="fixed inset-0 z-50 grid place-items-center p-4"
                                                     role="dialog" aria-modal="true" aria-label="{{ __('Delete for good') }}" @keydown.escape.window="confirming = false">
                                                    <button type="button" class="absolute inset-0 w-full h-full" style="background: rgba(23, 20, 15, 0.55);"
                                                            @click="confirming = false" aria-label="{{ __('Keep it') }}" tabindex="-1"></button>
                                                    <div class="fx-panel relative w-full max-w-md p-5 text-left">
                                                        <h2 class="font-bold text-lg">{{ __('Gone for good?') }}</h2>
                                                        <p class="mt-2 text-sm" style="color: var(--ink-soft);">
                                                            {{ __(':name is removed permanently: the document itself is destroyed, anyone it was shared with loses access, and there is no way back.', ['name' => $item->objectable->name ?? __('This record')]) }}
                                                        </p>
                                                        <div class="mt-4 flex justify-end gap-2">
                                                            <button type="button" class="fx-btn" @click="confirming = false">{{ __('Keep it') }}</button>
                                                            <form method="POST" action="{{ route('trash.destroy', $item) }}">
                                                                @csrf
                                                                <button type="submit" class="fx-btn fx-btn--oxide">{{ __('Delete for good') }}</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-app-layout>
