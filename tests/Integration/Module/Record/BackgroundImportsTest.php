<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Record\Application\Import\BackgroundImports;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Domain\Model\ImportJob;
use App\Module\Record\Domain\Model\ImportJobStatus;
use App\Module\Record\Domain\Repository\ImportJobRepository;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Workspace\CurrentWorkspace;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\RemovesDirectories;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the worker does with a background import, called as the worker calls it. The queue is
 * the caller's in the tests: queueCheck() returns once the check ran.
 */
final class BackgroundImportsTest extends KernelTestCase
{
    use CreatesPetObject;
    use RemovesDirectories;

    private const string CLERK = 'clerk@example.com';

    private string $directory;

    protected function setUp(): void
    {
        self::createPetObject(self::getContainer());
        $this->directory = sys_get_temp_dir().'/md-background-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        self::getContainer()->get(UserManagerInterface::class)->createUser(self::CLERK, 'correct horse battery staple', 'Ada', 'Lovelace');
        self::getContainer()->get(PermissionSynchronizer::class)->syncAll();
        foreach (['record.pet.read', 'record.pet.create', 'record.pet.update'] as $permission) {
            self::getContainer()->get(RbacManagerInterface::class)->assign(self::CLERK, $permission);
        }
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testARetriedImportGoesOnAfterTheBatchesWritten(): void
    {
        $job = $this->checkedJob();
        // A worker that died after the first batch, its progress committed with it.
        $this->importing($job);
        self::getContainer()->get(BackgroundImports::class)->import($this->workspace(), $job->id);
        self::assertSame(['Fido', 'Luna', 'Max'], $this->names());

        $done = $this->reload($job);
        self::assertSame([ImportJobStatus::Imported, 5, 3], [$done->status, $done->processed, $done->created]);
    }

    public function testAStopRequestEndsTheJobBeforeItsNextBatch(): void
    {
        $job = $this->checkedJob();
        $this->importing($job);
        self::getContainer()->get(ImportJobRepository::class)->requestStop($job);

        self::getContainer()->get(BackgroundImports::class)->import($this->workspace(), $job->id);

        self::assertSame(ImportJobStatus::Cancelled, $this->reload($job)->status);
        self::assertSame([], $this->names());
        self::assertFalse(self::getContainer()->get(BackgroundImports::class)->hasFile($job));
    }

    public function testAPermissionLostSinceTheCheckFailsTheImport(): void
    {
        $job = $this->checkedJob();
        self::getContainer()->get(RbacManagerInterface::class)->revoke(self::CLERK, 'record.pet.update');
        self::assertTrue(self::getContainer()->get(BackgroundImports::class)->queueImport($job));

        $failed = $this->reload($job);
        self::assertSame(ImportJobStatus::Failed, $failed->status);
        self::assertSame(['No permission to update the records of "pet": import without a key.'], $failed->reasons);
        self::assertSame([], $this->names());
    }

    public function testAMessageFailedForGoodFailsItsJob(): void
    {
        $job = $this->checkedJob();
        $this->importing($job, 0);

        self::getContainer()->get(BackgroundImports::class)->fail($this->workspace(), $job->id, new \RuntimeException('The database went away.'));

        $failed = $this->reload($job);
        self::assertSame(ImportJobStatus::Failed, $failed->status);
        self::assertSame(['An error stopped it: the details are in the log of the application.'], $failed->reasons);
        // A job no longer running is left as it is.
        self::getContainer()->get(BackgroundImports::class)->fail($this->workspace(), $job->id, new \RuntimeException('Again.'));
        self::assertCount(1, $this->reload($job)->reasons);
    }

    public function testKeepsASampleOfTheReasonsAndCountsThemAll(): void
    {
        $job = new ImportJob('00000000-0000-4000-8000-000000000000', 'pet', 'pets.csv', ',', [], null, [], self::CLERK, 'en', 1000, new \DateTimeImmutable());
        $job->addReasons(array_map(static fn (int $line): string => 'Line '.$line, range(1, 150)));
        $job->addReasons(array_map(static fn (int $line): string => 'Line '.$line, range(151, 300)));

        self::assertSame([ImportJob::MAX_REASONS, 300], [\count($job->reasons), $job->reasonCount]);
        self::assertSame('Line 200', $job->reasons[199]);
    }

    private function checkedJob(): ImportJob
    {
        $file = $this->directory.'/pets.csv';
        file_put_contents($file, "name,chip_code\nRex,A1\nTom,B2\nFido,C3\nLuna,D4\nMax,E5\n");
        $job = self::getContainer()->get(BackgroundImports::class)->queueCheck('pet', $file, 'pets.csv', ',', ['name' => 'name', 'chip_code' => 'chip_code'], 'chip_code', [], 5, self::CLERK, 'en');
        $checked = $this->reload($job);
        self::assertSame([ImportJobStatus::Checked, 5, 5], [$checked->status, $checked->processed, $checked->created], implode("\n", $checked->reasons));

        return $checked;
    }

    /**
     * The job as the worker finds it once its import was queued, $processed rows written.
     */
    private function importing(ImportJob $job, int $processed = 2): void
    {
        $jobs = self::getContainer()->get(ImportJobRepository::class);
        self::assertTrue($jobs->transition($job, ImportJobStatus::Checked, ImportJobStatus::Importing));
        $job->restart();
        $job->processed = $processed;
        $jobs->save($job);
    }

    private function reload(ImportJob $job): ImportJob
    {
        $reloaded = self::getContainer()->get(ImportJobRepository::class)->get($job->id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $names = array_map(static fn ($record): string => \is_string($record->values['name']) ? $record->values['name'] : '', self::getContainer()->get(RecordFinderInterface::class)->latest('pet', 10));
        sort($names);

        return $names;
    }

    private function workspace(): string
    {
        return self::getContainer()->get(CurrentWorkspace::class)->id();
    }
}
