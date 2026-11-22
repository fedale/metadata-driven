<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Admin\UI\FieldTypeTexts;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Module\Schema\Contract\Dto\AdoptionProposal;
use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Dto\JunctionMapping;
use App\Module\Schema\Contract\Exception\AdoptionRefused;
use App\Module\Schema\Contract\TableAdopterInterface;
use App\Shared\Workspace\CurrentWorkspace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tables loaded into the data schema by hand, which no object owns yet: what they hold,
 * whether they can become an object, and adopting one, with the tables it is linked to by
 * foreign keys if the admin wants them: the field each column becomes, tried first (a dry run),
 * then for real.
 */
#[Route('/admin/tables', name: 'admin_table_')]
final class AdminTableController extends AbstractController
{
    public function __construct(
        private readonly DatabaseTablesInterface $tables,
        private readonly AccessCheckerInterface $access,
        private readonly CurrentWorkspace $workspace,
        private readonly TableAdopterInterface $adopter,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly FieldTypeTexts $fieldTypeTexts,
    ) {
    }

    /**
     * "with[]" names the other tables adopted with it; without it, the tables it points at
     * (and those they point at) come along, so that its foreign keys become relations.
     */
    #[Route('/{table}/adopt', name: 'adopt', requirements: ['table' => '[^/]+'], methods: ['GET', 'POST'])]
    public function adopt(string $table, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $with = $request->query->has('with')
            ? array_values(array_filter($request->query->all('with'), static fn (mixed $name): bool => \is_string($name) && '' !== $name && $name !== $table))
            : $this->pointedAt($table);
        try {
            $proposals = $this->adopter->proposeSet([$table, ...$with]);
        } catch (AdoptionRefused) {
            // Gone, or not adoptable: its page says why.
            return $this->redirectToRoute('admin_table_show', ['table' => $table], Response::HTTP_SEE_OTHER);
        }
        $set = array_map(static fn (AdoptionProposal $proposal): string => $proposal->table->name, $proposals);
        $related = $this->adopter->related($set);
        $form = $this->form($proposals)->handleRequest($request);

        $reports = [];
        $reasons = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $adopt = $form->get('adopt');
            $dryRun = !($adopt instanceof SubmitButton && $adopt->isClicked());
            try {
                $reports = $this->adopter->adoptSet($this->requests($proposals, $form), $dryRun);
                if (!$dryRun) {
                    $names = array_map(static fn ($report): string => $report->objectName, $reports);
                    $this->addFlash('success', 1 === \count($reports)
                        ? $this->translator->trans('admin.tables.adopted', ['%table%' => $table, '%name%' => $names[0]])
                        : $this->translator->trans('admin.tables.adopted_many', ['%names%' => implode(', ', $names)]));

                    return 1 === \count($reports)
                        ? $this->redirectToRoute('admin_object_show', ['object' => $names[0]], Response::HTTP_SEE_OTHER)
                        : $this->redirectToRoute('admin_index', [], Response::HTTP_SEE_OTHER);
                }
            } catch (AdoptionRefused $e) {
                $reasons = $e->reasons;
            }
        }

