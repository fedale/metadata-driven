<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\RenameFieldCommand;
use App\Module\Metadata\Application\Lifecycle\DefinitionRewriter;
use App\Module\Metadata\Application\Lifecycle\FormRewrites;
use App\Module\Metadata\Application\View\GridViewCompiler;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Event\FieldDefinitionRenamed;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataDependentInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Renames a field and everything naming it, in one transaction: what its object says about it
 * (label field, searched fields, conditions, cascades), the cascades of other objects through
 * it, the views of its object, the forms, its translations, the other modules' definitions naming
 * it (the workflows, through MetadataDependentInterface); the listener of FieldDefinitionRenamed
 * renames its column.
 */
final class RenameFieldHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly TranslationRepository $translations,
        private readonly MetadataRegistryInterface $metadata,
        private readonly DefinitionRewriter $rewriter,
        private readonly FormRewrites $forms,
        private readonly GridViewCompiler $grids,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
        /** @var iterable<MetadataDependentInterface> */
        #[AutowireIterator('app.metadata_dependent')]
        private readonly iterable $dependents = [],
    ) {
    }

    /**
     * @return list<string> the definitions written by hand that were rewritten
     */
    public function __invoke(RenameFieldCommand $command): array
    {
        $objectName = $command->objectName;
        $old = $command->fieldName;
        $new = $command->newName;
        $rewritten = $this->transactions->run(function () use ($objectName, $old, $new): array {
            $object = $this->objects->findByName($objectName);
            if (null === $object || !$object->isActive()) {
                throw ObjectDefinitionNotFound::named($objectName);
            }
            $object->getField($old);
            if ($old === $new) {
                return [];
            }
            $before = $this->metadata->getObject($objectName);

            $object->renameField($old, $new);
            $this->objects->save($object);
            foreach ($this->objects->findAll() as $other) {
                $other->followTargetFieldRename($objectName, $old, $new);
                $this->objects->save($other);
            }

            $rewritten = [];
            $renamed = $this->metadata->getObject($objectName);
            foreach ($object->getViews() as $view) {
                if (ViewKind::Form === $view->getKind()) {
                    $source = $this->rewriter->tokens($view->getSource(), $old, $new);
                    $definition = null;
                } else {
                    $source = $this->rewriter->gridField($view->getSource(), $old, $new);
                    $definition = $source === $view->getSource() ? $view->getDefinition() : $this->grids->compile($renamed, $source);
                }
                if ($source !== $view->getSource()) {
                    $object->saveView($view->getId(), $view->getKind(), $view->getName(), $source, $view->isDefault(), $definition);
                    $rewritten[] = \sprintf('%s view "%s"', $view->getKind()->value, $view->getName());
                }
            }
            $this->objects->save($object);
            $this->translations->moveKeysUnder(array_combine(TranslationKey::fieldPrefixes($objectName, $old), TranslationKey::fieldPrefixes($objectName, $new)));
            $rewritten = [...$rewritten, ...$this->forms->apply(fn (string $source): string => $this->rewriter->formField($source, $objectName, $old, $new))];
            foreach ($this->dependents as $dependent) {
                $rewritten = [...$rewritten, ...$dependent->fieldRenamed($objectName, $old, $new)];
            }

            $this->events->dispatch(new FieldDefinitionRenamed($before, $old, $new));

            return $rewritten;
        });
        $this->events->dispatch(new MetadataChanged($objectName));

        return $rewritten;
    }
}
