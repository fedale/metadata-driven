<?php

declare(strict_types=1);

namespace App\Module\Package\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Application\Command\ExportPackageCommand;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Source\UploadedPackages;
use App\Module\Package\Contract\Exception\PackageException;
use App\Module\Package\Domain\Exception\InstallRefused;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Module\Package\UI\Form\PackageUploadForm;
use App\Module\Workflow\Contract\Dto\WorkflowSource;
use App\Module\Workflow\Contract\PackagedWorkflowsInterface;
use App\Shared\Message\MessageTranslator;
use Fedale\PackageFormat\Archive;
use Fedale\PackageFormat\PackageDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Packages as files, from the admin: exporting some objects (their records too, if asked), forms
 * and workflows as a package archive to download, and installing one uploaded, after a trial
 * install as for a package of an index.
 *
 * An upload is kept under a token (UploadedPackages) between the trial and the confirmation:
 * the pages name the token, never a path.
 */
#[Route('/admin/packages', name: 'admin_package_')]
final class PackageFileController extends AbstractController
{
    private const string TOKEN = '[a-f0-9]{32}';

    public function __construct(
        private readonly PackageExporter $exporter,
        private readonly PackageDirectory $directories,
        private readonly Archive $archive,
        private readonly UploadedPackages $uploads,
        private readonly PackageInstaller $installer,
        private readonly MetadataRegistryInterface $metadata,
        private readonly PackagedWorkflowsInterface $workflows,
        private readonly FormFactoryInterface $forms,
        private readonly AccessCheckerInterface $access,
        private readonly TranslatorInterface $translator,
        private readonly MessageTranslator $messageTranslator,
        private readonly PackageUploadForm $uploadForm,
    ) {
    }

    #[Route('/export', name: 'export', methods: ['GET', 'POST'])]
    public function export(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->exportForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $seeds = [];
            foreach (self::names($form, 'seeds') as $object) {
                $seeds[$object] = null;
            }
            $directory = sys_get_temp_dir().'/md-export-'.bin2hex(random_bytes(8));
            try {
                $package = $this->exporter->export(new ExportPackageCommand(
                    self::text($form, 'name'),
                    self::text($form, 'version'),
                    self::names($form, 'objects'),
                    self::names($form, 'forms'),
                    '' === self::text($form, 'label') ? null : self::text($form, 'label'),
                    '' === self::text($form, 'description') ? null : self::text($form, 'description'),
                    '' === self::text($form, 'license') ? 'proprietary' : self::text($form, 'license'),
                    $seeds,
                    self::names($form, 'workflows'),
                ));
                $this->directories->write($package, $directory);
                $archive = $directory.'.tar.gz';
                $this->archive->pack($directory, $archive);
            } catch (PackageException|MetadataException $e) {
                $form->addError(new FormError($this->messageTranslator->trans($e->text())));

                return $this->render('admin/package_export.html.twig', ['form' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            } finally {
                (new Filesystem())->remove($directory);
            }

            $response = new BinaryFileResponse($archive);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, \sprintf('%s-%s.tar.gz', str_replace('/', '-', $package->name()), $package->version()));
            $response->headers->set('Content-Type', 'application/gzip');

            return $response->deleteFileAfterSend();
        }

        return $this->render('admin/package_export.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->uploadForm->create()->handleRequest($request);
        $file = $form->isSubmitted() && $form->isValid() ? $form->get('archive')->getData() : null;
        if (!$file instanceof UploadedFile) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }
            $this->addFlash('error', [] === $errors ? $this->translator->trans('admin.package_admin.upload_missing') : implode(' ', $errors));

            return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
        }
        try {
            $token = $this->uploads->store($file->getPathname(), $file->getClientOriginalName());
        } catch (PackageSourceFailed $e) {
            $this->addFlash('error', $this->messageTranslator->trans($e->text()));

            return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_package_uploaded', ['token' => $token], Response::HTTP_SEE_OTHER);
    }

