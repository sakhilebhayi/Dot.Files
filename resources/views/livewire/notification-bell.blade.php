{{--
  The bell (EA-3): the shared Dot UI bell in its PANEL form (dot-ui 0.6.0). All styling is the shared header's own
  .dh-panel__* and .dh-mark; nothing here draws its own look. An unread notice carries data-unread (the accent rule).
--}}
{{-- Livewire needs its own single root; .fx-bellhost (display: contents) keeps the bell where the header lays it out. --}}
<div class="fx-bellhost">
<x-dot.bell :count="$unread" label="{{ __('Notifications') }}" id="fx-bell-panel">
    <div class="dh-panel__head">
        <p class="dh-panel__title">{{ __('Notifications') }}</p>
        @if ($unread > 0)
            <button type="button" class="dh-mark" wire:click="markAllRead">{{ __('Mark all read') }}</button>
        @endif
    </div>

    @if ($notices->isEmpty())
        <p class="dh-panel__empty">{{ __('Nothing yet. Share requests waiting for you, and decisions on what you asked for, appear here.') }}</p>
    @else
        <ul class="dh-panel__list">
            @foreach ($notices as $notice)
                <li class="dh-panel__item" wire:key="notice-{{ $notice->id }}" @if ($notice->read_at === null) data-unread @endif>
                    <span>
                        <a href="{{ $notice->data['url'] ?? route('files') }}" wire:click.prevent="open('{{ $notice->id }}')">{{ $notice->data['title'] ?? __('Notice') }}</a>
                        <span class="block">{{ $notice->data['body'] ?? '' }}</span>
                        <time class="fx-notice-time" datetime="{{ $notice->created_at->toIso8601String() }}">{{ $notice->created_at->diffForHumans() }}</time>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-dot.bell>
</div>
