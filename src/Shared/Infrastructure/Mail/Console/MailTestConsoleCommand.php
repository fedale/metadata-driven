<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail\Console;

use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends a test email, to check MAILER_DSN and MAILER_FROM: it is queued like any other, so it
 * leaves once the worker takes it (messenger:consume async).
 */
#[AsCommand(name: 'mail:test', description: 'Send a test email, to check MAILER_DSN and MAILER_FROM (sent by the worker)')]
final class MailTestConsoleCommand
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The address to send it to')]
        string $to,
        #[Option('Its language (en, it); default: the application\'s')]
        ?string $locale = null,
    ): int {
        try {
            $this->mailer->send(new Mail($to, 'mail_test', 'mail_test.subject', context: ['sent_at' => gmdate('Y-m-d H:i')], locale: $locale));
        } catch (MailNotSent $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('A test email to %s is queued: the worker (messenger:consume async) sends it. In development, Mailpit shows it on http://localhost:8025.', $to));

        return Command::SUCCESS;
    }
}
