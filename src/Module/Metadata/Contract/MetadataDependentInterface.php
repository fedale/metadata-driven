<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

/**
 * Port implemented by a module whose own definitions name objects and fields (the workflows),
 * so that Metadata treats them as it treats its forms and views: archiving refused while they
 * use the object or the field, a rename carried into them in the same transaction.
 *
 * Implementations are tagged "app.metadata_dependent" automatically (config/services.yaml).
 */
interface MetadataDependentInterface
{
    /**
     * @return list<string> one line per definition using it, naming the place for the admin to fix
     */
    public function usesOfObject(string $objectName): array;

    /**
     * @return list<string> one line per definition using it, naming the place for the admin to fix
     */
    public function usesOfField(string $objectName, string $fieldName): array;

    /**
     * Called inside the rename's transaction, once the object is renamed.
     *
     * @return list<string> the definitions rewritten, one line each
     */
    public function objectRenamed(string $oldName, string $newName): array;

    /**
     * Called inside the rename's transaction, once the field is renamed.
     *
     * @return list<string> the definitions rewritten, one line each
     */
    public function fieldRenamed(string $objectName, string $oldName, string $newName): array;
}
