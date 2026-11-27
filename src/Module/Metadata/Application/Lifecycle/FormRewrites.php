<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Lifecycle;

use App\Module\Metadata\Application\Form\FormDefinitionCompiler;
use App\Module\Metadata\Domain\Repository\FormDefinitionRepository;
use App\Shared\Message\Message;

/**
 * Rewrites the forms naming a renamed object or field, inside the transaction of the
 * rename: each changed source is compiled again against the renamed definitions (the registry
 * reads them from the database while a transaction is open) and stored.
 */
final class FormRewrites
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly FormDefinitionCompiler $compiler,
    ) {
    }

    /**
     * @param callable(string): string $rewrite the source as written => the rewritten source
     *
     * @return list<Message> what was rewritten, one line each
     */
    public function apply(callable $rewrite): array
    {
        $rewritten = [];
        foreach ($this->forms->findAll() as $form) {
            $source = $rewrite($form->getSource());
            if ($source === $form->getSource()) {
                continue;
            }
            $compiled = $this->compiler->compile($source);
            $form->redefine($compiled['label'], $source, $compiled['root']);
            $this->forms->save($form);
            $rewritten[] = new Message('metadata.rewritten.form', 'form "%form%"', ['form' => $form->getName()]);
        }

        return $rewritten;
    }
}
