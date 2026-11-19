<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * Sends the application's emails: from MAILER_FROM, in the layout of templates/email/, through
 * the queue (the worker sends them; MAILER_DSN says where to).
 *
 * A port of the shared kernel so that every module writes its emails the same way, with the same
 * sender and layout, without reaching into the infrastructure.
 */
interface Mailer
{
    /**
     * Rendered now, in the recipient's language, and queued.
     *
     * @throws MailNotSent the address is not one, or the email could not be queued
     */
    public function send(Mail $mail): void;
}
