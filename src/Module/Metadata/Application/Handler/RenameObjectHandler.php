<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\RenameObjectCommand;
use App\Module\Metadata\Application\Lifecycle\DefinitionRewriter;
use App\Module\Metadata\Application\Lifecycle\FormRewrites;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Event\ObjectDefinitionRenamed;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Renames an object and everything named after it, in one transaction: the relations pointing
 * at it, the composite forms naming it, its translations; the listeners of
 * ObjectDefinitionRenamed rename its table and its permissions.
 */
final class RenameObjectHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly TranslationRepository $translations,
        private readonly MetadataRegistryInterface $metadata,
        private readonly DefinitionRewriter $rewriter,
        private readonly FormRewrites $forms,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * @return list<string> the definitions written by hand that were rewritten
     */
    public function __invoke(RenameObjectCommand $command): array
    {
        $old = $command->objectName;
        $new = $command->newName;
        $rewritten = $this->transactions->run(function () use ($old, $new): array {
            $object = $this->objects->findByName($old);
            if (null === $object || !$object->isActive()) {
                throw ObjectDefinitionNotFound::named($old);
            }
            if ($object->isSystem()) {
                throw InvalidMetadataDefinition::systemObject($old, 'renamed');
            }
            if ($old === $new) {
                return [];
            }
            $taken = $this->objects->findByName($new);
            if (null !== $taken) {
                throw $taken->isActive() ? InvalidMetadataDefinition::duplicateObject($new) : InvalidMetadataDefinition::archivedObjectNameTaken($new);
            }
            $before = $this->metadata->getObject($old);

            $object->rename($new);
            $this->objects->save($object);
            foreach ($this->objects->findAll() as $other) {
                $other->retargetRelations($old, $new);
                $this->objects->save($other);
            }
            $this->translations->moveKeysUnder(array_combine(TranslationKey::objectPrefixes($old), TranslationKey::objectPrefixes($new)));
            $rewritten = $this->forms->apply(fn (string $source): string => $this->rewriter->formObject($source, $old, $new));

            $this->events->dispatch(new ObjectDefinitionRenamed($before, $new));

            return $rewritten;
        });
        $this->events->dispatch(new MetadataChanged($old));
        $this->events->dispatch(new MetadataChanged($new));

        return $rewritten;
    }
}
