<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Admin\UI\FieldTypeTexts;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Module\Schema\Contract\Dto\AdoptionProposal;
use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Exception\AdoptionRefused;
use App\Module\Schema\Contract\TableAdopterInterface;
use App\Shared\Workspace\CurrentWorkspace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tables loaded into the data schema by hand, which no object owns yet: what they hold,
 * whether they can become an object, and adopting one: the field each column becomes, tried
 * first (a dry run), then for real.
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

    #[Route('/{table}/adopt', name: 'adopt', requirements: ['table' => '[^/]+'], methods: ['GET', 'POST'])]
    public function adopt(string $table, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        try {
            $proposal = $this->adopter->propose($table);
        } catch (AdoptionRefused) {
            // Gone, or not adoptable: its page says why.
            return $this->redirectToRoute('admin_table_show', ['table' => $table], Response::HTTP_SEE_OTHER);
        }
        $keys = self::columnKeys($proposal);
        $form = $this->form($proposal, $keys)->handleRequest($request);

        $report = null;
        $reasons = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $adopt = $form->get('adopt');
            $dryRun = !($adopt instanceof SubmitButton && $adopt->isClicked());
            try {
                $report = $this->adopter->adopt($this->request($proposal, $keys, $form), $dryRun);
                if (!$dryRun) {
                    $this->addFlash('success', $this->translator->trans('admin.tables.adopted', ['%table%' => $table, '%name%' => $report->objectName]));

                    return $this->redirectToRoute('admin_object_show', ['object' => $report->objectName], Response::HTTP_SEE_OTHER);
                }
            } catch (AdoptionRefused $e) {
                $reasons = $e->reasons;
            }
        }

        return $this->render('admin/table_adopt.html.twig', [
            'proposal' => $proposal,
            'keys' => $keys,
            'form' => $form,
            'report' => $report,
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
     * The form of the adoption: the object's name and labels, and per column (by its key, a
     * column's name being no form name) the field type, its name and its label.
     *
     * @param array<string, string> $keys form key => column name
     *
     * @return FormInterface<mixed>
     */
    private function form(AdoptionProposal $proposal, array $keys): FormInterface
    {
        $columns = $this->forms->createNamedBuilder('columns', FormType::class, null, ['label' => false]);
        foreach ($keys as $key => $name) {
            $column = $proposal->columns[$name];
            $mapping = $column->mapping() ?? $column->options[array_key_first($column->options) ?? ''] ?? null;
            $types = [];
            foreach (array_keys($column->options) as $type) {
                $types[$this->fieldTypeTexts->label($this->fieldTypes->get($type))] = $type;
            }
            $columns->add($this->forms->createNamedBuilder($key, FormType::class, null, ['label' => false])
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

        return $this->forms->createNamedBuilder('adopt')
            ->add('name', TextType::class, ['label' => 'admin.machine_name', 'data' => $proposal->objectName])
            ->add('labelSingular', TextType::class, ['label' => 'admin.object.singular_label', 'data' => $proposal->labelSingular])
            ->add('labelPlural', TextType::class, ['label' => 'admin.object.plural_label', 'data' => $proposal->labelPlural])
            ->add($columns)
            ->add('try', SubmitType::class, ['label' => 'admin.tables.try'])
            ->add('adopt', SubmitType::class, ['label' => 'admin.tables.adopt'])
            ->getForm();
    }

    /**
     * @param array<string, string> $keys
     * @param FormInterface<mixed>  $form
     */
    private function request(AdoptionProposal $proposal, array $keys, FormInterface $form): AdoptionRequest
    {
        $columns = [];
        foreach ($keys as $key => $name) {
            $row = $form->get('columns')->get($key);
            $type = self::text($row->get('type')->getData());
            $option = $proposal->columns[$name]->options[$type] ?? null;
            $columns[$name] = null === $option ? null : $option->withNames(self::text($row->get('field')->getData()), self::text($row->get('label')->getData()));
        }

        return new AdoptionRequest(
            $proposal->table->name,
            self::text($form->get('name')->getData()),
            self::text($form->get('labelSingular')->getData()),
            self::text($form->get('labelPlural')->getData()),
            $columns,
        );
    }

    /**
     * @return array<string, string> "c0", "c1"... => column name, in the order of the table
     */
    private static function columnKeys(AdoptionProposal $proposal): array
    {
        $keys = [];
        foreach (array_keys($proposal->columns) as $i => $name) {
            $keys['c'.$i] = (string) $name;
        }

        return $keys;
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
