<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contacts linked to any number of tags (at most 3):
 *
 *  - contact.tags → tag (many-to-many, unlink, inverse "contacts")
 */
trait CreatesTaggedContacts
{
    private static function createTaggedContacts(ContainerInterface $container): void
    {
        $object = $container->get(CreateObjectHandler::class);
        $field = $container->get(CreateFieldHandler::class);

        $object(new CreateObjectCommand('tag', 'Tag', 'Tags'));
        $field(new CreateFieldCommand('tag', 'name', 'text', 'Name', isRequired: true));

        $object(new CreateObjectCommand('contact', 'Contact', 'Contacts'));
        $field(new CreateFieldCommand('contact', 'full_name', 'text', 'Full name', isRequired: true));
        $field(new CreateFieldCommand('contact', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag', 'inverseName' => 'contacts', 'inverseLabel' => 'Contacts', 'max' => 3]));
    }
}
