<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract\Exception;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * A workflow a package brings that the app refuses: its name, or its definition's structure.
 */
final class InvalidWorkflowSource extends \DomainException
{
    /**
     * @param array<string, list<Message>> $errors by path in the definition ("steps.0.type"), "name"
     *                                             for the name
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct((string) new MessageList($this->located(), ' '));
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
