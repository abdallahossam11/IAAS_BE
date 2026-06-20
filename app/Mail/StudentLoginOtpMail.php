<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentLoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otpCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Galala IAAS Login Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-login-otp',
            with: [
                'otpCode' => $this->otpCode,
            ],
        );
    }
}