    /**
     * The trial install of an upload (GET), then the install (its confirmation, POST).
     */
    #[Route('/uploads/{token}', name: 'uploaded', requirements: ['token' => self::TOKEN], methods: ['GET', 'POST'])]
    public function uploaded(string $token, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $opened = $this->uploads->open($token) ?? throw $this->createNotFoundException('No such upload: it was installed, given up, or removed a day later.');
        $confirm = $this->forms->createNamedBuilder('install')->getForm()->handleRequest($request);
        $real = $confirm->isSubmitted() && $confirm->isValid();

        $reports = [];
        $reasons = [];
        try {
            $reports = $this->installer->installAll([$opened], dryRun: !$real);
        } catch (InstallRefused $e) {
            $reasons = $e->reasons;
        }
        if ($real && [] !== $reports) {
            $this->uploads->discard($token);
            $this->addFlash('success', $this->translator->trans('admin.package_admin.installed_one', ['%packages%' => $reports[0]->name.' '.$reports[0]->version]));

            return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/package_install.html.twig', [
            'package' => $opened->package->name().' '.$opened->package->version(),
            'reports' => $reports,
            'reasons' => $reasons,
            'confirm' => $confirm->createView(),
            'discard' => $this->generateUrl('admin_package_upload_discard', ['token' => $token]),
        ], new Response(status: [] === $reasons ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/uploads/{token}/discard', name: 'upload_discard', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function discard(string $token, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        if ($this->isCsrfTokenValid('discard-upload', $request->request->getString('_token'))) {
            $this->uploads->discard($token);
        }

        return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function exportForm(): FormInterface
    {
        $objects = array_keys($this->metadata->allObjects());
        $forms = array_keys($this->metadata->allForms());
        $workflows = array_values(array_map(static fn (WorkflowSource $workflow): string => $workflow->name, $this->workflows->all()));
        $choices = self::choices(...);

        return $this->forms->createNamedBuilder('export')
            ->add('name', TextType::class, ['label' => 'admin.package_admin.export_name', 'help' => 'admin.package_admin.export_name_help'])
            ->add('version', TextType::class, ['label' => 'admin.package_admin.version', 'data' => '1.0.0'])
            ->add('label', TextType::class, ['label' => 'admin.package_admin.export_label', 'required' => false])
            ->add('description', TextType::class, ['label' => 'admin.package_admin.export_description', 'required' => false])
            ->add('license', TextType::class, ['label' => 'admin.package_admin.export_license', 'data' => 'proprietary', 'help' => 'admin.package_admin.export_license_help'])
            ->add('objects', ChoiceType::class, ['label' => 'admin.objects', 'choices' => $choices($objects), 'multiple' => true, 'expanded' => true, 'choice_translation_domain' => false])
            ->add('seeds', ChoiceType::class, ['label' => 'admin.package_admin.export_seeds', 'help' => 'admin.package_admin.export_seeds_help', 'choices' => $choices($objects), 'multiple' => true, 'expanded' => true, 'required' => false, 'choice_translation_domain' => false])
            ->add('forms', ChoiceType::class, ['label' => 'admin.forms', 'choices' => $choices($forms), 'multiple' => true, 'expanded' => true, 'required' => false, 'choice_translation_domain' => false])
            ->add('workflows', ChoiceType::class, ['label' => 'admin.workflows', 'help' => 'admin.package_admin.export_workflows_help', 'choices' => $choices($workflows), 'multiple' => true, 'expanded' => true, 'required' => false, 'choice_translation_domain' => false])
            ->getForm();
    }

    /**
     * Each name its own label.
     *
     * @param array<int|string> $names
     *
     * @return array<string, string>
     */
    private static function choices(array $names): array
    {
        $choices = [];
        foreach ($names as $name) {
            $choices[(string) $name] = (string) $name;
        }

        return $choices;
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return list<string>
     */
    private static function names(FormInterface $form, string $field): array
    {
        $value = $form->get($field)->getData();

        return \is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function text(FormInterface $form, string $field): string
    {
        $value = $form->get($field)->getData();

        return \is_string($value) ? trim($value) : '';
    }

    private function denyUnlessAdministrator(): void
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the packages.');
        }
    }
}
