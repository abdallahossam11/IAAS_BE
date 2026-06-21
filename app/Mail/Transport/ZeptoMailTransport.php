<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ZeptoMailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $host,
        private readonly string $authorization,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new TransportException('ZeptoMailTransport only supports Symfony Email messages.');
        }

        $from = $email->getFrom()[0] ?? null;

        if (! $from instanceof Address) {
            throw new TransportException('ZeptoMail requires a sender address.');
        }

        $payload = [
            'from' => $this->sender($from),
            'to' => $this->recipients($email->getTo()),
            'subject' => $email->getSubject() ?? '',
        ];

        if ($email->getCc() !== []) {
            $payload['cc'] = $this->recipients($email->getCc());
        }

        if ($email->getBcc() !== []) {
            $payload['bcc'] = $this->recipients($email->getBcc());
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        if (is_string($html) && $html !== '') {
            $payload['htmlbody'] = $html;

            if (is_string($text) && $text !== '') {
                $payload['textbody'] = $text;
            }
        } else {
            $payload['textbody'] = $text ?: '';
        }

        if ($email->getAttachments() !== []) {
            throw new TransportException('ZeptoMailTransport does not support attachments in this production hotfix.');
        }

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => $this->authorization,
        ])
            ->timeout(45)
            ->retry(2, 500)
            ->post('https://' . $this->host . '/v1.1/email', $payload);

        if (! $response->successful()) {
            throw new TransportException('ZeptoMail API failed HTTP '.$response->status().': '.$response->body());
        }
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }

    private function sender(Address $address): array
    {
        $data = ['address' => $address->getAddress()];

        if ($address->getName() !== '') {
            $data['name'] = $address->getName();
        }

        return $data;
    }

    private function recipients(array $addresses): array
    {
        if ($addresses === []) {
            throw new TransportException('ZeptoMail requires at least one recipient.');
        }

        return array_map(fn (Address $address): array => [
            'email_address' => $this->sender($address),
        ], $addresses);
    }
}
