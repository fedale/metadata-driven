<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Event\RecordWriteAction;
use App\Module\Record\Contract\Event\RecordWriting;
use App\Module\Record\Contract\Event\RecordWritten;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordEventScopeInterface;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Shared\Transaction\TransactionRunner;
use App\Tests\Support\CreatesRelatedObjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The events of a record's write: RecordWriting before it, in its transaction, which may change
 * or refuse it; RecordWritten once the outermost transaction committed.
 */
final class RecordEventsTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private RecordManagerInterface $records;

    /** @var list<string> */
    private array $log = [];

    /** @var list<RecordWritten> */
    private array $written = [];

    protected function setUp(): void
    {
        self::createRelatedObjects(self::getContainer());
        $this->records = self::getContainer()->get(RecordManagerInterface::class);
        $events = self::getContainer()->get(EventDispatcherInterface::class);
        $events->addListener(RecordWriting::class, function (RecordWriting $event): void {
            $this->log[] = \sprintf('writing %s %s', $event->action->value, $event->objectName);
        });
        $events->addListener(RecordWritten::class, function (RecordWritten $event): void {
            $this->log[] = \sprintf('written %s %s', $event->action->value, $event->objectName);
            $this->written[] = $event;
        });
    }

    public function testTheWrittenEventsComeOnceTheOutermostTransactionCommitted(): void
    {
        self::getContainer()->get(TransactionRunner::class)->run(function (): void {
            $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));
            $this->records->create(new RecordInput('person', ['full_name' => 'Bea']));
            $this->log[] = 'commit';
        });

        self::assertSame(['writing create person', 'writing create person', 'commit', 'written create person', 'written create person'], $this->log);
        self::assertSame(['Ada', 'Bea'], array_map(static fn (RecordWritten $event): mixed => $event->record->get('full_name'), $this->written));
    }

    public function testARolledBackWriteRaisesNoWrittenEvent(): void
    {
        try {
            self::getContainer()->get(TransactionRunner::class)->run(function (): void {
                $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

                throw new \DomainException('rolled back');
            });
        } catch (\DomainException) {
        }

        self::assertSame(['writing create person'], $this->log);
    }

    public function testAListenerChangesTheValuesWhichAreValidatedAsTheOthers(): void
    {
        $this->listen(static function (RecordWriting $event): void {
            $event->setValue('city', 'Torino');
        });
        $person = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));
        self::assertSame('Torino', $person->get('city'));

        $this->listen(static function (RecordWriting $event): void {
            $event->setValue('full_name', '');
        });
        try {
            $this->records->create(new RecordInput('person', ['full_name' => 'Bea']));
            self::fail('A value set by a listener is validated.');
        } catch (RecordValidationFailed $e) {
            self::assertArrayHasKey('full_name', $e->errors);
        }
    }

    public function testAListenerRefusesTheWrite(): void
    {
        $person = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));
        $this->listen(static function (RecordWriting $event): void {
            $event->refuse('Closed for the night.');
        });

        try {
            $this->records->create(new RecordInput('person', ['full_name' => 'Bea']));
            self::fail('A refused create.');
        } catch (RecordValidationFailed $e) {
            self::assertSame([RecordValidationFailed::GLOBAL_ERRORS => ['Closed for the night.']], $e->errors);
        }
        try {
            $this->records->delete('person', $person->id);
            self::fail('A refused delete.');
        } catch (RecordDeleteRestricted $e) {
            self::assertSame('Closed for the night.', $e->getMessage());
        }

        self::assertCount(1, iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('person'), false));
    }

    public function testAnUpdateTellsWhatItWasAndWhatChanged(): void
    {
        $person = $this->records->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'Milano']));
        $this->written = [];

        $this->records->update($person->id, new RecordInput('person', ['city' => 'Torino']));

        $event = $this->written()[0] ?? self::fail('No event.');
        self::assertSame(RecordWriteAction::Update, $event->action);
        self::assertSame('Milano', $event->before?->get('city'));
        self::assertSame('Torino', $event->record->get('city'));
        self::assertSame(['city'], $event->changed);
    }

    public function testADeleteRaisesOnePerRecordOfItsCascadeWithTheRecordAsItWas(): void
    {
        $boss = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));
        $this->records->create(new RecordInput('person', ['full_name' => 'Bea', 'manager' => $boss->id]));
        $this->log = $this->written = [];

        $this->records->delete('person', $boss->id);

        self::assertSame(['writing delete person', 'writing delete person', 'written delete person', 'written delete person'], $this->log);
        self::assertSame(['Ada', 'Bea'], array_map(static fn (RecordWritten $event): mixed => $event->record->get('full_name'), $this->written()));
    }

    public function testTheScopeSilencesOrStampsTheWrites(): void
    {
        $scope = self::getContainer()->get(RecordEventScopeInterface::class);

        $scope->withoutEvents(fn () => $this->records->create(new RecordInput('person', ['full_name' => 'Ada'])));
        $scope->asRun('run-1', 1, false, fn () => $this->records->create(new RecordInput('person', ['full_name' => 'Bea'])));
        self::assertSame([], $this->log);

        $scope->asRun('run-2', 2, true, fn () => $this->records->create(new RecordInput('person', ['full_name' => 'Cleo'])));
        self::assertSame('run-2', $this->written[0]->origin?->runId);
        self::assertSame(2, $this->written[0]->origin->depth);

        // Out of it, no origin and events again.
        $this->records->create(new RecordInput('person', ['full_name' => 'Dora']));
        self::assertNull($this->written[1]->origin);
    }

    /**
     * What the listener collected: read through here, not narrowed to what the test assigned.
     *
     * @return list<RecordWritten>
     */
    private function written(): array
    {
        return $this->written;
    }

    /**
     * @param callable(RecordWriting): void $listener
     */
    private function listen(callable $listener): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(RecordWriting::class, $listener);
    }
}
