<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Objects linked by every kind of delete rule:
 *
 *  - person ← pet.owner      (set_null, inverse "pets")
 *  - pet    ← visit.pet      (cascade)
 *  - person ← invoice.customer (restrict)
 *  - person ← person.manager (cascade, self-reference)
 */
trait CreatesRelatedObjects
{
    private static function createRelatedObjects(ContainerInterface $container): void
    {
        $object = $container->get(CreateObjectHandler::class);
        $field = $container->get(CreateFieldHandler::class);

        $object(new CreateObjectCommand('person', 'Person', 'People'));
        $field(new CreateFieldCommand('person', 'full_name', 'text', 'Full name', isRequired: true));
        $field(new CreateFieldCommand('person', 'city', 'text', 'City'));
        $field(new CreateFieldCommand('person', 'manager', 'relation', 'Manager', settings: ['target' => 'person', 'onDelete' => 'cascade', 'inverseName' => 'reports']));

        $object(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        $field(new CreateFieldCommand('pet', 'name', 'text', 'Name', isRequired: true));
        $field(new CreateFieldCommand('pet', 'owner', 'relation', 'Owner', settings: ['target' => 'person', 'inverseName' => 'pets', 'inverseLabel' => 'Pets']));

        $object(new CreateObjectCommand('visit', 'Visit', 'Visits'));
        $field(new CreateFieldCommand('visit', 'notes', 'text', 'Notes'));
        $field(new CreateFieldCommand('visit', 'pet', 'relation', 'Pet', isRequired: true, settings: ['target' => 'pet', 'onDelete' => 'cascade']));

        $object(new CreateObjectCommand('invoice', 'Invoice', 'Invoices'));
        $field(new CreateFieldCommand('invoice', 'number', 'text', 'Number'));
        $field(new CreateFieldCommand('invoice', 'customer', 'relation', 'Customer', settings: ['target' => 'person', 'onDelete' => 'restrict']));
    }
}
