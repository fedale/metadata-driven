<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Tests\Support\CreatesPetObject;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Views stored with their object, through the metadata manager; each test is rolled back.
 */
final class ViewDefinitionTest extends KernelTestCase
{
    use CreatesPetObject;

    private MetadataManagerInterface $manager;
    private MetadataRegistryInterface $registry;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createPetObject($container);
        $this->manager = $container->get(MetadataManagerInterface::class);
        $this->registry = $container->get(MetadataRegistryInterface::class);
    }

    public function testStoresASanitizedLayout(): void
    {
        $this->manager->saveView('pet', ViewKind::Form, 'main', '<div class="row" onclick="steal()"><script>alert(1)</script>{name}</div><p style="color: red">{ species }</p><button>Go</button>');

        $view = $this->registry->getObject('pet')->view(ViewKind::Form);
        self::assertNotNull($view);
        self::assertSame('main', $view->name);
        // The first view of a kind is its default.
        self::assertTrue($view->isDefault);
        self::assertSame('<div class="row">{name}</div><p>{ species }</p>', $view->template);
    }

    public function testTheDefaultMovesAndCanBeLeftEmpty(): void
    {
        $this->manager->saveView('pet', ViewKind::Form, 'main', '{name}');
        $this->manager->saveView('pet', ViewKind::Form, 'compact', '{weight}');
        self::assertSame('main', $this->registry->getObject('pet')->view(ViewKind::Form)?->name);

        $this->manager->saveView('pet', ViewKind::Form, 'compact', '{weight} {age}', isDefault: true);
        $pet = $this->registry->getObject('pet');
        self::assertSame('compact', $pet->view(ViewKind::Form)?->name);
        self::assertSame('{weight} {age}', $pet->view(ViewKind::Form)->template);
        self::assertFalse($pet->view(ViewKind::Form, 'main')?->isDefault);
        self::assertSame(['compact', 'main'], array_map(static fn ($view): string => $view->name, $pet->views));

        // Without a default the form falls back to the plain layout; the named view stays.
        $this->manager->deleteView('pet', ViewKind::Form, 'compact');
        $pet = $this->registry->getObject('pet');
        self::assertNull($pet->view(ViewKind::Form));
        self::assertNotNull($pet->view(ViewKind::Form, 'main'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLayouts(): iterable
    {
        yield 'unknown field' => ['<div>{nickname}</div>', 'object "pet" has no field "nickname"'];
        yield 'field placed twice' => ['{name}<hr>{name}', 'the field "name" is placed more than once'];
        yield 'token in an attribute' => ['<div class="{name}">Name</div>', 'tokens go in the text'];
    }

    #[DataProvider('invalidLayouts')]
    public function testRejectsAnInvalidLayout(string $template, string $message): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage($message);

        $this->manager->saveView('pet', ViewKind::Form, 'main', $template);
    }

    public function testRejectsAnInvalidInput(): void
    {
        try {
            $this->manager->saveView('pet', ViewKind::Form, '', '');
            self::fail('A blank name and template must be refused.');
        } catch (MetadataInputInvalid $e) {
            self::assertSame(['name', 'template'], array_keys($e->errors));
        }

        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('Invalid name "Main view"');
        $this->manager->saveView('pet', ViewKind::Form, 'Main view', '{name}');
    }

    public function testRefusesToDeleteAnUnknownView(): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('Object "pet" has no form view "main".');

        $this->manager->deleteView('pet', ViewKind::Form, 'main');
    }
}
