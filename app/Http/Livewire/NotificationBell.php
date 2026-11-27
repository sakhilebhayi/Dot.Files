<?php

namespace App\Http\Livewire;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The bell in the header (EA-3): this person's own notices (share requests waiting for them, decisions on what they
 * asked for), newest first, with the unread count on the bell. Opening one marks it read and goes where it points.
 * Only the signed-in person's own notifications are ever read or changed.
 */
class NotificationBell extends Component
{
    public const SHOWN = 8;

    public function open(string $id)
    {
        $notice = $this->mine()->whereKey($id)->first();

        if ($notice === null) {
            return null;
        }

        $notice->markAsRead();
        $url = (string) ($notice->data['url'] ?? '');

        // Only this app's own addresses are followed, whatever a stored notice says.
        return $this->redirect(str_starts_with($url, url('/')) ? $url : route('files'));
    }

    public function markAllRead(): void
    {
        $this->mine()->whereNull('read_at')->update(['read_at' => now()]);
    }

    private function mine()
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', auth()->user()->getMorphClass())
            ->where('notifiable_id', auth()->id());
    }

    public function render(): View
    {
        return view('livewire.notification-bell', [
            'unread' => $this->mine()->whereNull('read_at')->count(),
            'notices' => $this->mine()->latest()->limit(self::SHOWN)->get(),
        ]);
    }
}
