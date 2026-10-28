<?php

namespace Tests\Fakes;

use App\Http\ApiError;
use App\Mail\Transport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Keeps the mails instead of sending them; fails every send once told to.
 */
final class MailTransportFake implements Transport
{
    /**
     * @var list<array{to: string, subject: string, text: string, html: string, message: Message}>
     */
    public array $sent = [];

    public ?string $failure = null;

    public function send(Message $message, Envelope $envelope): void
    {
        if ($this->failure !== null) {
            throw ApiError::message($this->failure);
        }

        $texts = ['plain' => '', 'html' => ''];
        $collect = static function (mixed $part) use (&$collect, &$texts): void {
            if ($part instanceof TextPart && isset($texts[$part->getMediaSubtype()])) {
                $texts[$part->getMediaSubtype()] = $part->getBody();
            } elseif ($part instanceof AbstractMultipartPart) {
                array_map($collect, $part->getParts());
            }
        };
        $collect($message->getBody());

        $this->sent[] = [
            'to' => $envelope->getRecipients()[0]->getAddress(),
            'subject' => (string) $message->getHeaders()->get('Subject')?->getBodyAsString(),
            'text' => $texts['plain'],
            'html' => $texts['html'],
            'message' => $message,
        ];
    }

    /**
     * The last mail to the address.
     *
     * @return array{to: string, subject: string, text: string, html: string, message: Message}
     */
    public function last(string $to): array
    {
        foreach (array_reverse($this->sent) as $mail) {
            if ($mail['to'] === $to) {
                return $mail;
            }
        }

        throw new \RuntimeException("No mail to {$to}.");
    }
}