        return $this->render('admin/table_adopt.html.twig', [
            'table' => $table,
            'proposals' => $proposals,
            'set' => $set,
            'related' => $related,
            'form' => $form,
            'reports' => $reports,
            'reasons' => $reasons,
            'data_schema' => $this->workspace->dataSchema(),
        ], new Response(null, [] !== $reasons ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{table}', name: 'show', requirements: ['table' => '[^/]+'], methods: ['GET'])]
    public function show(string $table): Response
    {
        $this->denyUnlessAdministrator();
        $found = $this->tables->find($table) ?? throw $this->createNotFoundException(\sprintf('The data schema has no table "%s" that no object owns.', $table));

        return $this->render('admin/table_show.html.twig', ['table' => $found, 'data_schema' => $this->workspace->dataSchema()]);
    }

    /**
     * The tables a table points at, and those they point at, that can be adopted.
     *
     * @return list<string>
     */
    private function pointedAt(string $table): array
    {
        $set = [$table];
        do {
            $more = $this->adopter->related($set)['pointedAt'];
            $set = [...$set, ...$more];
        } while ([] !== $more);

        return \array_slice($set, 1);
    }

    /**
     * The form of the adoption: for each table (by its position, "t0", "t1"...), the object's
     * name and labels, and per column (by its position too: a column's name is no form name)
     * the field type, its name and its label.
     *
     * @param list<AdoptionProposal> $proposals
     *
     * @return FormInterface<mixed>
     */
    private function form(array $proposals): FormInterface
    {
        $builder = $this->forms->createNamedBuilder('adopt');
        foreach ($proposals as $t => $proposal) {
            $builder->add($this->tableForm('t'.$t, $proposal));
        }

        return $builder
            ->add('try', SubmitType::class, ['label' => 'admin.tables.try'])
            ->add('adopt', SubmitType::class, ['label' => 'admin.tables.adopt'])
            ->getForm();
    }

    /**
     * @return FormBuilderInterface<mixed>
     */
    private function tableForm(string $key, AdoptionProposal $proposal): FormBuilderInterface
    {
        $columns = $this->forms->createNamedBuilder('columns', FormType::class, null, ['label' => false]);
        foreach (array_values($proposal->columns) as $c => $column) {
            $mapping = $column->mapping() ?? $column->options[array_key_first($column->options) ?? ''] ?? null;
            $types = [];
            foreach ($column->options as $type => $option) {
                $label = $this->fieldTypeTexts->label($this->fieldTypes->get($type));
                // A relation names its target: the object a table of the set becomes, or one there is.
                $target = $option->targetTable ?? $option->settings['target'] ?? null;
                $types['relation' === $type && \is_string($target) ? \sprintf('%s → %s', $label, $target) : $label] = $type;
            }
            $columns->add($this->forms->createNamedBuilder('c'.$c, FormType::class, null, ['label' => false])
                ->add('type', ChoiceType::class, [
                    'label' => 'admin.tables.field_type',
                    'choices' => [$this->translator->trans('admin.tables.left_out') => ''] + $types,
                    'choice_translation_domain' => false,
                    'data' => $column->proposed ?? '',
                    'required' => false,
                ])
                ->add('field', TextType::class, ['label' => 'admin.machine_name', 'data' => $mapping->fieldName ?? '', 'required' => false])
                ->add('label', TextType::class, ['label' => 'admin.tables.label', 'data' => $mapping->label ?? '', 'required' => false]));
        }

        $builder = $this->forms->createNamedBuilder($key, FormType::class, null, ['label' => false]);
        $junction = $proposal->junction;
        if (null !== $junction) {
            $modes = ['admin.tables.as_object' => 'object'];
            if (!$junction->asObject) {
                // Columns of its own would be lost as a link table: only a junction object then.
                $modes = ['admin.tables.as_links' => 'links'] + $modes;
            }
            if (!$proposal->table->junction) {
                // A key of its own: an object anyway, with the many-to-many field or without.
                $modes += ['admin.tables.no_field' => 'none'];
            }
            $builder->add($this->forms->createNamedBuilder('junction', FormType::class, null, ['label' => false])
                ->add('mode', ChoiceType::class, ['label' => 'admin.tables.junction_mode', 'choices' => $modes, 'data' => $junction->asObject ? 'object' : 'links', 'expanded' => true])
                ->add('field', TextType::class, ['label' => 'admin.machine_name', 'data' => $junction->fieldName])
                ->add('label', TextType::class, ['label' => 'admin.tables.label', 'data' => $junction->label]));
        }

        return $builder
            ->add('name', TextType::class, ['label' => 'admin.machine_name', 'data' => $proposal->objectName])
            ->add('labelSingular', TextType::class, ['label' => 'admin.object.singular_label', 'data' => $proposal->labelSingular])
            ->add('labelPlural', TextType::class, ['label' => 'admin.object.plural_label', 'data' => $proposal->labelPlural])
            ->add($columns);
    }

    /**
     * @param list<AdoptionProposal> $proposals
     * @param FormInterface<mixed>   $form
     *
     * @return list<AdoptionRequest>
     */
    private function requests(array $proposals, FormInterface $form): array
    {
        $requests = [];
        foreach ($proposals as $t => $proposal) {
            $table = $form->get('t'.$t);
            $columns = [];
            foreach (array_values($proposal->columns) as $c => $column) {
                $row = $table->get('columns')->get('c'.$c);
                $option = $column->options[self::text($row->get('type')->getData())] ?? null;
                $columns[$column->column->name] = $option?->withNames(self::text($row->get('field')->getData()), self::text($row->get('label')->getData()));
            }
            $junction = null;
            if (null !== $proposal->junction && 'none' !== $table->get('junction')->get('mode')->getData()) {
                $chosen = $table->get('junction');
                $junction = new JunctionMapping(
                    $proposal->junction->sourceColumn,
                    $proposal->junction->targetColumn,
                    self::text($chosen->get('field')->getData()),
                    self::text($chosen->get('label')->getData()),
                    'object' === $chosen->get('mode')->getData(),
                );
            }
            $requests[] = new AdoptionRequest(
                $proposal->table->name,
                self::text($table->get('name')->getData()),
                self::text($table->get('labelSingular')->getData()),
                self::text($table->get('labelPlural')->getData()),
                $columns,
                $junction,
            );
        }

        return $requests;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }

    private function denyUnlessAdministrator(): void
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the objects.');
        }
    }
}
