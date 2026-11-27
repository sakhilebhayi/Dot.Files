<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notice about something that happened to a person's request or needs their decision (EA-3): a share asked for,
 * a share decided, a deletion request decided. It lands in the bell (database) and by email. The text is plain: names
 * of files, people and companies come from users, so they are stripped of Markdown before they reach the mail.
 */
class FilesActivity extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  share.requested | share.approved | share.declined | deletion.approved | deletion.rejected
     * @param  array<int, string>  $lines  plain sentences, shown in the bell and the email
     */
    public function __construct(
        public string $kind,
        public string $title,
        public array $lines,
        public string $url,
        public string $action,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(self::plain($this->title))->greeting(self::plain($this->title));

        foreach ($this->lines as $line) {
            $mail->line(self::plain($line));
        }

        return $mail->action($this->action, $this->url)
            ->line('You receive this because you are a member of a company on Dot.Files.');
    }

    /** @return array{kind: string, title: string, body: string, url: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => implode(' ', $this->lines),
            'url' => $this->url,
        ];
    }

    /**
     * User-supplied text made inert for Markdown mail: no HTML, no link or image syntax, no line breaks (so no headings,
     * lists or quotes). Brackets become parentheses; everything else, a file's real name included, is kept.
     */
    public static function plain(string $text): string
    {
        $text = strip_tags($text);
        $text = strtr($text, ['[' => '(', ']' => ')', '<' => '(', '>' => ')', '`' => "'"]);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
