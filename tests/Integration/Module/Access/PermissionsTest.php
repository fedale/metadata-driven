<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Access;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PermissionsTest extends KernelTestCase
{
    public function testCreatingAnObjectCreatesItsPermissions(): void
    {
        self::getContainer()->get(CreateObjectHandler::class)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        $items = self::getContainer()->get(ItemStorageInterface::class);

        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $item = $items->getItem('record.pet.'.$action);
            self::assertNotNull($item, $action);
            self::assertTrue($item->isPermission());
        }
    }

    public function testSyncCreatesTheMissingItemsOnly(): void
    {
        self::getContainer()->get(CreateObjectHandler::class)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        $synchronizer = self::getContainer()->get(PermissionSynchronizer::class);

        self::assertSame(['admin.metadata', 'admin.members', 'admin.roles', 'ROLE_SUPER_ADMIN'], $synchronizer->syncAll());
        self::assertSame([], $synchronizer->syncAll());

        $command = new CommandTester((new Application(self::bootKernel()))->find('access:sync'));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('Every permission already exists.', $command->getDisplay());
    }
}
