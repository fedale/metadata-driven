<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A "contact" object for search tests: people whose name and city are separate fields.
 */
trait CreatesContactObject
{
    private static function createContacts(ContainerInterface $container): void
    {
        $createObject = $container->get(CreateObjectHandler::class);
        $createField = $container->get(CreateFieldHandler::class);
        $records = $container->get(RecordManagerInterface::class);

        $createObject(new CreateObjectCommand('contact', 'Contact', 'Contacts'));
        $createField(new CreateFieldCommand('contact', 'first_name', 'text', 'First name'));
        $createField(new CreateFieldCommand('contact', 'last_name', 'text', 'Last name'));
        $createField(new CreateFieldCommand('contact', 'city', 'text', 'City'));
        $createField(new CreateFieldCommand('contact', 'age', 'number', 'Age'));

        foreach ([
            ['John', 'Smith', 'London', 40],
            ['John', 'Doe', 'Paris', 30],
            ['Mary', 'Johnson', 'Milan', 25],
            ['Anna', 'Rossi', 'New York', 35],
        ] as [$firstName, $lastName, $city, $age]) {
            $records->create(new RecordInput('contact', ['first_name' => $firstName, 'last_name' => $lastName, 'city' => $city, 'age' => $age]));
        }
    }
}
