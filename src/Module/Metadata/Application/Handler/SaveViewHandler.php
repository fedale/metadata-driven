<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\SaveViewCommand;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates or replaces a view. The template is sanitized here, once, before it is stored:
 * the pages print it as it is.
 */
final class SaveViewHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        #[Target('view_layout')]
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * @return string the id of the view
     */
    public function __invoke(SaveViewCommand $command): string
    {
        $template = $this->sanitizer->sanitize($command->template);

        $id = $this->transactions->run(function () use ($command, $template): string {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);

            $view = $object->saveView(Uuid::v7(), $command->kind, $command->name, $template, $command->isDefault);
            $this->objects->save($object);

            return $view->getId()->toRfc4122();
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));

        return $id;
    }
}
