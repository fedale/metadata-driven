<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Metadata\Contract\Dto\TranslationEntryDto;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The translations of the metadata, one language at a time: the labels of the objects,
 * fields and options, the titles of the forms, and the texts placed by {t:name} tokens.
 *
 * In the default language the page lists the texts only (the metadata is the text of every
 * other key) and creates new ones.
 */
#[Route('/admin/translations', name: 'admin_translation_')]
final class AdminTranslationController extends AbstractController
{
    private const string TEXT_NAME_HELP = 'Lowercase letters, digits and single underscores. A layout places it with {t:<name>}.';

    public function __construct(
        private readonly MetadataTranslationsInterface $translations,
        private readonly MetadataManagerInterface $manager,
        private readonly FormFactoryInterface $forms,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAdministrator();
        $locales = $this->translations->locales();

        // The first language to translate into; the texts when there is none.
        return $this->redirectToRoute('admin_translation_edit', ['locale' => $locales[1] ?? $locales[0]]);
    }

    #[Route('/{locale}', name: 'edit', requirements: ['locale' => '[a-z]{2,3}(?:_[A-Z]{2})?'], methods: ['GET', 'POST'])]
    public function edit(string $locale, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        if (!\in_array($locale, $this->translations->locales(), true)) {
            throw $this->createNotFoundException(\sprintf('"%s" is no enabled language.', $locale));
        }
        $isDefault = $locale === $this->translations->defaultLocale();
        $entries = array_values(array_filter(
            $this->translations->entries($locale),
            static fn (TranslationEntryDto $entry): bool => !$isDefault || $entry->isText(),
        ));

        $form = $this->translationsForm($entries)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->save($form, $entries, $locale);
            if (null !== $saved) {
                $this->addFlash('success', 0 === $saved ? 'Nothing changed.' : \sprintf('%d %s saved.', $saved, 1 === $saved ? 'text' : 'texts'));

                return $this->redirectToRoute('admin_translation_edit', ['locale' => $locale], Response::HTTP_SEE_OTHER);
            }
        }

        $newText = $isDefault ? $this->newTextForm($locale)->handleRequest($request) : null;
        if (null !== $newText && $newText->isSubmitted() && $newText->isValid() && $this->createText($newText, $locale)) {
            $this->addFlash('success', 'Text created.');

            return $this->redirectToRoute('admin_translation_edit', ['locale' => $locale], Response::HTTP_SEE_OTHER);
        }

        $groups = [];
        foreach ($entries as $index => $entry) {
            $groups[$entry->group][$index] = $entry;
        }

        return $this->render('admin/translations.html.twig', [
            'locale' => $locale,
            'default_locale' => $this->translations->defaultLocale(),
            'locales' => $this->translations->locales(),
            'groups' => $groups,
            'form' => $form,
            'new_text' => $newText,
        ]);
    }

    /**
     * One input per key, named by its position: form names cannot hold the dots of a key.
     *
     * @param list<TranslationEntryDto> $entries
     *
     * @return FormInterface<mixed>
     */
    private function translationsForm(array $entries): FormInterface
    {
        $data = [];
        foreach ($entries as $index => $entry) {
            $data['t'.$index] = $entry->translation;
        }
        $builder = $this->forms->createNamedBuilder('translations', FormType::class, $data);
        foreach ($entries as $index => $entry) {
            $builder->add('t'.$index, TextType::class, [
                'label' => $entry->key,
                'required' => false,
                'attr' => ['placeholder' => $entry->source],
            ]);
        }

        return $builder->getForm();
    }

    /**
     * @param FormInterface<mixed>      $form
     * @param list<TranslationEntryDto> $entries
     *
     * @return int|null the number of texts changed; null when one was refused
     */
    private function save(FormInterface $form, array $entries, string $locale): ?int
    {
        $saved = 0;
        $refused = false;
        foreach ($entries as $index => $entry) {
            $value = $form->get('t'.$index)->getData();
            $text = \is_string($value) && '' !== trim($value) ? trim($value) : null;
            if ($text === $entry->translation) {
                continue;
            }
            try {
                $this->manager->translate($locale, $entry->key, $text);
                ++$saved;
            } catch (MetadataException $e) {
                $messages = $e instanceof MetadataInputInvalid ? array_merge(...array_values($e->errors)) : [$e->getMessage()];
                foreach ($messages as $message) {
                    $form->get('t'.$index)->addError(new FormError($message));
                }
                $refused = true;
            }
        }

        return $refused ? null : $saved;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function newTextForm(string $locale): FormInterface
    {
        return $this->forms->createNamedBuilder('new_text', FormType::class, null, [
            'action' => $this->generateUrl('admin_translation_edit', ['locale' => $locale]),
        ])
            ->add('name', TextType::class, ['label' => 'Name', 'help' => self::TEXT_NAME_HELP])
            ->add('text', TextType::class, ['label' => 'Text ('.$locale.')'])
            ->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function createText(FormInterface $form, string $locale): bool
    {
        $name = $form->get('name')->getData();
        $text = $form->get('text')->getData();
        $name = \is_string($name) ? trim($name) : '';
        if ('' === $name || !\is_string($text) || '' === trim($text)) {
            $form->addError(new FormError('Give the text a name and a text.'));

            return false;
        }
        $key = TranslationKey::text($name);
        if (\in_array($key, array_map(static fn (TranslationEntryDto $entry): string => $entry->key, $this->translations->entries($locale)), true)) {
            $form->get('name')->addError(new FormError(\sprintf('A text named "%s" already exists.', $name)));

            return false;
        }
        try {
            $this->manager->translate($locale, $key, $text);

            return true;
        } catch (MetadataException $e) {
            $messages = $e instanceof MetadataInputInvalid ? array_merge(...array_values($e->errors)) : [$e->getMessage()];
            foreach ($messages as $message) {
                $form->get('name')->addError(new FormError($message));
            }

            return false;
        }
    }

    private function denyUnlessAdministrator(): void
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the translations.');
        }
    }
}
