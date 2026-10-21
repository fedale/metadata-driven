<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid;

use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
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
use Fedale\GridviewBundle\Column\Config\TextColumn;
use Fedale\GridviewBundle\Contract\SearchModelInterface;
use Fedale\GridviewBundle\Export\ExporterInterface;
use Fedale\GridviewBundle\Export\GridExporterRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use Fedale\GridviewBundle\Grid\GridviewBuilderFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the grid of an object at runtime: one column per field, with the filter and
 * the rendering of its field type, fed by the record grid source.
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
    ) {
    }

    public static function gridId(ObjectDefinitionDto $object): string
    {
        return 'record_'.$object->name;
    }

    public function create(ObjectDefinitionDto $object): Gridview
    {
        $columns = [];
        foreach ($object->fields as $field) {
            $widget = $this->fieldTypes->get($field->type)->widget($field->settings);
            $columns[] = $this->column($field, $widget)->label($field->label)->sortable();
        }
        $columns[] = $this->actions($object);

        return $this->builders->createGridviewBuilder()
            ->setId(self::gridId($object))
            // Must precede setColumns(): column filters are registered on the search model.
            ->setSearchModel($this->searchModel)
            ->setDataProvider([
                'dataSource' => $this->source,
                'model' => ['object' => $object->name],
                'identifier' => 'id',
                'pagination' => ['defaultPageSize' => self::DEFAULT_PAGE_SIZE],
            ])
            ->setColumns($columns)
            ->setOptions($this->options($object))
            ->renderGridview();
    }

    /**
     * The grid options of an object. Also handed to the UI settings modal by
     * RecordGridDescriptors: one source, so its copy cannot drift from the real grid.
     *
     * @return array<string, mixed>
     */
    public function options(ObjectDefinitionDto $object): array
    {
        return [
            'display' => [
                'title' => $object->labelPlural,
                'layout' => ['toolbar' => '{globalSearch} {filterSubmit} {spacer} {savedSearch} {export}'],
            ],
            // The fields of the search box: configured in the metadata, or every text-like field.
            'behavior' => ['globalSearch' => $this->search->fieldsFor($object)],
            'integration' => [
                'routeName' => 'record_index',
                'export' => [
                    'url' => $this->urls->generate('record_export', ['object' => $object->name]),
                    'formats' => array_map(
                        static fn (ExporterInterface $exporter): array => ['key' => $exporter->getKey(), 'label' => $exporter->getLabel()],
                        array_values($this->exporters->all()),
                    ),
                    'followsUi' => true,
                ],
            ],
        ];
    }

    private function column(FieldDefinitionDto $field, Widget $widget): AbstractColumnConfig
    {
        $name = $field->name;

        return match ($widget->kind) {
            WidgetKind::Text, WidgetKind::Textarea => TextColumn::new($name)->filterText(),
            WidgetKind::Email => EmailColumn::new($name)->filterText(),
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
                ->filter(['type' => 'relation', 'options' => [
                    'ajax_url' => $this->urls->generate('record_options', ['object' => $widget->options['target'] ?? '']),
                    'option_label' => 'label',
                    'option_value' => 'id',
                    'searchable' => true,
                    // Several records at once. Also avoids a bundle bug: a single-select AJAX
                    // filter moves its <select>, which reconnects its controller in a loop.
                    'multiple' => true,
                ]]),
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
