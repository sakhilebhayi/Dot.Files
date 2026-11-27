<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\FilesActivity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers FilesActivity notices without ever failing the action that caused them (EA-3). Each recipient is sent to
 * separately after the response, so a slow or failing mail server neither delays the person who acted nor stops the
 * other recipients; the bell entry is written first, so a mail failure still leaves the notice in the app.
 */
class Notifier
{
    /** @var array<string, true> */
    private static array $delivered = [];

    /** @param  iterable<User|null>  $recipients */
    public function send(iterable $recipients, FilesActivity $notice): void
    {
        $ids = collect($recipients)->filter()->unique('id')->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        // Delivered exactly once, even where one process runs several requests' after-response work (a long-lived
        // worker, the test harness): each send carries a token, and a token is honoured once per process.
        $token = (string) Str::uuid();
        $deliver = function () use ($ids, $notice, $token) {
            if (isset(self::$delivered[$token])) {
                return;
            }
            self::$delivered[$token] = true;

            foreach (User::whereIn('id', $ids)->get() as $user) {
                try {
                    $user->notifyNow($notice, ['database']);
                } catch (Throwable $e) {
                    report($e);

                    continue;
                }

                try {
                    $user->notifyNow($notice, ['mail']);
                } catch (Throwable $e) {
                    Log::warning('Files notice email failed; the notice stays in the bell.', ['kind' => $notice->kind, 'user_id' => $user->id, 'error' => class_basename($e)]);
                }
            }
        };

        app()->runningInConsole() && ! app()->runningUnitTests() ? $deliver() : dispatch($deliver)->afterResponse();
    }
}
