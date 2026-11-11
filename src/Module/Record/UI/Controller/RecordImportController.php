<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\Import\ImportReport;
use App\Module\Record\Application\Import\RecordImporter;
use App\Module\Record\Domain\Exception\ImportRefused;
use App\Module\Record\UI\Import\ImportColumns;
use App\Module\Record\UI\Import\ImportDraft;
use App\Module\Record\UI\Import\ImportDrafts;
use App\Shared\Csv\CsvFile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The CSV import wizard of an object: upload a file, say which column fills which field, check
 * what the import would do, import. Every step is a page of its own; the file waits in a draft
 * (ImportDrafts) between them. The import itself is RecordImporter's, all or nothing, with the
 * rules of the forms, as record:import runs it.
 *
 * Whoever may create the records may import them; with a key, which may update records, the
 * update permission is needed too.
 */
#[Route('/o/{object}/import', name: 'record_import', requirements: ['object' => '[a-z][a-z0-9_]*'])]
final class RecordImportController extends AbstractController
{
    private const string DETECT = 'detect';

    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly AccessCheckerInterface $access,
        private readonly ImportDrafts $drafts,
        private readonly ImportColumns $columns,
        private readonly RecordImporter $importer,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.import.max_rows%')]
        private readonly int $maxRows,
    ) {
    }

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function upload(string $object, Request $request): Response
    {
        $definition = $this->objectFor($object, RecordAction::Create);
        $form = $this->uploadForm()->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $separator = $form->get('separator')->getData();
            if ($file instanceof UploadedFile) {
                $draft = $this->drafts->create($definition->name, $file, self::DETECT === $separator || !\is_string($separator)
                    ? CsvFile::detectSeparator($file->getPathname())
                    : $separator);
                $rows = $this->readOrRefuse($draft, $form->get('file'));
                if (null !== $rows) {
                    $draft->mapping = $this->columns->propose($definition, self::headers($rows));
                    $this->drafts->save($draft);

                    return $this->redirectToRoute('record_import_columns', ['object' => $definition->name, 'draft' => $draft->id], Response::HTTP_SEE_OTHER);
                }
            }
        }

        return $this->render('record/import/upload.html.twig', [
            'object' => $definition,
            'form' => $form,
            'max_rows' => $this->maxRows,
        ]);
    }

    #[Route('/{draft}', name: '_columns', requirements: ['draft' => Requirement::UUID_V4], methods: ['GET', 'POST'])]
    public function columns(string $object, string $draft, Request $request): Response
    {
        $definition = $this->objectFor($object, RecordAction::Create);
        $importDraft = $this->draftOr404($definition, $draft);
        $rows = $this->read($importDraft);
        $headers = self::headers($rows);
        $form = $this->columnsForm($definition, $importDraft, $headers)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->applyColumns($definition, $importDraft, $headers, $form)) {
            $this->drafts->save($importDraft);

            return $this->redirectToRoute('record_import_check', ['object' => $definition->name, 'draft' => $importDraft->id], Response::HTTP_SEE_OTHER);
        }

        $relations = [];
        foreach ($this->columns->fields($definition) as $name => $field) {
            if (null !== $field->target()) {
                $relations[] = $name;
            }
        }

        return $this->render('record/import/columns.html.twig', [
            'object' => $definition,
            'draft' => $importDraft,
            'form' => $form,
            'headers' => $headers,
            // The first values of each column, to tell what it holds.
            'samples' => array_slice($rows, 0, 3),
            'rows' => \count($rows),
            'relations' => $relations,
            'cancel_form' => $this->buttonForm('cancel', $definition, $importDraft)->createView(),
        ]);
    }

    /**
     * The import as a dry run: what it would create and update, or the rows it refuses.
     */
    #[Route('/{draft}/check', name: '_check', requirements: ['draft' => Requirement::UUID_V4], methods: ['GET'])]
    public function check(string $object, string $draft): Response
    {
        $definition = $this->objectFor($object, RecordAction::Create);
        $importDraft = $this->draftOr404($definition, $draft);
        if ([] === array_filter($importDraft->mapping)) {
            return $this->redirectToRoute('record_import_columns', ['object' => $definition->name, 'draft' => $importDraft->id], Response::HTTP_SEE_OTHER);
        }

        $report = null;
        $reasons = [];
        try {
            $report = $this->run($importDraft, dryRun: true);
            $importDraft->checked = $importDraft->fingerprint();
            $this->drafts->save($importDraft);
        } catch (ImportRefused $e) {
            $reasons = $e->reasons;
        }

        return $this->checkPage($definition, $importDraft, $report, $reasons);
    }

    #[Route('/{draft}/run', name: '_run', requirements: ['draft' => Requirement::UUID_V4], methods: ['POST'])]
    public function import(string $object, string $draft, Request $request): Response
    {
        $definition = $this->objectFor($object, RecordAction::Create);
        $importDraft = $this->draftOr404($definition, $draft);
        $form = $this->buttonForm('run', $definition, $importDraft)->handleRequest($request);
        // Only what the user saw checked: a draft changed since (another tab) is checked again.
        if (!$form->isSubmitted() || !$form->isValid() || !$importDraft->isChecked()) {
            return $this->redirectToRoute('record_import_check', ['object' => $definition->name, 'draft' => $importDraft->id], Response::HTTP_SEE_OTHER);
        }
        $this->denyUpdatesWithoutPermission($definition, $importDraft);

        try {
            $report = $this->run($importDraft, dryRun: false);
        } catch (ImportRefused $e) {
            // The records changed since the check: the reasons, and nothing written.
            $importDraft->checked = null;
            $this->drafts->save($importDraft);

            return $this->checkPage($definition, $importDraft, null, $e->reasons);
        }
        $this->drafts->delete($importDraft);
        $this->addFlash('success', $this->translator->trans('import.done', [
            '%created%' => $report->created,
            '%updated%' => $report->updated,
        ]));

        return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{draft}/cancel', name: '_cancel', requirements: ['draft' => Requirement::UUID_V4], methods: ['POST'])]
    public function cancel(string $object, string $draft, Request $request): Response
    {
        $definition = $this->objectFor($object, RecordAction::Create);
        $importDraft = $this->draftOr404($definition, $draft);
        $form = $this->buttonForm('cancel', $definition, $importDraft)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->drafts->delete($importDraft);
        }

        return $this->redirectToRoute('record_index', ['object' => $definition->name], Response::HTTP_SEE_OTHER);
    }

    /**
     * Reads the columns' choices into the draft; a refusal is shown on its input.
     *
     * @param list<string>         $headers
     * @param FormInterface<mixed> $form
     */
    private function applyColumns(ObjectDefinitionDto $object, ImportDraft $draft, array $headers, FormInterface $form): bool
    {
        $mapping = [];
        $seen = [];
        $valid = true;
        foreach ($headers as $index => $header) {
            $field = $form->get('c'.$index)->getData();
            $field = \is_string($field) && '' !== $field ? $field : null;
            if (null !== $field && isset($seen[$field])) {
                $form->get('c'.$index)->addError(new FormError($this->translator->trans('import.field_twice', ['%column%' => $seen[$field]])));
                $valid = false;
            }
            $mapping[$header] = $field;
            if (null !== $field) {
                $seen[$field] ??= $header;
            }
        }
        if ([] === $seen) {
            $form->addError(new FormError($this->translator->trans('import.nothing_mapped')));
            $valid = false;
        }

        $key = $form->get('key')->getData();
        $key = \is_string($key) && '' !== $key ? $key : null;
        if (null !== $key && !isset($seen[$key])) {
            $form->get('key')->addError(new FormError($this->translator->trans('import.key_not_mapped')));
            $valid = false;
        }
        if (null !== $key && !$this->access->canRecords($object->name, RecordAction::Update)) {
            $form->get('key')->addError(new FormError($this->translator->trans('import.key_needs_update')));
            $valid = false;
        }

        $matches = [];
        foreach ($this->columns->fields($object) as $name => $field) {
            if (null !== $field->target()) {
                $match = $form->get('m_'.$name)->getData();
                if (\is_string($match) && '' !== $match) {
                    $matches[$name] = $match;
                }
            }
        }
        if (!$valid) {
            return false;
        }
        $draft->mapping = $mapping;
        $draft->key = $key;
        $draft->matches = $matches;

        return true;
    }

    private function run(ImportDraft $draft, bool $dryRun): ImportReport
    {
        // Only the relations the file fills: a match on a field left out means nothing.
        $matches = array_intersect_key($draft->matches, array_flip(array_filter($draft->mapping)));

        return $this->importer->importRows($draft->object, ImportColumns::rename($this->read($draft), $draft->mapping), $draft->key, $matches, $dryRun);
    }

    /**
     * @param list<string> $reasons
     */
    private function checkPage(ObjectDefinitionDto $object, ImportDraft $draft, ?ImportReport $report, array $reasons): Response
    {
        $mapped = array_filter($draft->mapping);

        return $this->render('record/import/check.html.twig', [
            'object' => $object,
            'draft' => $draft,
            'report' => $report,
            'reasons' => $reasons,
            'mapped' => $mapped,
            'preview' => array_slice(ImportColumns::rename($this->read($draft), $draft->mapping), 0, 5),
            'run_form' => $this->buttonForm('run', $object, $draft)->createView(),
            'cancel_form' => $this->buttonForm('cancel', $object, $draft)->createView(),
        ], new Response(status: [] === $reasons ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function uploadForm(): FormInterface
    {
        return $this->forms->createNamedBuilder('import', FormType::class, ['separator' => self::DETECT])
            ->add('file', FileType::class, [
                'label' => 'import.file',
                'help' => 'import.file_help',
                'help_translation_parameters' => ['%rows%' => $this->maxRows],
                'attr' => ['accept' => '.csv,text/csv'],
            ])
            ->add('separator', ChoiceType::class, [
                'label' => 'import.separator',
                'choices' => ['import.separator_detect' => self::DETECT, 'import.separator_comma' => CsvFile::COMMA, 'import.separator_semicolon' => CsvFile::SEMICOLON],
            ])
            ->getForm();
    }

    /**
     * One select per column of the file, the key, and what each relation's cells hold.
     *
     * @param list<string> $headers
     *
     * @return FormInterface<mixed>
     */
    private function columnsForm(ObjectDefinitionDto $object, ImportDraft $draft, array $headers): FormInterface
    {
        $fields = $this->columns->fields($object);
        $choices = [];
        foreach ($fields as $name => $field) {
            $choices[$field->label] = $name;
        }
        $data = ['key' => $draft->key];
        foreach ($headers as $index => $header) {
            $data['c'.$index] = $draft->mapping[$header] ?? null;
        }
        $builder = $this->forms->createNamedBuilder('columns', FormType::class, $data + array_combine(
            array_map(static fn (string $name): string => 'm_'.$name, array_keys($draft->matches)),
            array_values($draft->matches),
        ));
        foreach ($headers as $index => $header) {
            $builder->add('c'.$index, ChoiceType::class, [
                // The header is the file's text, the field labels the metadata's, already in the
                // user's language: nothing for the form theme to translate.
                'translation_domain' => false,
                'label' => '' === $header ? $this->translator->trans('import.column_unnamed', ['%index%' => $index + 1]) : $header,
                'choices' => $choices,
                'required' => false,
                'placeholder' => $this->translator->trans('import.ignore'),
            ]);
        }

        $keys = [];
        foreach ($fields as $name => $field) {
            if ($field->isUnique) {
                $keys[$field->label] = $name;
            }
        }
        $builder->add('key', ChoiceType::class, [
            'label' => 'import.key',
            'help' => 'import.key_help',
            'choice_translation_domain' => false,
            'choices' => $keys,
            'required' => false,
            'placeholder' => 'import.no_key',
        ]);

        foreach ($fields as $name => $field) {
            if (null === $field->target()) {
                continue;
            }
            $targets = [];
            foreach ($this->columns->matchableFields($field) as $targetName => $targetField) {
                $targets[$this->translator->trans('import.match_by', ['%field%' => $targetField->label])] = $targetName;
            }
            $builder->add('m_'.$name, ChoiceType::class, [
                'label' => $field->label,
                'choice_translation_domain' => false,
                'choices' => $targets,
                'required' => false,
                'placeholder' => 'import.match_ids',
            ]);
        }

        return $builder->getForm();
    }

    /**
     * A button posting to a step (import, cancel), protected by its CSRF token.
     *
     * @return FormInterface<mixed>
     */
    private function buttonForm(string $name, ObjectDefinitionDto $object, ImportDraft $draft): FormInterface
    {
        return $this->forms->createNamedBuilder($name, FormType::class, null, [
            'action' => $this->generateUrl('record_import_'.$name, ['object' => $object->name, 'draft' => $draft->id]),
        ])->getForm();
    }

    /**
     * Reads the draft's file at upload; a file it cannot take is refused on the input, and its
     * draft deleted.
     *
     * @param FormInterface<mixed> $input
     *
     * @return list<array{line: int, values: array<string, string|null>}>|null
     */
    private function readOrRefuse(ImportDraft $draft, FormInterface $input): ?array
    {
        try {
            $rows = CsvFile::read($this->drafts->path($draft), $draft->separator);
        } catch (\UnexpectedValueException $e) {
            $this->drafts->delete($draft);
            $input->addError(new FormError($e->getMessage()));

            return null;
        }
        $error = match (true) {
            [] === $rows => $this->translator->trans('import.no_rows'),
            \count($rows) > $this->maxRows => $this->translator->trans('import.too_many_rows', ['%rows%' => \count($rows), '%max%' => $this->maxRows]),
            default => null,
        };
        if (null !== $error) {
            $this->drafts->delete($draft);
            $input->addError(new FormError($error));

            return null;
        }

        return $rows;
    }

    /**
     * @return list<array{line: int, values: array<string, string|null>}>
     */
    private function read(ImportDraft $draft): array
    {
        // Read once at upload already: a failure now is a file changed under the draft.
        return CsvFile::read($this->drafts->path($draft), $draft->separator);
    }

    /**
     * @param list<array{line: int, values: array<string, string|null>}> $rows
     *
     * @return list<string>
     */
    private static function headers(array $rows): array
    {
        return array_map(strval(...), array_keys($rows[0]['values'] ?? []));
    }

    private function denyUpdatesWithoutPermission(ObjectDefinitionDto $object, ImportDraft $draft): void
    {
        if (null !== $draft->key && !$this->access->canRecords($object->name, RecordAction::Update)) {
            throw $this->createAccessDeniedException(\sprintf('No permission to update the records of "%s": import without a key.', $object->name));
        }
    }

    private function draftOr404(ObjectDefinitionDto $object, string $id): ImportDraft
    {
        return $this->drafts->get($object->name, $id)
            ?? throw $this->createNotFoundException(\sprintf('No import "%s" of "%s" in this session: upload the file again.', $id, $object->name));
    }

    private function objectFor(string $name, RecordAction $action): ObjectDefinitionDto
    {
        try {
            $object = $this->metadata->getObject($name);
        } catch (ObjectDefinitionNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        if (!$this->access->canRecords($object->name, $action)) {
            throw $this->createAccessDeniedException(\sprintf('No permission to %s the records of "%s".', $action->value, $object->name));
        }

        return $object;
    }
}
