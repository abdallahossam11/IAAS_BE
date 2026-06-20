<?php

namespace App\Mail;

use App\Models\Admin;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Admin $admin,
        public readonly string $plainPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Galala IAAS Admin Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-account-created',
            with: [
                'admin' => $this->admin,
                'plainPassword' => $this->plainPassword,
                'loginUrl' => rtrim((string) config('app.admin_url', config('app.url')), '/').'/admin/login',
            ],
        );
    }
}
