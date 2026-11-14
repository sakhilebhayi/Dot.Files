{{-- The actions menu for one register row or shelf card. It is positioned FIXED from the trigger's rectangle, so the
     locked scroller around the records can never clip it, and it opens upward when there is no room below. --}}
<div x-data="{ m: false, top: 'auto', bottom: 'auto', right: 0,
        toggle(el) {
            if (this.m) { this.m = false; return }
            const r = el.getBoundingClientRect()
            const up = window.innerHeight - r.bottom < 300 && r.top > 300
            this.right = Math.max(8, window.innerWidth - r.right)
            this.top = up ? 'auto' : (r.bottom + 4) + 'px'
            this.bottom = up ? (window.innerHeight - r.top + 4) + 'px' : 'auto'
            this.m = true
        } }"
     @keydown.escape.stop="m = false" @scroll.window.capture="m = false" @resize.window="m = false">
    <button type="button" class="fx-dots" @click="toggle($el)"
            :aria-expanded="m.toString()" aria-haspopup="menu" aria-label="{{ __('Actions for') }} {{ $child->objectable->name }}">
        <x-fx.icon name="dots" />
    </button>
    <div x-cloak x-show="m" @click.outside="m = false" class="fx-menu fx-menu--fixed" role="menu"
         :style="`top:${top};bottom:${bottom};right:${right}px`">
        @if ($child->objectable_type === 'file')
            <a href="{{ route('files.open', $child->objectable->uuid) }}" role="menuitem"><x-fx.icon name="open" /> {{ __('Open') }}</a>
            <a href="{{ route('files.download', $child->objectable) }}" role="menuitem"><x-fx.icon name="download" /> {{ __('Download') }}</a>
            <button type="button" role="menuitem" wire:click="togglePin({{ $child->objectable->id }})" @click="m = false">
                <x-fx.icon name="pin" /> {{ in_array($child->objectable->id, $pinnedIds) ? __('Unpin') : __('Pin to the front') }}
            </button>
            @if ($canShare)
                <a href="{{ route('files.share', $child->objectable) }}" role="menuitem"><x-fx.icon name="share" /> {{ __('Share outside the company') }}</a>
            @elseif ($canRequest)
                <a href="{{ route('files.share', $child->objectable) }}" role="menuitem"><x-fx.icon name="share" /> {{ __('Ask to share') }}</a>
            @endif
        @endif
        <button type="button" role="menuitem" wire:click="$set('renamingObject', {{ $child->id }})" @click="m = false">
            <x-fx.icon name="rename" /> {{ __('Rename') }}
        </button>
        <button type="button" role="menuitem" class="fx-menu-danger" wire:click="$set('confirmingObjectDeletion', {{ $child->id }})" @click="m = false">
            <x-fx.icon name="x" /> {{ __('Delete') }}
        </button>
    </div>
</div>
