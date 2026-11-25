<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation a new person gets when an account is created for them: company name, username, starting password
 * and where to sign in. The wording is a placeholder for now. It lives in two views: invitation-html.blade.php (branded to
 * StartupLab_Brand_Design_System.html) and invitation.blade.php (plain text). Change both when the final text arrives.
 */
class InvitationMail extends Mailable
{
    public function __construct(
        public string $recipientName,
        public string $username,
        public string $startingPassword,
        public string $company,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->company} account is ready");
    }

    public function content(): Content
    {
        // The branded version is the one most people see; the plain-text copy is for clients that cannot show it.
        return new Content(view: 'mail.invitation-html', text: 'mail.invitation');
    }
}
