<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a "pet" object with one field of every type, through the real handlers
 * (metadata and physical table).
 */
trait CreatesPetObject
{
    private static function createPetObject(ContainerInterface $container): void
    {
        $createObject = $container->get(CreateObjectHandler::class);
        $createField = $container->get(CreateFieldHandler::class);

        $createObject(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        $createField(new CreateFieldCommand('pet', 'name', 'text', 'Name', isRequired: true, settings: ['maxLength' => 20]));
        $createField(new CreateFieldCommand('pet', 'species', 'select', 'Species', settings: [
            'options' => [['value' => 'dog', 'label' => 'Dog'], ['value' => 'cat', 'label' => 'Cat']],
        ]));
        $createField(new CreateFieldCommand('pet', 'weight', 'number', 'Weight', settings: ['decimals' => 2]));
        $createField(new CreateFieldCommand('pet', 'age', 'number', 'Age'));
        $createField(new CreateFieldCommand('pet', 'vaccinated', 'boolean', 'Vaccinated'));
        $createField(new CreateFieldCommand('pet', 'born_on', 'date', 'Born on'));
        $createField(new CreateFieldCommand('pet', 'chip_code', 'text', 'Chip code', isUnique: true));
        $createField(new CreateFieldCommand('pet', 'owner_email', 'email', 'Owner email'));
    }
}
