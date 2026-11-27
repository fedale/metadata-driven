<?php

declare(strict_types=1);

namespace App\Module\Workflow\Domain\Exception;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * A definition that cannot be activated: every problem, located by its path in the definition
 * ("steps.0.branches.1.steps.0.values.note"; "" for the definition as a whole).
 */
final class InvalidWorkflowDefinition extends \DomainException
{
    /**
     * @param array<string, list<Message>> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('The workflow is invalid: '.(string) new MessageList($this->located(), '; '));
    }

    /**
     * Each error after its path: "steps.0.type: One of …". For a page, translated (see
     * MessageTranslator), or for a refusal that lists them (a package's).
     *
     * @return list<Message>
     */
    public function located(): array
    {
        $located = [];
        foreach ($this->errors as $path => $messages) {
            foreach ($messages as $message) {
                $located[] = '' === $path ? $message : new Message('workflow.definition.at', '%path%: %message%', ['path' => $path, 'message' => $message]);
            }
        }

        return $located;
    }
}
