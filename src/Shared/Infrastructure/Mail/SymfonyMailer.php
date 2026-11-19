<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspaces;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Symfony Mailer behind the shared port. The email is rendered when it is sent, in the request
 * (Symfony renders a TemplatedEmail before it queues it), in the recipient's language
 * (TemplatedEmail::locale()); the worker only hands it to MAILER_DSN.
 */
final class SymfonyMailer implements Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly CurrentWorkspace $current,
        private readonly Workspaces $workspaces,
        #[Autowire('%app.mail.from%')]
        private readonly string $from,
        #[Autowire('%app.mail.sender_name%')]
        private readonly string $applicationName,
        #[Autowire('%app.workspace.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    public function send(Mail $mail): void
    {
        $locale = $mail->locale ?? $this->defaultLocale;
        $senderName = $mail->senderName ?? $this->workspaceName() ?? $this->applicationName;
        try {
            $to = new Address($mail->to);
        } catch (RfcComplianceException) {
            throw MailNotSent::to($mail->to);
        }
        $email = (new TemplatedEmail())
            // No MAILER_FROM: an address of the installation's own domain, which its SPF covers.
            ->from(new Address('' === $this->from ? 'noreply@'.$this->baseHost : $this->from, $senderName))
            ->to($to)
            ->subject($this->translator->trans($mail->subject, $mail->subjectParameters, 'email', $locale))
            ->htmlTemplate('email/'.$mail->template.'.html.twig')
            ->textTemplate('email/'.$mail->template.'.txt.twig')
            ->context([...$mail->context, 'sender_name' => $senderName, 'locale' => $locale])
            ->locale($locale);
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface|MessengerException $e) {
            throw MailNotSent::to($mail->to, $e);
        }
    }

    private function workspaceName(): ?string
    {
        return $this->current->isSelected() ? $this->workspaces->byId($this->current->id())?->name : null;
    }
}
