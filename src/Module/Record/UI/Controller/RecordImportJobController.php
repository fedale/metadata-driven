<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\Import\BackgroundImports;
use App\Module\Record\Domain\Model\ImportJob;
use App\Module\Record\Domain\Repository\ImportJobRepository;
use App\Module\Record\UI\Import\ImportDrafts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The background imports of an object (BackgroundImports): the list of the user's, the page of
 * one, refreshed while the worker has it, and its buttons: Import once checked, Stop while it
 * waits or runs, Change the columns once checked or refused.
 *
 * A job is its creator's: anyone else gets a 404 (a reason may quote the file's values).
 */
#[Route('/o/{object}/imports', name: 'record_import_job', requirements: ['object' => '[a-z][a-z0-9_]*'])]
final class RecordImportJobController extends AbstractController
{
    /** How many of the user's imports the list shows. */
    private const int LIST_LIMIT = 20;

    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly AccessCheckerInterface $access,
        private readonly ImportJobRepository $jobs,
        private readonly BackgroundImports $imports,
        private readonly ImportDrafts $drafts,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 's', methods: ['GET'])]
    public function list(string $object): Response
    {
        $definition = $this->objectFor($object);

        return $this->render('record/import/jobs.html.twig', [
            'object' => $definition,
            'jobs' => $this->jobs->latest($this->user(), $definition->name, self::LIST_LIMIT),
        ]);
    }

    #[Route('/{job}', name: '', requirements: ['job' => Requirement::UUID_V4], methods: ['GET'])]
    public function show(string $object, string $job): Response
    {
        $definition = $this->objectFor($object);
        $importJob = $this->jobOr404($definition, $job);

        return $this->render('record/import/job.html.twig', [
            'object' => $definition,
            'job' => $importJob,
            'has_file' => $this->imports->hasFile($importJob),
            'run_form' => $this->buttonForm('run', $definition, $importJob)->createView(),
            'stop_form' => $this->buttonForm('stop', $definition, $importJob)->createView(),
            'columns_form' => $this->buttonForm('columns', $definition, $importJob)->createView(),
        ]);
    }

    #[Route('/{job}/run', name: '_run', requirements: ['job' => Requirement::UUID_V4], methods: ['POST'])]
    public function run(string $object, string $job, Request $request): Response
    {
        $definition = $this->objectFor($object);
        $importJob = $this->jobOr404($definition, $job);
        if ($this->isValid('run', $definition, $importJob, $request)) {
            if (null !== $importJob->key && !$this->access->canRecords($definition->name, RecordAction::Update)) {
                throw $this->createAccessDeniedException(\sprintf('No permission to update the records of "%s": import without a key.', $definition->name));
            }
            if (!$this->imports->queueImport($importJob)) {
                $this->addFlash('error', $this->translator->trans('import.job.not_importable'));
            }
        }

        return $this->toJob($definition, $importJob);
    }

    #[Route('/{job}/stop', name: '_stop', requirements: ['job' => Requirement::UUID_V4], methods: ['POST'])]
    public function stop(string $object, string $job, Request $request): Response
    {
        $definition = $this->objectFor($object);
        $importJob = $this->jobOr404($definition, $job);
        if ($this->isValid('stop', $definition, $importJob, $request)) {
            $this->imports->stop($importJob);
        }

        return $this->toJob($definition, $importJob);
    }

    /**
     * Back to the wizard's columns step, with the job's file and choices; the job is deleted.
     */
    #[Route('/{job}/columns', name: '_columns', requirements: ['job' => Requirement::UUID_V4], methods: ['POST'])]
    public function columns(string $object, string $job, Request $request): Response
    {
        $definition = $this->objectFor($object);
        $importJob = $this->jobOr404($definition, $job);
        if (!$this->isValid('columns', $definition, $importJob, $request)) {
            return $this->toJob($definition, $importJob);
        }
        $draft = $this->drafts->adopt($definition->name, $importJob->originalName, $importJob->separator, $importJob->totalRows, $importJob->mapping, $importJob->key, $importJob->matches);
        if (!$this->imports->release($importJob, $this->drafts->path($draft))) {
            $this->drafts->forget($draft);
            $this->addFlash('error', $this->translator->trans('import.job.not_importable'));

            return $this->toJob($definition, $importJob);
        }

        return $this->redirectToRoute('record_import_columns', ['object' => $definition->name, 'draft' => $draft->id], Response::HTTP_SEE_OTHER);
    }

    private function isValid(string $name, ObjectDefinitionDto $object, ImportJob $job, Request $request): bool
    {
        $form = $this->buttonForm($name, $object, $job)->handleRequest($request);

        return $form->isSubmitted() && $form->isValid();
    }

    /**
     * A button posting to an action of the job, protected by its CSRF token.
     *
     * @return FormInterface<mixed>
     */
    private function buttonForm(string $name, ObjectDefinitionDto $object, ImportJob $job): FormInterface
    {
        return $this->forms->createNamedBuilder($name, FormType::class, null, [
            'action' => $this->generateUrl('record_import_job_'.$name, ['object' => $object->name, 'job' => $job->id]),
        ])->getForm();
    }

    private function toJob(ObjectDefinitionDto $object, ImportJob $job): Response
    {
        return $this->redirectToRoute('record_import_job', ['object' => $object->name, 'job' => $job->id], Response::HTTP_SEE_OTHER);
    }

    private function jobOr404(ObjectDefinitionDto $object, string $id): ImportJob
    {
        $job = $this->jobs->get($id);
        if (null === $job || $job->object !== $object->name || $job->createdBy !== mb_strtolower($this->user())) {
            throw $this->createNotFoundException(\sprintf('No import "%s" of "%s" of yours.', $id, $object->name));
        }

        return $job;
    }

    private function objectFor(string $name): ObjectDefinitionDto
    {
        try {
            $object = $this->metadata->getObject($name);
        } catch (ObjectDefinitionNotFound $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        if (!$this->access->canRecords($object->name, RecordAction::Create)) {
            throw $this->createAccessDeniedException(\sprintf('No permission to create the records of "%s".', $object->name));
        }

        return $object;
    }

    private function user(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? throw $this->createAccessDeniedException();
    }
}
