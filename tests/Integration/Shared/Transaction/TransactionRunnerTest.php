<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Transaction;

use App\Shared\Transaction\TransactionRunner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * whenDone(): the work deferred until the outermost transaction ends, told whether what was done
 * where it was deferred is kept.
 */
final class TransactionRunnerTest extends KernelTestCase
{
    /** @var list<string> */
    private array $log = [];

    public function testRunsAtOnceWithNoTransactionOpen(): void
    {
        $this->runner()->whenDone($this->record('alone'));

        self::assertSame(['alone: committed'], $this->log);
    }

    public function testWaitsForTheOutermostCommit(): void
    {
        $runner = $this->runner();
        $runner->run(function () use ($runner): void {
            $runner->run(function () use ($runner): void {
                $runner->whenDone($this->record('inner'));
            });
            $this->log[] = 'outer still open';
            $runner->whenDone($this->record('outer'));
        });

        self::assertSame(['outer still open', 'inner: committed', 'outer: committed'], $this->log);
    }

    public function testTellsARollback(): void
    {
        $runner = $this->runner();
        try {
            $runner->run(function () use ($runner): void {
                $runner->whenDone($this->record('refused'));

                throw new \DomainException('refused');
            });
        } catch (\DomainException) {
        }

        self::assertSame(['refused: rolled back'], $this->log);
    }

    public function testANestedRollbackIsToldSoThoughTheOuterCommits(): void
    {
        $runner = $this->runner();
        $runner->run(function () use ($runner): void {
            try {
                $runner->run(function () use ($runner): void {
                    $runner->whenDone($this->record('inner'));

                    throw new \DomainException('inner refused');
                });
            } catch (\DomainException) {
            }
            $runner->whenDone($this->record('outer'));
        });

        self::assertSame(['outer: committed', 'inner: rolled back'], $this->log);
    }

    public function testTheWorkRunsOutsideAnyTransaction(): void
    {
        $runner = $this->runner();
        $runner->run(function () use ($runner): void {
            $runner->whenDone(function () use ($runner): void {
                // Deferred again from there: no transaction is open, so at once.
                $runner->whenDone($this->record('from the deferred work'));
            });
        });

        self::assertSame(['from the deferred work: committed'], $this->log);
    }

    public function testEveryWorkRunsThoughOneThrows(): void
    {
        $runner = $this->runner();
        try {
            $runner->run(function () use ($runner): void {
                $runner->whenDone(static fn () => throw new \RuntimeException('broken'));
                $runner->whenDone($this->record('next'));
            });
            self::fail('The first exception is thrown once every work ran.');
        } catch (\RuntimeException $e) {
            self::assertSame('broken', $e->getMessage());
        }

        self::assertSame(['next: committed'], $this->log);
    }

    private function runner(): TransactionRunner
    {
        return self::getContainer()->get(TransactionRunner::class);
    }

    /**
     * @return callable(bool): void
     */
    private function record(string $name): callable
    {
        return function (bool $committed) use ($name): void {
            $this->log[] = $name.': '.($committed ? 'committed' : 'rolled back');
        };
    }
}
