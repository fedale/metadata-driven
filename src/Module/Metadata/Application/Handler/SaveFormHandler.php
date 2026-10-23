<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\SaveFormCommand;
use App\Module\Metadata\Application\Form\FormDefinitionCompiler;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Domain\Model\FormDefinition;
use App\Module\Metadata\Domain\Repository\FormDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates a composite form, or replaces the definition of an existing one.
 */
final class SaveFormHandler
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly FormDefinitionCompiler $compiler,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * @return string the id of the form
     */
    public function __invoke(SaveFormCommand $command): string
    {
        $compiled = $this->compiler->compile($command->definition);

        $id = $this->transactions->run(function () use ($command, $compiled): string {
            $form = $this->forms->findByName($command->name);
            if (null === $form) {
                $form = new FormDefinition(Uuid::v7(), $command->name, $compiled['label'], $command->definition, $compiled['root']);
            } else {
                $form->redefine($compiled['label'], $command->definition, $compiled['root']);
            }
            $this->forms->save($form);

            return $form->getId()->toRfc4122();
        });
        $this->events->dispatch(new MetadataChanged(\is_string($compiled['root']['object']) ? $compiled['root']['object'] : $command->name));

        return $id;
    }
}
