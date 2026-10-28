<?php

namespace App\Mail;

use App\Config\Config;
use App\Http\ApiError;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Message;

/**
 * mail::sendmail_transport: lettre runs `<command> -i -f <from> -- <to>` with
 * the message on stdin; SENDMAIL_COMMAND or `sendmail` on the PATH.
 */
final readonly class SendmailTransport implements Transport
{
    public function __construct(
        private Config $config,
    ) {}

    public function send(Message $message, Envelope $envelope): void
    {
        $command = [$this->config->optionalString('sendmail_command') ?? 'sendmail', '-i', '-f', $envelope->getSender()->getAddress(), '--'];
        foreach ($envelope->getRecipients() as $recipient) {
            $command[] = $recipient->getAddress();
        }

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw ApiError::message('Sendmail error: cannot run '.$command[0]);
        }
        fwrite($pipes[0], $message->toString());
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        if ($status !== 0) {
            throw ApiError::message("Sendmail response error: exit status {$status}: ".trim($stderr));
        }
    }
}
