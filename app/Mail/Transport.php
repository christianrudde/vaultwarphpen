<?php

namespace App\Mail;

use App\Http\ApiError;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Message;

/**
 * How mails leave: SMTP or sendmail, as configured (mail.rs,
 * send_with_selected_transport). Tests put a fake in the container.
 */
interface Transport
{
    /**
     * @throws ApiError With the message upstream gives the failure
     */
    public function send(Message $message, Envelope $envelope): void;
}
