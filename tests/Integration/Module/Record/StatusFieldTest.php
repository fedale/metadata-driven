<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Application\State\TransitionContext;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\TransitionOption;
use App\Module\Record\Contract\Event\RecordTransition;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\Exception\TransitionRefused;
use App\Module\Record\Contract\RecordEventScopeInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Record\Contract\RecordTransitionsInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A Status field: a new record in its initial state, changed by its transitions only, each
 * taken by whoever holds one of its roles. The clerk holds ROLE_CLERK, the boss ROLE_BOSS; both
 * may update invoices.
 */
final class StatusFieldTest extends KernelTestCase
{
    private const array SETTINGS = [
        'states' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'sent', 'label' => 'Sent'], ['value' => 'paid', 'label' => 'Paid']],
        'initial' => 'draft',
        'transitions' => [
            ['name' => 'send', 'label' => 'Send', 'from' => ['draft'], 'to' => 'sent', 'roles' => ['ROLE_CLERK', 'ROLE_BOSS']],
            ['name' => 'pay', 'label' => 'Mark as paid', 'from' => ['sent'], 'to' => 'paid', 'roles' => []],
            ['name' => 'reopen', 'label' => 'Reopen', 'from' => ['sent', 'paid'], 'to' => 'draft', 'roles' => ['ROLE_BOSS']],
        ],
    ];

    private RecordManagerInterface $records;

    private RecordTransitionsInterface $transitions;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $metadata = $container->get(MetadataManagerInterface::class);
        $metadata->createObject('invoice', 'Invoice', 'Invoices');
        $metadata->createField('invoice', 'number', 'text', 'Number');
        $metadata->createField('invoice', 'state', 'status', 'State', settings: self::SETTINGS);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $rbac = $container->get(RbacManagerInterface::class);
        foreach (['clerk' => 'ROLE_CLERK', 'boss' => 'ROLE_BOSS'] as $who => $role) {
            $rbac->addItem($role, AuthItemType::ROLE);
            foreach (['read', 'create', 'update'] as $action) {
                $rbac->addChild($role, 'record.invoice.'.$action);
            }
            $container->get(UserManagerInterface::class)->createUser($who.'@example.com', 'correct horse battery staple');
            $rbac->assign($who.'@example.com', $role);
        }
        $this->records = $container->get(RecordManagerInterface::class);
        $this->transitions = $container->get(RecordTransitionsInterface::class);
        $this->signIn('clerk@example.com');
    }

    public function testANewRecordStartsInTheInitialState(): void
    {
        self::assertSame('draft', $this->records->create(new RecordInput('invoice', ['number' => 'A1']))->get('state'));

        try {
            $this->records->create(new RecordInput('invoice', ['number' => 'A2', 'state' => 'paid']));
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['state' => ['A new record starts in the state "Draft": its transitions change it.']], $e->errors);
        }
    }

    public function testOnlyItsTransitionsChangeIt(): void
    {
        $invoice = $this->records->create(new RecordInput('invoice', ['number' => 'A1']));
        try {
            $this->records->update($invoice->id, new RecordInput('invoice', ['state' => 'paid']));
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['state' => ['Changed by its transitions only.']], array_map(static fn (array $messages): array => array_map(strval(...), $messages), $e->errors));
        }
        // The same state, or another field: no change of state, no refusal.
        self::assertSame('A9', $this->records->update($invoice->id, new RecordInput('invoice', ['number' => 'A9', 'state' => 'draft']))->get('number'));

        self::assertSame(['send'], $this->names($this->transitions->available('invoice', $invoice)));
        $sent = $this->transitions->apply('invoice', $invoice->id, 'state', 'send');
        self::assertSame('sent', $sent->get('state'));
        // From sent, the clerk may pay (no role needed), not reopen (the boss's).
        self::assertSame(['pay'], $this->names($this->transitions->available('invoice', $sent)));
    }

    public function testATransitionIsTakenFromItsStatesByItsRoles(): void
    {
        $invoice = $this->records->create(new RecordInput('invoice', ['number' => 'A1']));
        $this->assertRefused('"Mark as paid" is not taken from "Draft".', fn () => $this->transitions->apply('invoice', $invoice->id, 'state', 'pay'));
        $this->transitions->apply('invoice', $invoice->id, 'state', 'send');
        $this->assertRefused('You may not take "Reopen".', fn () => $this->transitions->apply('invoice', $invoice->id, 'state', 'reopen'));
        $this->assertRefused('The status "state" has no transition "burn".', fn () => $this->transitions->apply('invoice', $invoice->id, 'state', 'burn'));

        $this->signIn('boss@example.com');
        self::assertSame('draft', $this->transitions->apply('invoice', $invoice->id, 'state', 'reopen')->get('state'));
    }

    public function testAPackagesWriteIsNotHeldToIt(): void
    {
        $invoice = $this->records->create(new RecordInput('invoice', ['number' => 'A1']));

        $paid = self::getContainer()->get(RecordEventScopeInterface::class)->withoutEvents(fn () => $this->records->update($invoice->id, new RecordInput('invoice', ['state' => 'paid'])));

        self::assertSame('paid', $paid->get('state'));
    }

    public function testATransitionFromAStateLeftMeanwhileIsRefused(): void
    {
        $invoice = $this->records->create(new RecordInput('invoice', ['number' => 'A1']));
        // Read as draft, then paid by someone else before it writes.
        self::getContainer()->get(RecordEventScopeInterface::class)->withoutEvents(fn () => $this->records->update($invoice->id, new RecordInput('invoice', ['state' => 'paid'])));

        try {
            self::getContainer()->get(TransitionContext::class)->run('invoice', $invoice->id, new RecordTransition('state', 'send', 'draft', 'sent'), fn () => $this->records->update($invoice->id, new RecordInput('invoice', ['state' => 'sent'])));
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['state' => ['Its state changed meanwhile: try again.']], array_map(static fn (array $messages): array => array_map(strval(...), $messages), $e->errors));
        }
    }

    public function testItsSettingsAreChecked(): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('Transition #0: "from" must list states of the field, one at least.');
        self::getContainer()->get(MetadataManagerInterface::class)->createField('invoice', 'other', 'status', 'Other', settings: [
            'states' => [['value' => 'a', 'label' => 'A']],
            'transitions' => [['name' => 'go', 'label' => 'Go', 'from' => ['nowhere'], 'to' => 'a', 'roles' => []]],
        ]);
    }

    /**
     * @param list<TransitionOption> $options
     *
     * @return list<string>
     */
    private function names(array $options): array
    {
        return array_map(static fn (TransitionOption $option): string => $option->name, $options);
    }

    /**
     * @param callable(): mixed $apply
     */
    private function assertRefused(string $message, callable $apply): void
    {
        try {
            $apply();
            self::fail('Refused.');
        } catch (TransitionRefused $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    private function signIn(string $email): void
    {
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier($email);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
