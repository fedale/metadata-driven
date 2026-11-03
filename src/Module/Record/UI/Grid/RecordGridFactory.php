<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid;

use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\GridColumnDto;
use App\Module\Metadata\Contract\Dto\GridViewDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Application\RecordLabels;
use App\Module\Search\Contract\SearchConfigurationInterface;
use Fedale\GridviewBundle\Column\ActionButton;
use Fedale\GridviewBundle\Column\Config\AbstractColumnConfig;
use Fedale\GridviewBundle\Column\Config\ActionColumn;
use Fedale\GridviewBundle\Column\Config\BooleanColumn;
use Fedale\GridviewBundle\Column\Config\DateColumn;
use Fedale\GridviewBundle\Column\Config\DatetimeColumn;
use Fedale\GridviewBundle\Column\Config\EmailColumn;
use Fedale\GridviewBundle\Column\Config\NumberColumn;
use Fedale\GridviewBundle\Column\Config\SelectColumn;
use Fedale\GridviewBundle\Column\Config\TelColumn;
use Fedale\GridviewBundle\Column\Config\TextColumn;
use Fedale\GridviewBundle\Column\Config\UrlColumn;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Export\ExporterInterface;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Grid\GridviewBuilderFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Markup;

/**
 * Builds the grid of an object at runtime, fed by the record grid source: one column per field
 * (write-only ones, such as passwords, left out), with the filter and the rendering of its
 * field type; or the columns of a grid view, with their labels, cell renderers, virtual
 * columns, initial sort and page size.
 */
final class RecordGridFactory
{
    public const int DEFAULT_PAGE_SIZE = 25;

    public function __construct(
        private readonly GridviewBuilderFactory $builders,
        private readonly SearchModelInterface $searchModel,
        private readonly RecordGridSourceInterface $source,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly SearchConfigurationInterface $search,
        private readonly GridExporterRegistry $exporters,
        private readonly UrlGeneratorInterface $urls,
        private readonly GridRenderers $renderers,
    ) {
    }

    /**
     * The grid of a view other than the default one has an id of its own: its saved searches
     * and column preferences name columns the other grids may not have.
     */
    public static function gridId(ObjectDefinitionDto $object, ?ViewDefinitionDto $view = null): string
    {
        return 'record_'.$object->name.(null === $view || $view->isDefault ? '' : '__'.$view->name);
    }

    /**
     * @param ViewDefinitionDto|null $view a grid view; null for one column per field
     */
    public function create(ObjectDefinitionDto $object, ?ViewDefinitionDto $view = null): Gridview
    {
        $grid = $view?->grid;
        $dataProvider = [
            'dataSource' => $this->source,
            'model' => ['object' => $object->name],
            'identifier' => 'id',
            'pagination' => ['defaultPageSize' => $grid->pageSize ?? self::DEFAULT_PAGE_SIZE],
        ];
        if (null === $grid) {
            $columns = [];
            foreach ($object->fields as $field) {
                $type = $this->fieldTypes->get($field->type);
                // A many-to-many field has no column for the grid to read.
                if (!$field->storesColumn() || $type->isWriteOnly()) {
                    continue;
                }
                $columns[] = $this->column($field, $type->widget($field->settings))->label($field->label)->sortable();
            }
        } else {
            $columns = array_map(fn (GridColumnDto $column): AbstractColumnConfig => $this->viewColumn($object, $column), $grid->columns);
            // The source filters a virtual column on its fields; the sort map orders by them.
            $dataProvider['model']['virtual'] = array_column(array_map(
                static fn (GridColumnDto $column): array => [$column->key, $column->sources],
                array_filter($grid->columns, static fn (GridColumnDto $column): bool => $column->isVirtual()),
            ), 1, 0);
            $dataProvider['sort'] = ['map' => $this->sortMap($grid), 'default' => $grid->sort];
        }
        $columns[] = $this->actions($object);

        return $this->builders->createGridviewBuilder()
            ->setId(self::gridId($object, $view))
            // Must precede setColumns(): column filters are registered on the search model.
            ->setSearchModel($this->searchModel)
            ->setDataProvider($dataProvider)
            ->setColumns($columns)
            ->setOptions($this->options($object, $view))
            ->renderGridview();
    }

