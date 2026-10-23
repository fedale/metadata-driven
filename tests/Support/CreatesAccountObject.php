<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * An "account" object using the richer field types and settings: a confirmed password,
 * a switch checked by default, a choice shown as buttons, a pattern, a link, a phone.
 */
trait CreatesAccountObject
{
    private static function createAccountObject(ContainerInterface $container): void
    {
        $createObject = $container->get(CreateObjectHandler::class);
        $createField = $container->get(CreateFieldHandler::class);

        $createObject(new CreateObjectCommand('account', 'Account', 'Accounts'));
        $createField(new CreateFieldCommand('account', 'username', 'text', 'Username', isRequired: true, isUnique: true));
        $createField(new CreateFieldCommand('account', 'password', 'password', 'Password', isRequired: true, settings: [
            'minLength' => 8, 'requireDigit' => true, 'confirm' => true,
        ]));
        $createField(new CreateFieldCommand('account', 'active', 'boolean', 'Active', settings: ['default' => true, 'display' => 'switch']));
        $createField(new CreateFieldCommand('account', 'gender', 'select', 'Gender', settings: [
            'options' => [['value' => 'm', 'label' => 'Male'], ['value' => 'f', 'label' => 'Female'], ['value' => 'x', 'label' => 'Non-binary']],
            'display' => 'radio',
        ]));
        $createField(new CreateFieldCommand('account', 'zipcode', 'text', 'Postal code', settings: [
            'maxLength' => 5, 'pattern' => '\d{5}', 'patternMessage' => 'A postal code has five digits.',
        ]));
        $createField(new CreateFieldCommand('account', 'website', 'url', 'Website'));
        $createField(new CreateFieldCommand('account', 'phone', 'tel', 'Phone'));
    }
}
