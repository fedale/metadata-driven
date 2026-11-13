<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Controller;

use App\Module\Template\Application\Source\TemplateSources;
use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The template indexes offered to every tenant, on the console of the installation: the page of
 * tenant:template:repo. The console runs in the landlord's session, where an index added gets no
 * tenant: every tenant lists it, none removes it.
 */
#[Route('/console/indexes', name: 'console_')]
final class ConsoleIndexController extends AbstractController
{
    public function __construct(
        private readonly TemplateSources $sources,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'indexes', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $add = $this->addForm()->handleRequest($request);
        if ($add->isSubmitted() && $add->isValid()) {
            $name = self::text($add, 'name');
            $key = self::text($add, 'key');
            try {
                $this->sources->add($name, self::text($add, 'url'), '' === $key ? null : $key, true === $add->get('unsigned')->getData());
                $this->logger->notice('Operator {operator} added the shared index {name}', ['operator' => $this->getUser()?->getUserIdentifier(), 'name' => $name]);
                $this->addFlash('success', $this->translator->trans('admin.template_admin.index_added', ['%name%' => $name]));

                return $this->redirectToRoute('console_indexes', [], Response::HTTP_SEE_OTHER);
            } catch (TemplateSourceFailed $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $indexes = [];
        try {
            $indexes = $this->sources->indexes();
        } catch (TemplateSourceFailed $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->render('console/indexes.html.twig', [
            'indexes' => $indexes,
            // The catalog is configured, not added: it isn't removed here.
            'remove_forms' => array_combine(
                array_column($indexes, 'name'),
                array_map(fn (array $index) => TemplateSources::CATALOG === $index['name'] ? null : $this->removeForm($index['name'])->createView(), $indexes),
            ),
            'add_form' => $add->createView(),
        ], new Response(status: $add->isSubmitted() && !$add->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{name}/remove', name: 'index_remove', requirements: ['name' => '[a-z][a-z0-9-]*'], methods: ['POST'])]
    public function remove(string $name, Request $request): Response
    {
        if ($this->isValidSubmission($this->removeForm($name), $request)) {
            try {
                $this->sources->remove($name);
                $this->logger->notice('Operator {operator} removed the shared index {name}', ['operator' => $this->getUser()?->getUserIdentifier(), 'name' => $name]);
                $this->addFlash('success', $this->translator->trans('admin.template_admin.index_removed', ['%name%' => $name]));
            } catch (TemplateSourceFailed $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('console_indexes', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function addForm(): FormInterface
    {
        return $this->forms->createNamedBuilder('add_index', FormType::class)
            ->add('name', TextType::class, ['label' => 'admin.name', 'help' => 'admin.template_admin.index_name_help'])
            ->add('url', UrlType::class, ['label' => 'admin.template_admin.index_url', 'help' => 'admin.template_admin.index_url_help', 'default_protocol' => null])
            ->add('key', TextType::class, ['label' => 'admin.template_admin.index_key', 'required' => false, 'help' => 'admin.template_admin.index_key_help'])
            ->add('unsigned', CheckboxType::class, ['label' => 'admin.template_admin.index_unsigned', 'required' => false])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function removeForm(string $name): FormInterface
    {
        return $this->forms->createNamedBuilder('remove_'.str_replace('-', '_', $name), FormType::class, null, [
            'action' => $this->generateUrl('console_index_remove', ['name' => $name]),
        ])->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function text(FormInterface $form, string $child): string
    {
        $value = $form->get($child)->getData();

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * Submitted, and valid: its CSRF token checked. An action of the console runs on nothing less.
     *
     * @param FormInterface<mixed> $form
     */
    private function isValidSubmission(FormInterface $form, Request $request): bool
    {
        $form->handleRequest($request);

        return $form->isSubmitted() && $form->isValid();
    }
}
