<?php

declare(strict_types=1);

namespace App\Module\Package\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Package\Application\InstallReport;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Source\PackageLocator;
use App\Module\Package\Application\Source\PackageSources;
use App\Module\Package\Application\Upgrade\ConflictView;
use App\Module\Package\Application\Upgrade\PackageUpgrader;
use App\Module\Package\Application\Upgrade\Resolution;
use App\Module\Package\Application\Upgrade\Strategy;
use App\Module\Package\Domain\Exception\InstallRefused;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Module\Package\Domain\Exception\UpgradeRefused;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use Fedale\PackageFormat\InvalidPackage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Packages in the admin: the ones installed, the ones the indexes offer, and the indexes.
 * Installing and upgrading go through a preview first: an install's is a dry run; an upgrade's
 * lists its changes and its conflicts, both versions of each side by side, for the user to
 * choose item by item.
 *
 * Packages are named here, never given as a directory: the server's files are the console's.
 */
#[Route('/admin/packages', name: 'admin_package_')]
final class PackageAdminController extends AbstractController
{
    private const string NAMED = '~^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*(@\d+\.\d+\.\d+)?$~';

    public function __construct(
        private readonly PackageSources $sources,
        private readonly PackageLocator $locator,
        private readonly PackageInstaller $installer,
        private readonly PackageUpgrader $upgrader,
        private readonly PackageInstallationRepository $installations,
        private readonly FormFactoryInterface $forms,
        private readonly AccessCheckerInterface $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $add = $this->indexForm()->handleRequest($request);
        if ($add->isSubmitted() && $add->isValid()) {
            try {
                $key = self::text($add, 'key');
                $this->sources->add(self::text($add, 'name'), self::text($add, 'url'), '' === $key ? null : $key, true === $add->get('unsigned')->getData());
                $this->addFlash('success', $this->translator->trans('admin.package_admin.index_added', ['%name%' => self::text($add, 'name')]));

                return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
            } catch (PackageSourceFailed $e) {
                $add->addError(new FormError($e->getMessage()));
            }
        }

        $available = [];
        $unreachable = null;
        try {
            $available = $this->sources->search();
        } catch (PackageSourceFailed $e) {
            $unreachable = $e->getMessage();
        }
        $installed = [];
        foreach ($this->installations->all() as $installation) {
            $installed[$installation->getName()] = $installation;
        }
        $indexes = [];
        try {
            $indexes = $this->sources->indexes();
        } catch (PackageSourceFailed $e) {
            $unreachable ??= $e->getMessage();
        }

        // What each one offers: install, upgrade, or nothing newer than what is here.
        $offers = [];
        foreach ($available as $located) {
            $here = $installed[$located->entry->name] ?? null;
            $offers[] = [
                'located' => $located,
                'action' => match (true) {
                    null === $here => 'install',
                    version_compare($here->getVersion(), $located->entry->version, '<') => 'upgrade',
                    default => null,
                },
                'installed' => $here?->getVersion(),
            ];
        }

        return $this->render('admin/packages.html.twig', [
            'installed' => $installed,
            'available' => $offers,
            'unreachable' => $unreachable,
            'indexes' => $indexes,
            // The indexes offered to every workspace (the catalog, configured; the system admin's,
            // system:package:repo) cannot be removed here.
            'remove_forms' => array_combine(
                array_column($indexes, 'name'),
                array_map(fn (array $index): ?FormView => $index['shared'] ? null : $this->confirmForm('remove_index', [])->createView(), $indexes),
            ),
            'add_form' => $add->createView(),
        ], new Response(status: $add->isSubmitted() && !$add->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/indexes/{name}/remove', name: 'index_remove', requirements: ['name' => '[a-z][a-z0-9-]*'], methods: ['POST'])]
    public function removeIndex(string $name, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $form = $this->confirmForm('remove_index', [])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->sources->remove($name);
                $this->addFlash('success', $this->translator->trans('admin.package_admin.index_removed', ['%name%' => $name]));
            } catch (PackageSourceFailed $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/install', name: 'install', methods: ['GET', 'POST'])]
    public function install(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        [$package, $repo] = $this->named($request, 'install');
        $confirm = $this->confirmForm('install', ['package' => $package, 'repo' => $repo ?? ''])->handleRequest($request);
        $real = $confirm->isSubmitted() && $confirm->isValid();

        $reports = [];
        $reasons = [];
        try {
            // It comes with the packages it requires that are missing: the preview lists them all.
            $chain = $this->locator->openWithRequirements($package, $repo);
            try {
                $reports = $this->installer->installAll($chain, dryRun: !$real);
            } finally {
                foreach ($chain as $opened) {
                    $this->locator->close($opened);
                }
            }
        } catch (PackageSourceFailed $e) {
            $reasons = [$e->getMessage()];
        } catch (InvalidPackage $e) {
            $reasons = array_map(strval(...), $e->problems);
        } catch (InstallRefused $e) {
            $reasons = $e->reasons;
        }
        if ($real && [] !== $reports) {
            $this->addFlash('success', $this->translator->trans(\count($reports) > 1 ? 'admin.package_admin.installed_many' : 'admin.package_admin.installed_one', [
                '%packages%' => implode(', ', array_map(static fn (InstallReport $report): string => $report->name.' '.$report->version, $reports)),
            ]));

            return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/package_install.html.twig', [
            'package' => $package,
            'reports' => $reports,
            'reasons' => $reasons,
            'confirm' => $confirm->createView(),
        ], new Response(status: [] === $reasons ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/upgrade', name: 'upgrade', methods: ['GET', 'POST'])]
    public function upgrade(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        [$package, $repo] = $this->named($request, 'upgrade');

        $preview = null;
        $choices = null;
        $reasons = [];
        try {
            $opened = $this->locator->open($package, $repo);
            try {
                $preview = $this->upgrader->preview($opened->package);
                $choices = $this->resolutionForm($package, $repo, $preview->conflicts)->handleRequest($request);
                if ($choices->isSubmitted() && $choices->isValid() && [] === $preview->refusals) {
                    $report = $this->upgrader->upgrade($opened->package, $opened->source, $this->resolution($choices, $preview->conflicts));
                    $this->addFlash('success', $this->translator->trans('admin.package_admin.upgrade_done', ['%name%' => $report->name, '%from%' => $report->from, '%to%' => $report->to]));

                    return $this->redirectToRoute('admin_package_index', [], Response::HTTP_SEE_OTHER);
                }
            } finally {
                $this->locator->close($opened);
            }
        } catch (PackageSourceFailed $e) {
            $reasons = [$e->getMessage()];
        } catch (InvalidPackage $e) {
            $reasons = array_map(strval(...), $e->problems);
        } catch (UpgradeRefused $e) {
            $reasons = $e->reasons;
        }

        $reasons = [...$reasons, ...$preview->refusals ?? []];

        return $this->render('admin/package_upgrade.html.twig', [
            'package' => $package,
            'preview' => $preview,
            'reasons' => $reasons,
            'choices' => $choices?->createView(),
        ], new Response(status: [] === $reasons ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The package a request names, "vendor/name" or "vendor/name@1.0.0", and the index to take
     * it from; never a path. A link gives them in the query, a confirmation in its form.
     *
     * @return array{string, string|null}
     */
    private function named(Request $request, string $form): array
    {
        $values = $request->isMethod('POST') ? $request->request->all($form) : $request->query->all();
        $package = \is_string($values['package'] ?? null) ? $values['package'] : '';
        $repo = \is_string($values['repo'] ?? null) && '' !== $values['repo'] ? $values['repo'] : null;
        if (1 !== preg_match(self::NAMED, $package)) {
            throw $this->createNotFoundException('Name a package: vendor/name, or vendor/name@1.0.0.');
        }

        return [$package, $repo];
    }

    /**
     * @param list<ConflictView> $conflicts
     *
     * @return FormInterface<mixed>
     */
    private function resolutionForm(string $package, ?string $repo, array $conflicts): FormInterface
    {
        $builder = $this->forms->createNamedBuilder('upgrade', FormType::class, ['package' => $package, 'repo' => $repo ?? ''])
            ->add('package', HiddenType::class)
            ->add('repo', HiddenType::class);
        foreach ($conflicts as $i => $conflict) {
            $builder->add('c'.$i, ChoiceType::class, [
                'label' => $conflict->description,
                'choices' => ['admin.package_admin.keep' => Strategy::Keep->value, 'admin.package_admin.take' => Strategy::Overwrite->value],
                'expanded' => true,
                'data' => Strategy::Keep->value,
            ]);
        }

        return $builder->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     * @param list<ConflictView>   $conflicts
     */
    private function resolution(FormInterface $form, array $conflicts): Resolution
    {
        $items = [];
        foreach ($conflicts as $i => $conflict) {
            $choice = $form->get('c'.$i)->getData();
            $items[$conflict->key] = Strategy::from(\is_string($choice) ? $choice : Strategy::Keep->value);
        }

        return new Resolution(null, $items);
    }

    /**
     * A form with hidden values and a button: the confirmation of an install, the removal of an
     * index. CSRF-protected, as every form is.
     *
     * @param array<string, string> $values
     *
     * @return FormInterface<mixed>
     */
    private function confirmForm(string $name, array $values): FormInterface
    {
        $builder = $this->forms->createNamedBuilder($name, FormType::class, $values);
        foreach (array_keys($values) as $field) {
            $builder->add($field, HiddenType::class);
        }

        return $builder->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function indexForm(): FormInterface
    {
        return $this->forms->createNamedBuilder('index')
            ->add('name', TextType::class, ['label' => 'admin.name', 'help' => 'admin.package_admin.index_name_help'])
            ->add('url', UrlType::class, ['label' => 'admin.package_admin.index_url', 'help' => 'admin.package_admin.index_url_help', 'default_protocol' => null])
            ->add('key', TextType::class, ['label' => 'admin.package_admin.index_key', 'required' => false, 'help' => 'admin.package_admin.index_key_help'])
            ->add('unsigned', CheckboxType::class, ['label' => 'admin.package_admin.index_unsigned', 'required' => false])
            ->getForm();
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
