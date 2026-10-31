<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\DeleteFormCommand;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\FormDefinitionNotFound;
use App\Module\Metadata\Domain\Repository\FormDefinitionRepository;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes the definition and the translations of its texts: the records the form wrote stay.
 */
final class DeleteFormHandler
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly TranslationRepository $translations,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function __invoke(DeleteFormCommand $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $this->forms->remove($this->forms->findByName($command->name) ?? throw FormDefinitionNotFound::named($command->name));
            $this->translations->removeKeysUnder([
                TranslationKey::form($command->name),
                'section.'.$command->name,
                'service.'.$command->name,
                'description.'.$command->name,
            ]);
        });
        $this->events->dispatch(new MetadataChanged($command->name));
    }
}
