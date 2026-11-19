<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspaces;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The application's emails through the shared port: the sender, the layout, the language.
 */
final class MailerTest extends KernelTestCase
{
    public function testSendsFromTheWorkspaceInTheRecipientsLanguage(): void
    {
        self::getContainer()->get(Mailer::class)->send(new Mail('anna@acme.example', 'mail_test', 'mail_test.subject', context: ['sent_at' => '2026-11-19 10:00'], locale: 'it'));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEquals([new Address('anna@acme.example')], $email->getTo());
        $container = self::getContainer();
        $workspace = $container->get(Workspaces::class)->byId($container->get(CurrentWorkspace::class)->id());
        self::assertNotNull($workspace);
        // No MAILER_FROM: an address of the base host, under the workspace's name.
        self::assertEquals([new Address('noreply@localhost', $workspace->name)], $email->getFrom());
        self::assertSame('Un\'email di prova', $email->getSubject());
        self::assertEmailHtmlBodyContains($email, 'Inviata alle 2026-11-19 10:00 (UTC).');
        self::assertEmailHtmlBodyContains($email, '<html lang="it">');
        self::assertEmailTextBodyContains($email, 'Questa è un\'email di prova');
        self::assertEmailTextBodyContains($email, 'le risposte non vengono lette');
    }

    public function testWithoutALanguageTheApplicationsAndANameOfItsOwn(): void
    {
        self::getContainer()->get(Mailer::class)->send(new Mail('anna@acme.example', 'mail_test', 'mail_test.subject', context: ['sent_at' => 'now'], senderName: 'Acme Corp'));

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('A test email', $email->getSubject());
        self::assertSame('Acme Corp', $email->getFrom()[0]->getName());
        self::assertEmailHtmlBodyContains($email, 'Acme Corp');
    }

    public function testAnAddressThatIsNotOneIsRefused(): void
    {
        $this->expectException(MailNotSent::class);
        $this->expectExceptionMessage('The email to "not an address" could not be sent: not an email address.');

        self::getContainer()->get(Mailer::class)->send(new Mail('not an address', 'mail_test', 'mail_test.subject'));
    }

    public function testTheTestCommandQueuesOne(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('mail:test'));

        self::assertSame(0, $tester->execute(['to' => 'ops@acme.example']));
        self::assertEmailCount(1);
        self::assertStringContainsString('queued', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['to' => 'nobody']));
    }
}