    /**
     * The grid options of an object. Also handed to the UI settings modal by
     * RecordGridDescriptors: one source, so its copy cannot drift from the real grid.
     *
     * @return array<string, mixed>
     */
    public function options(ObjectDefinitionDto $object, ?ViewDefinitionDto $view = null): array
    {
        // A view other than the default one has a page (and an export) of its own.
        $named = null !== $view && !$view->isDefault;

        return [
            'display' => [
                'title' => $object->labelPlural,
                'layout' => ['toolbar' => '{globalSearch} {filterSubmit} {spacer} {savedSearch} {columnVisibility} {export}'],
            ],
            // The fields of the search box: configured in the metadata, or every text-like field.
            'behavior' => ['globalSearch' => $this->search->fieldsFor($object)],
            'integration' => [
                'routeName' => $named ? 'record_grid' : 'record_index',
                'export' => [
                    'url' => $named
                        ? $this->urls->generate('record_grid_export', ['object' => $object->name, 'grid' => $view->name])
                        : $this->urls->generate('record_export', ['object' => $object->name]),
                    'formats' => array_map(
                        static fn (ExporterInterface $exporter): array => ['key' => $exporter->getKey(), 'label' => $exporter->getLabel()],
                        array_values($this->exporters->all()),
                    ),
                    'followsUi' => true,
                ],
            ],
        ];
    }

    /**
     * A column of a grid view: a field, with its type's column unless a renderer draws the
     * cells, or a virtual column joining its fields through the template.
     */
    private function viewColumn(ObjectDefinitionDto $object, GridColumnDto $column): AbstractColumnConfig
    {
        if (null === $column->field) {
            $fields = [];
            foreach ($column->sources as $source) {
                $field = $object->field($source);
                $fields[$source] = [$field, $this->fieldTypes->get($field->type)->widget($field->settings)];
            }
            $text = static function (array $row) use ($column, $fields): string {
                return trim((string) preg_replace_callback(
                    ViewDefinitionDto::TOKEN_PATTERN,
                    static fn (array $token): string => isset($fields[$token[1]]) ? self::text($fields[$token[1]][0], $fields[$token[1]][1], $row) : '',
                    (string) $column->template,
                ));
            };
            $config = TextColumn::new($column->key)->label($column->label ?? $column->key);
            if ($column->filter) {
                $config->filterText();
            }
        } else {
            $field = $object->field($column->field);
            $widget = $this->fieldTypes->get($field->type)->widget($field->settings);
            $text = static fn (array $row): string => self::text($field, $widget, $row);
            $config = $this->column($field, $widget)->label($column->label ?? $field->label);
            if (!$column->filter) {
                $config->filter(false);
            }
        }
        $config->sortable($column->sortable);
        if ($column->hidden) {
            $config->visible(false);
        }

        if (null !== $column->renderer) {
            $renderer = $this->renderers->get($column->renderer);
            $key = $column->field;
            $config->value(static fn (array $row): Markup => new Markup($renderer->render(null === $key ? null : ($row[$key] ?? null), $text($row), $column->options), 'UTF-8'));
        } elseif (null === $column->field) {
            $config->value($text);
        }

        return $config;
    }

    /**
     * The sort map of a grid view: a field sorts on itself, a virtual column on its fields; a
     * system column of the initial sort on itself.
     *
     * @return array<string, string|array{asc: list<string>, desc: list<string>}>
     */
    private function sortMap(GridViewDto $grid): array
    {
        $map = [];
        foreach ($grid->columns as $column) {
            if ($column->sortable) {
                $map[$column->key] = $column->isVirtual() ? ['asc' => $column->sources, 'desc' => $column->sources] : $column->key;
            }
        }
        foreach (array_keys($grid->sort) as $key) {
            $map[$key] ??= $key;
        }

        return $map;
    }

