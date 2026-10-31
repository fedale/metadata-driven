<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\SaveViewCommand;
use App\Module\Metadata\Application\View\GridViewCompiler;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates or replaces a view. The template of a form view is sanitized here, once, before it
 * is stored: the pages print it as it is. A grid view is compiled (and checked) here, and
 * stored with its YAML source as written.
 */
final class SaveViewHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        #[Target('view_layout')]
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly GridViewCompiler $grids,
        private readonly MetadataRegistryInterface $metadata,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * @return string the id of the view
     */
    public function __invoke(SaveViewCommand $command): string
    {
        $template = $command->template;
        $definition = null;
        if (ViewKind::Grid === $command->kind) {
            if (!$this->metadata->hasObject($command->objectName)) {
                throw ObjectDefinitionNotFound::named($command->objectName);
            }
            $definition = $this->grids->compile($this->metadata->getObject($command->objectName), $template);
        } else {
            $template = $this->sanitizer->sanitize($template);
        }

        $id = $this->transactions->run(function () use ($command, $template, $definition): string {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);

            $view = $object->saveView(Uuid::v7(), $command->kind, $command->name, $template, $command->isDefault, $definition);
            $this->objects->save($object);

            return $view->getId()->toRfc4122();
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));

        return $id;
    }
}
