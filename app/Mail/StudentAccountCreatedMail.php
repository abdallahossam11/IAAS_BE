<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Student $student,
        public readonly string $plainPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Galala IAAS Account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-account-created',
            with: [
                'student' => $this->student,
                'plainPassword' => $this->plainPassword,
                'loginUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login',
            ],
        );
    }
}
