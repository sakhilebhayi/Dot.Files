<?php

namespace App\Mail;

use App\Models\File;
use App\Models\FileFlag;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The alert behind a governance flag (R-28 §29): what was seen, on which record, with a link to
 * the approvals desk where the owner decides. Worded as a question for review, never a verdict.
 */
class SuspiciousActivityAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public FileFlag $flag,
        public File $file,
        public Team $team,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Unusual activity to review').' — '.$this->file->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.suspicious-activity',
            with: [
                'ruleLabel' => $this->flag->ruleLabel(),
                'actorName' => $this->flag->actor?->name ?? __('a member'),
                'url' => route('approvals'),
            ],
        );
    }
}
