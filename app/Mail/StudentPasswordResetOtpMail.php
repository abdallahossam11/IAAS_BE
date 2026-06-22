<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentPasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otpCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Galala IAAS Password Reset Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-password-reset-otp',
            with: [
                'otpCode' => $this->otpCode,
            ],
        );
    }
}
