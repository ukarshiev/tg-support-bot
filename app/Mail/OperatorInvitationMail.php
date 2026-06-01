<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued invitation email sent to a newly created operator.
 *
 * Contains:
 *  - The operator's email address.
 *  - A one-time generated plain-text password.
 *  - The login URL so the operator can sign in immediately.
 *
 * The mail driver is 'log' locally (see config/mail.php), so no real SMTP is required.
 * NEVER log the plain-text password. After the Mailable is serialised into the queue the
 * $password property is discarded from memory.
 */
class OperatorInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param User   $user     The newly created operator.
     * @param string $password The generated plain-text password (not stored in DB).
     */
    public function __construct(
        public readonly User $user,
        public readonly string $password,
    ) {
    }

    /**
     * Get the message envelope.
     *
     * @return Envelope
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Приглашение в команду поддержки',
        );
    }

    /**
     * Get the message content definition.
     *
     * @return Content
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.operator-invitation',
            with: [
                'loginUrl' => url('/admin/login'),
                'email' => $this->user->email,
                'password' => $this->password,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
