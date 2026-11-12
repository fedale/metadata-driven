<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Infrastructure\Cache\MetadataRevision;
use App\Shared\Database\TenantScope;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\CreatesRegistrationForm;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The metadata revision, the part of the cache keys that ties them to a state of the metadata:
 * every write to a metadata table bumps it, in its own transaction, whichever way it is made.
 */
final class MetadataRevisionTest extends KernelTestCase
{
    use CreatesPetObject;
    use CreatesRegistrationForm;

    public function testEveryKindOfMetadataWriteBumpsTheRevision(): void
    {
        $container = self::getContainer();
        $manager = $container->get(MetadataManagerInterface::class);
        $revisions = [$this->revision()];

        self::createPetObject($container);                       // objects and fields
        $revisions[] = $this->revision();
        $manager->saveView('pet', ViewKind::Grid, 'main', "columns: [name]\n");
        $revisions[] = $this->revision();
        self::createRegistrationForm($container);                // a composite form
        $revisions[] = $this->revision();
        $manager->translate('it', 'object.pet.singular', 'Animale');
        $revisions[] = $this->revision();
        $manager->relabelField('pet', 'name', 'Full name');
        $revisions[] = $this->revision();

        for ($i = 1; $i < \count($revisions); ++$i) {
            self::assertGreaterThan($revisions[$i - 1], $revisions[$i], \sprintf('Write %d did not bump the revision.', $i));
        }
    }

    public function testTheKeyFollowsAChangeMadeInTheSameProcess(): void
    {
        $revision = self::getContainer()->get(MetadataRevision::class);
        $before = $revision->current();
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}\.\d+$/', $before);
        // Read once per request: the same until a change.
        self::assertSame($before, $revision->current());

        self::createPetObject(self::getContainer());

        $after = $revision->current();
        self::assertNotSame($before, $after);
        self::assertSame(strtok($before, '.'), strtok($after, '.'), 'The instance keeps its id.');
    }

    private function revision(): int
    {
        $revision = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT revision FROM core.metadata_state WHERE tenant_id = '.TenantScope::CURRENT,
        );
        self::assertIsInt($revision);

        return $revision;
    }
}
