<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Repository;

/**
 * Forgets the entities loaded so far, after an install or an upgrade was rolled back.
 *
 * The ORM keeps the entities a transaction changed, changed, after the database rolled them
 * back: read again, an object relabelled by a dry run would still show its new label. Dropping
 * them makes the next read come from the database.
 */
interface PersistenceReset
{
    public function reset(): void;
}
