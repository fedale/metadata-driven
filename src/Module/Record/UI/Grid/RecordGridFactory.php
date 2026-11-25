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
use App\Module\Record\Contract\Dto\RecordActionLink;
use App\Module\Search\Contract\SearchConfigurationInterface;
use Fedale\GridviewBundle\Column\ActionButton;
use Fedale\GridviewBundle\Column\Config\AbstractColumnConfig;
use Fedale\GridviewBundle\Column\Config\ActionColumn;
use Fedale\GridviewBundle\Column\Config\BooleanColumn;
use Fedale\GridviewBundle\Column\Config\CheckboxColumn;
use Fedale\GridviewBundle\Column\Config\ColorColumn;
use Fedale\GridviewBundle\Column\Config\DateColumn;
use Fedale\GridviewBundle\Column\Config\DatetimeColumn;
use Fedale\GridviewBundle\Column\Config\EmailColumn;
use Fedale\GridviewBundle\Column\Config\NumberColumn;
use Fedale\GridviewBundle\Column\Config\SelectColumn;
use Fedale\GridviewBundle\Column\Config\TelColumn;
use Fedale\GridviewBundle\Column\Config\TextColumn;
use Fedale\GridviewBundle\Column\Config\TimeColumn;
use Fedale\GridviewBundle\Column\Config\UrlColumn;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Export\ExporterInterface;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Fedale\GridviewBundle\Filter\FilterType;
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
    /** The most labels in use the filter of a tags field lists; past them it takes a text. */
    public const int MAX_FILTER_LABELS = 200;

    public function __construct(
        private readonly GridviewBuilderFactory $builders,
        private readonly SearchModelInterface $searchModel,
        private readonly RecordGridSourceInterface $source,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly SearchConfigurationInterface $search,
        private readonly GridExporterRegistry $exporters,
        private readonly UrlGeneratorInterface $urls,
        private readonly GridRenderers $renderers,
        private readonly FileCell $fileCell,
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
     * @param ViewDefinitionDto|null $view             a grid view; null for one column per field
     * @param list<RecordActionLink> $selectionActions what the user may do on a selection (other
     *                                                 modules' actions): the selection column and
     *                                                 the bulk bar, a button each
     */
    public function create(ObjectDefinitionDto $object, ?ViewDefinitionDto $view = null, array $selectionActions = []): Gridview
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
                if ($type->isWriteOnly()) {
                    continue;
                }
                $column = $this->column($object, $field, $type->widget($field->settings))->label($field->label);
                // A file column holds an id, a many-to-many one a set of records: there is no
                // order to sort them by (the bundle's columns are sortable unless told otherwise).
                $columns[] = $column->sortable($type->isSortable());
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
        if ([] !== $selectionActions) {
            array_unshift($columns, CheckboxColumn::new());
        }

        return $this->builders->createGridviewBuilder()
            ->setId(self::gridId($object, $view))
            // Must precede setColumns(): column filters are registered on the search model.
            ->setSearchModel($this->searchModel)
            ->setDataProvider($dataProvider)
            ->setColumns($columns)
            ->setOptions($this->options($object, $view, $selectionActions))
            ->renderGridview();
    }

    /**
     * The grid options of an object. Also handed to the UI settings modal by
     * RecordGridDescriptors: one source, so its copy cannot drift from the real grid.
     *
     * @param list<RecordActionLink> $selectionActions see create()
     *
     * @return array<string, mixed>
     */
    public function options(ObjectDefinitionDto $object, ?ViewDefinitionDto $view = null, array $selectionActions = []): array
    {
        // A view other than the default one has a page (and an export) of its own.
        $named = null !== $view && !$view->isDefault;
        $layout = ['toolbar' => '{globalSearch} {filterSubmit} {spacer} {savedSearch} {columnVisibility} {export}'];
        $crud = [];
        if ([] !== $selectionActions) {
            // The bulk bar shows once a row is selected; its buttons open the bundle's modal on
            // their url, the selection appended (gridview-selection#bulk).
            $layout['shell'] = '{restrictionNotice} {header} {bulkBar} {dataview} {footer}';
            $crud = ['crud' => ['bulkActions' => array_combine(
                array_map(static fn (int $i): string => 'action_'.$i, array_keys($selectionActions)),
                array_map(static fn (RecordActionLink $action): array => ['url' => $action->url, 'label' => $action->label, 'variant' => ''], $selectionActions),
            )]];
        }

        return [
            'display' => [
                'title' => $object->labelPlural,
                'layout' => $layout,
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
                ...$crud,
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
            $config = $this->column($object, $field, $widget)->label($column->label ?? $field->label);
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
            WidgetKind::Relation === $widget->kind && $widget->isMultiple(), WidgetKind::Media === $widget->kind => self::linkedLabels($field->name, $row),
            null === $value => '',
            WidgetKind::Relation === $widget->kind => \is_string($label = $row[$field->name.RecordGridSourceInterface::LABEL_SUFFIX] ?? null) ? $label : (\is_string($value) ? RecordLabels::fallback($value) : ''),
            WidgetKind::Choice === $widget->kind && \is_scalar($value) => $widget->choices()[(string) $value] ?? (string) $value,
            WidgetKind::Tags === $widget->kind => self::tags($value),
            \is_bool($value) => $value ? '✓' : '✗',
            $value instanceof \DateTimeInterface => $value->format(match ($widget->kind) {
                WidgetKind::DateTime => 'Y-m-d H:i',
                WidgetKind::Time => true === ($widget->options['seconds'] ?? false) ? 'H:i:s' : 'H:i',
                default => 'Y-m-d',
            }),
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * The labels of the records a many-to-many field links to, or the names of the files a file
     * field holds, joined by the source.
     *
     * @param array<mixed> $row
     */
    private static function linkedLabels(string $name, array $row): string
    {
        $labels = $row[$name.RecordGridSourceInterface::LABEL_SUFFIX] ?? null;

        return \is_string($labels) ? $labels : '';
    }

    /**
     * The labels of a tags field, joined as the export writes them.
     */
    private static function tags(mixed $value): string
    {
        return \is_array($value) ? implode(', ', array_filter($value, \is_string(...))) : '';
    }

    /**
     * The labels of a tags field as badges. Between them a text separator, hidden on screen: a
     * screen reader and the export read "Urgent, client x".
     */
    private static function tagBadges(mixed $value): Markup
    {
        $badges = array_map(
            static fn (string $label): string => '<span class="gv-badge">'.htmlspecialchars($label, \ENT_QUOTES).'</span>',
            \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [],
        );

        return new Markup([] === $badges ? '' : '<span class="md-tags-cell">'.implode('<span class="md-tags-cell__separator">, </span>', $badges).'</span>', 'UTF-8');
    }

    /**
     * The filter of a tags field: its labels to pick, any of them. They are the suggestions of
     * the field, or else the labels its records hold; past MAX_FILTER_LABELS of those, a text
     * the labels are matched against.
     */
    private function tagsFilter(ObjectDefinitionDto $object, FieldDefinitionDto $field, Widget $widget, AbstractColumnConfig $column): AbstractColumnConfig
    {
        $labels = \is_array($widget->options['suggestions'] ?? null) ? array_values(array_filter($widget->options['suggestions'], \is_string(...))) : [];
        if ([] === $labels) {
            $labels = $this->source->labelsInUse($object, $field->name, self::MAX_FILTER_LABELS + 1);
            if (\count($labels) > self::MAX_FILTER_LABELS) {
                return $column->filterText();
            }
        }

        return $column->filterRelation(choices: array_combine($labels, $labels), multiple: true, searchable: true);
    }

    private function column(ObjectDefinitionDto $object, FieldDefinitionDto $field, Widget $widget): AbstractColumnConfig
    {
        $name = $field->name;

        return match ($widget->kind) {
            WidgetKind::Text, WidgetKind::Textarea => TextColumn::new($name)->filterText(),
            WidgetKind::Email => EmailColumn::new($name)->filterText(),
            WidgetKind::Url => UrlColumn::new($name)->filterText(),
            WidgetKind::Tel => TelColumn::new($name)->filterText(),
            WidgetKind::Password => throw new \LogicException(\sprintf('Field "%s" is write-only: it has no grid column.', $name)),
            WidgetKind::Integer => NumberColumn::new($name)->decimals(0)->filterNumber(),
            WidgetKind::Number => NumberColumn::new($name)
                ->decimals(\is_int($widget->options['scale'] ?? null) ? $widget->options['scale'] : 2)
                ->filterNumber(),
            WidgetKind::Boolean => BooleanColumn::new($name)->filterBoolean(),
            WidgetKind::Date => DateColumn::new($name)->filterDate(),
            WidgetKind::DateTime => DatetimeColumn::new($name)->filterDate(),
            WidgetKind::Time => TimeColumn::new($name)->pattern(true === ($widget->options['seconds'] ?? false) ? 'H:i:s' : 'H:i')->filterTime(),
            WidgetKind::Color => ColorColumn::new($name)->filterText(),
            // Shows the option label, filters and sorts on the stored value.
            WidgetKind::Choice => SelectColumn::new($name)->choices(array_flip($widget->choices())),
            // A many-to-many link shows the labels of the linked records and filters on their ids
            // (any of them), no sort.
            WidgetKind::Relation => $widget->isMultiple() ? $this->filterRecords(
                TextColumn::new($name)->value(static fn (array $row): string => self::linkedLabels($name, $row)),
                $widget,
            ) : $this->filterRecords(
                // One record: shows its label, filters on its id, sorts on its label.
                TextColumn::new($name)->value(static function (array $row) use ($name): string {
                    $label = $row[$name.RecordGridSourceInterface::LABEL_SUFFIX] ?? null;
                    $id = $row[$name] ?? null;

                    return \is_string($label) ? $label : (\is_string($id) ? RecordLabels::fallback($id) : '');
                }),
                $widget,
            ),
            // A link to each file, with a thumbnail for an image; filters on whether there is one.
            WidgetKind::Media => TextColumn::new($name)
                ->value(fn (array $row): Markup => new Markup($this->fileCell->render($name, $row), 'UTF-8'))
                ->filter(FilterType::Boolean),
            // A badge per label; filters on the labels held (any of those picked), no sort (a set).
            WidgetKind::Tags => $this->tagsFilter($object, $field, $widget, TextColumn::new($name)->value(static fn (array $row): Markup => self::tagBadges($row[$name] ?? null))),
        };
    }

    /**
     * The relation filter of a column pointing at the records of the widget's target, looked
     * up as the user types; several records at once.
     */
    private function filterRecords(AbstractColumnConfig $column, Widget $widget): AbstractColumnConfig
    {
        $target = $widget->options['target'] ?? '';

        return $column->filterRelation(
            ajaxUrl: $this->urls->generate('record_options', ['object' => \is_string($target) ? $target : '']),
            multiple: true,
            searchable: true,
            optionLabel: 'label',
            optionValue: 'id',
        );
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
