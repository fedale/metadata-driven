<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Secrets\SecretBox;
use App\Module\Workflow\Application\Secrets\SecretsKeyMissing;
use App\Module\Workflow\Application\Secrets\WorkflowSecrets;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Tests\Support\FakePackageServer;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A workspace's secrets: encrypted, sent in an HTTP request, written nowhere in its runs, read
 * nowhere else.
 */
final class WorkflowSecretsTest extends KernelTestCase
{
    /** Characters an address, JSON and base64 write otherwise. */
    private const string TOKEN = 'tok/3f9a very+secret"&=';

    protected function setUp(): void
    {
        FakePackageServer::reset();
        $container = self::getContainer();
        $metadata = $container->get(MetadataManagerInterface::class);
        $metadata->createObject('note', 'Note', 'Notes');
        $metadata->createField('note', 'text', 'text', 'Text');
        $container->get(PermissionSynchronizer::class)->syncAll();
        $container->get(UserManagerInterface::class)->createUser('owner@example.com', 'correct horse battery staple');
        $container->get(RbacManagerInterface::class)->assign('owner@example.com', Permissions::SUPER_ADMIN_ROLE);
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('owner@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $container->get(WorkflowSecrets::class)->set('crm_token', self::TOKEN, 'owner@example.com');
    }

    public function testKeptEncryptedAndReadBack(): void
    {
        $secrets = self::getContainer()->get(WorkflowSecrets::class);
        self::assertSame(['crm_token' => self::TOKEN], $secrets->values());
        $stored = self::getContainer()->get(Connection::class)->fetchOne('SELECT value FROM core.workflow_secret');
        self::assertIsString($stored);
        self::assertStringNotContainsString(self::TOKEN, (string) base64_decode($stored, true));

        // Another key: the value cannot be read; no key: none is written.
        $this->expectException(\UnexpectedValueException::class);
        (new SecretBox(SecretBox::generateKey()))->open($stored);
    }

    public function testNoKeyNoSecret(): void
    {
        $this->expectException(SecretsKeyMissing::class);
        (new SecretBox(null))->seal('x');
    }

    public function testSentInTheRequestWrittenNowhereInTheRun(): void
    {
        // The answer echoes the request, its credentials in base64 too.
        FakePackageServer::answerAny(static fn (string $method, array $options): MockResponse => new MockResponse((string) json_encode(['echo' => $options['headers'] ?? [], 'basic' => base64_encode(self::TOKEN)]), ['response_headers' => ['content-type' => 'application/json']]));
        $manager = self::getContainer()->get(WorkflowManager::class);
        $manager->activate($manager->create('Hook', null, <<<'YAML'
            trigger: { on: created, object: note, when: after }
            steps:
              - { key: hook, type: http_request, url: 'https://crm.example.test/hook?key={{ $secrets.crm_token }}', headers: { Authorization: 'Bearer {{ $secrets.crm_token }}' } }
            YAML));

        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('note', ['text' => 'Hi']));

        [$method, $url, $options] = FakePackageServer::requests()[0];
        self::assertSame('https://crm.example.test/hook?key='.self::TOKEN, rawurldecode($url));
        self::assertContains('Authorization: Bearer '.self::TOKEN, \is_array($options['headers'] ?? null) ? $options['headers'] : []);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame('completed', $connection->fetchOne('SELECT status FROM core.workflow_run'));
        $logged = (string) json_encode([$connection->fetchAllAssociative('SELECT chain FROM core.workflow_run'), $connection->fetchAllAssociative('SELECT input, output FROM core.workflow_run_step')]);
        foreach ([self::TOKEN, rawurlencode(self::TOKEN), urlencode(self::TOKEN), substr((string) json_encode(self::TOKEN), 1, -1), base64_encode(self::TOKEN)] as $form) {
            self::assertStringNotContainsString($form, $logged, $form);
            self::assertStringNotContainsString($form, stripslashes($logged), $form);
        }
        self::assertStringContainsString('[secret]', $logged);
    }

    public function testAFailureTellsNoSecret(): void
    {
        FakePackageServer::answerAny(static fn (): MockResponse => new MockResponse([new \RuntimeException('Connection to '.self::TOKEN.' reset')]));
        $manager = self::getContainer()->get(WorkflowManager::class);
        $manager->activate($manager->create('Hook', null, "trigger: { on: created, object: note, when: after }\nsteps: [{ key: hook, type: http_request, url: 'https://crm.example.test/{{ \$secrets.crm_token }}' }]"));

        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('note', ['text' => 'Hi']));

        self::assertSame('The request to crm.example.test got no answer.', self::getContainer()->get(Connection::class)->fetchOne('SELECT error FROM core.workflow_run'));
    }

    public function testReadInAnHttpRequestOnly(): void
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        try {
            $manager->activate($manager->create('Leak', null, <<<'YAML'
                trigger: { on: created, object: note, when: after }
                steps:
                  - { key: leak, type: create_record, object: note, values: { text: '{{ $secrets.crm_token }}' } }
                  - { key: hook, type: http_request, url: 'https://crm.example.test/{{ $secrets.nope }}' }
                YAML));
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['A secret is read in an http_request\'s url, headers and body only: nowhere it could be written down.'], array_map(strval(...), $e->errors['steps.0.values.text']));
            self::assertSame(['There is no secret "nope": add it on the page of the secrets.'], array_map(strval(...), $e->errors['steps.1.url']));
        }
    }
}
