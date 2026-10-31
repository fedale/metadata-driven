<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Tests\Support\CreatesAccountObject;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\CreatesRegistrationForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Grid views: compiled when saved, checked against the object, read back from the metadata.
 */
final class GridViewTest extends KernelTestCase
{
    use CreatesAccountObject;
    use CreatesPetObject;
    use CreatesRegistrationForm;

    protected function setUp(): void
    {
        self::createPetObject(self::getContainer());
    }

    public function testCompilesTheDefinition(): void
    {
        $source = <<<'YAML'
            # Kept as written, comments included.
            columns:
              - name
              - { field: species, label: Kind, renderer: badge, options: { tones: { dog: success } } }
              - { name: tag, template: '{name} #{chip_code}', sortable: false }
              - { field: weight, hidden: true, filter: false }
            sort: { name: asc, created_at: desc }
            page_size: 50
            YAML;
        $this->manager()->saveView('pet', ViewKind::Grid, 'main', $source);

        $view = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('pet')->view(ViewKind::Grid);
        self::assertNotNull($view);
        self::assertSame($source, $view->template);
        $grid = $view->grid;
        self::assertNotNull($grid);
        self::assertSame(['name', 'species', 'tag', 'weight'], array_map(static fn ($column): string => $column->key, $grid->columns));
        self::assertSame(['name' => 'asc', 'created_at' => 'desc'], $grid->sort);
        self::assertSame(50, $grid->pageSize);

        $species = $grid->column('species');
        self::assertNotNull($species);
        self::assertSame(['species', 'Kind', 'badge', ['tones' => ['dog' => 'success']]], [$species->field, $species->label, $species->renderer, $species->options]);
        $tag = $grid->column('tag');
        self::assertTrue($tag?->isVirtual());
        self::assertSame(['name', 'chip_code'], $tag->sources);
        self::assertFalse($tag->sortable);
        self::assertTrue($grid->column('weight')?->hidden);
        self::assertFalse($grid->column('weight')->filter);
    }

    public function testReportsEveryErrorOfTheDefinition(): void
    {
        self::createAccountObject(self::getContainer());
        $errors = $this->errorsOf('account', <<<'YAML'
            columns:
              - nickname
              - password
              - { field: website, renderer: sparkle }
              - { field: website }
              - { field: username, renderer: truncate, options: { length: 0, width: 3 } }
              - { name: website, template: '{username}' }
              - { name: full, template: 'no tokens here' }
              - { name: owner, template: '{password}' }
              - { field: username, name: x, template: '{phone}' }
              - { field: active, hidden: yes please }
            sort: { website: sideways, nickname: asc }
            page_size: 0
            colour: blue
            YAML);

        self::assertSame([
            '(grid): unknown key "colour" (allowed: columns, sort, page_size).',
            'Column #1: object "account" has no field "nickname".',
            'Column #2: "password" is write-only: it cannot be shown.',
            'Column #3: "renderer" must be one of badge, date, truncate.',
            'Column "website" is listed twice.',
            'Column #5: unknown option "width" of renderer truncate (allowed: length).',
            'Column #5: "length" must be an integer from 1 to 1000.',
            'Column "website": "website" is a field of "account": a virtual column needs a name of its own.',
            'Column "full": the template places no {field}.',
            'Column "owner": "password" is write-only: it cannot be shown.',
            'Column #9: a field column takes "field"; a virtual column takes "name" and "template", not both.',
            'Column #10: "hidden" must be true or false.',
            '"sort": the direction of "website" must be asc or desc.',
            '"sort": "nickname" is no sortable column of the grid (nor created_at, updated_at).',
            '"page_size" must be an integer from 1 to 500.',
        ], $errors);
    }

    public function testAVirtualColumnLeavesRelationsOutAndTheYamlIsChecked(): void
    {
        self::createRegistrationForm(self::getContainer());

        self::assertSame(['Column "line": {profile} is a relation: show it in a column of its own.'], $this->errorsOf('phone', "columns:\n  - { name: line, template: '{number} {profile}' }"));
        self::assertSame(['"columns" must be a non-empty list.'], $this->errorsOf('pet', 'columns: []'));
        self::assertStringStartsWith('Invalid YAML: ', $this->errorsOf('pet', 'columns: [name')[0]);
    }

    /**
     * @return list<string>
     */
    private function errorsOf(string $object, string $source): array
    {
        try {
            $this->manager()->saveView($object, ViewKind::Grid, 'main', $source);
        } catch (MetadataInputInvalid $e) {
            return $e->errors['template'];
        }
        self::fail('The definition is invalid.');
    }

    private function manager(): MetadataManagerInterface
    {
        return self::getContainer()->get(MetadataManagerInterface::class);
    }
}
