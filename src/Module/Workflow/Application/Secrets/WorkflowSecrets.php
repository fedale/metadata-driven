<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Secrets;

/**
 * The current workspace's secrets: their names for anyone who manages the workflows, their
 * values for the HTTP requests of a run only, never shown again once written.
 */
interface WorkflowSecrets
{
    public const string NAME = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @return list<array{name: string, updated_by: ?string, updated_at: string}> by name
     */
    public function all(): array;

    /**
     * @return list<string>
     */
    public function names(): array;

    /**
     * Writes a secret, a new one or over the one of that name.
     *
     * @throws \InvalidArgumentException a name that will not do, an empty value
     * @throws SecretsKeyMissing
     */
    public function set(string $name, string $value, ?string $by): void;

    public function remove(string $name): bool;

    /**
     * Every secret's value, decrypted: for a run's HTTP requests.
     *
     * @return array<string, string> name => value
     *
     * @throws SecretsKeyMissing
     */
    public function values(): array;
}