    /**
     * A value as the grid shows it: the label of a choice or of a related record.
     *
     * @param array<mixed> $row
     */
    private static function text(FieldDefinitionDto $field, Widget $widget, array $row): string
    {
        $value = $row[$field->name] ?? null;

        return match (true) {
            null === $value => '',
            WidgetKind::Relation === $widget->kind => \is_string($label = $row[$field->name.RecordGridSourceInterface::LABEL_SUFFIX] ?? null) ? $label : (\is_string($value) ? RecordLabels::fallback($value) : ''),
            WidgetKind::Choice === $widget->kind && \is_scalar($value) => $widget->choices()[(string) $value] ?? (string) $value,
            \is_bool($value) => $value ? '✓' : '✗',
            $value instanceof \DateTimeInterface => $value->format(WidgetKind::DateTime === $widget->kind ? 'Y-m-d H:i' : 'Y-m-d'),
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private function column(FieldDefinitionDto $field, Widget $widget): AbstractColumnConfig
    {
        $name = $field->name;

        return match ($widget->kind) {
            WidgetKind::Text, WidgetKind::Textarea => TextColumn::new($name)->filterText(),
            WidgetKind::Email => EmailColumn::new($name)->filterText(),
            WidgetKind::Url => UrlColumn::new($name)->filterText(),
            WidgetKind::Tel => TelColumn::new($name)->filterText(),
            WidgetKind::Password => throw new \LogicException(\sprintf('Field "%s" is write-only: it has no grid column.', $name)),
            WidgetKind::ManyToMany => throw new \LogicException(\sprintf('Field "%s" is many-to-many: it has no grid column yet.', $name)),
            WidgetKind::Integer => NumberColumn::new($name)->decimals(0)->filterNumber(),
            WidgetKind::Decimal => NumberColumn::new($name)
                ->decimals(\is_int($widget->options['scale'] ?? null) ? $widget->options['scale'] : 2)
                ->filterNumber(),
            WidgetKind::Checkbox => BooleanColumn::new($name)->filterBoolean(),
            WidgetKind::Date => DateColumn::new($name)->filterDate(),
            WidgetKind::DateTime => DatetimeColumn::new($name)->filterDate(),
            // Shows the option label, filters and sorts on the stored value.
            WidgetKind::Choice => SelectColumn::new($name)->choices(array_flip($widget->choices())),
            // Shows the label of the related record; filters on its id, sorts on its label.
            WidgetKind::Relation => TextColumn::new($name)
                ->value(static function (array $row) use ($name): string {
                    $label = $row[$name.RecordGridSourceInterface::LABEL_SUFFIX] ?? null;
                    $id = $row[$name] ?? null;

                    return \is_string($label) ? $label : (\is_string($id) ? RecordLabels::fallback($id) : '');
                })
                ->filterRelation(
                    ajaxUrl: $this->urls->generate('record_options', ['object' => $widget->options['target'] ?? '']),
                    // Several records at once.
                    multiple: true,
                    searchable: true,
                    optionLabel: 'label',
                    optionValue: 'id',
                ),
        };
    }

    private function actions(ObjectDefinitionDto $object): ActionColumn
    {
        $edit = new ActionButton(function (array $row) use ($object): string {
            $id = $row['id'] ?? null;
            if (!\is_string($id)) {
                return '';
            }
            $url = $this->urls->generate('record_edit', ['object' => $object->name, 'id' => $id]);

            return \sprintf('<a href="%s" data-turbo-frame="_top">Edit</a>', htmlspecialchars($url, \ENT_QUOTES));
        });

        return ActionColumn::new()->label(false)->layout('{edit}')->buttons(['edit' => $edit]);
    }
}
