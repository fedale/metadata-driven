<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Metadata\Contract\Dto\TextDto;
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
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The languages of the metadata, in two pages that each do one thing:
 *
 * - Translations: the labels of the metadata (objects, fields, options, forms) translated
 *   into one other language at a time. Their original text is edited where it is defined.
 * - Texts: the texts placed by {t:name} tokens, each written in every language at once.
 */
#[Route('/admin', name: 'admin_')]
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

    #[Route('/translations', name: 'translation_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAdministrator();
        $targets = $this->targetLocales();

        return [] === $targets
            ? $this->redirectToRoute('admin_text_index')
            : $this->redirectToRoute('admin_translation_edit', ['locale' => $targets[0]]);
    }

    #[Route('/translations/{locale}', name: 'translation_edit', requirements: ['locale' => '[a-z]{2,3}(?:_[A-Z]{2})?'], methods: ['GET', 'POST'])]
    public function edit(string $locale, Request $request): Response
    {
        $this->denyUnlessAdministrator();
        if (!\in_array($locale, $this->targetLocales(), true)) {
            throw $this->createNotFoundException(\sprintf('"%s" is no language to translate into.', $locale));
        }
        $entries = array_values(array_filter(
            $this->translations->entries($locale),
            static fn (TranslationEntryDto $entry): bool => !$entry->isText(),
        ));

        $data = [];
        foreach ($entries as $index => $entry) {
            $data['t'.$index] = $entry->translation;
        }
        // One input per key, named by its position: form names cannot hold the dots of a key.
        $builder = $this->forms->createNamedBuilder('translations', FormType::class, $data);
        foreach ($entries as $index => $entry) {
            $builder->add('t'.$index, TextType::class, ['label' => $entry->key, 'required' => false, 'attr' => ['placeholder' => $entry->source]]);
        }
        $form = $builder->getForm()->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $changes = [];
            foreach ($entries as $index => $entry) {
                $changes[] = ['t'.$index, $locale, $entry->key, $entry->translation];
            }
            $saved = $this->save($form, $changes);
            if (null !== $saved) {
                $this->flashSaved($saved);

                return $this->redirectToRoute('admin_translation_edit', ['locale' => $locale], Response::HTTP_SEE_OTHER);
            }
        }

        $groups = [];
        foreach ($entries as $index => $entry) {
            $groups[$entry->group][$index] = ['entry' => $entry, 'edit_url' => $this->sourceUrl($entry->key)];
        }

        return $this->render('admin/translations.html.twig', [
            'locale' => $locale,
            'default_locale' => $this->translations->defaultLocale(),
            'targets' => $this->targetLocales(),
            'groups' => $groups,
            'form' => $form,
        ]);
    }

    #[Route('/texts', name: 'text_index', methods: ['GET', 'POST'])]
    public function texts(Request $request): Response
    {
        $this->denyUnlessAdministrator();
        $texts = $this->translations->texts();
        $locales = $this->translations->locales();

        $data = [];
        foreach ($texts as $index => $text) {
            foreach ($locales as $locale) {
                $data[self::child($index, $locale)] = $text->texts[$locale] ?? null;
            }
        }
        $builder = $this->forms->createNamedBuilder('texts', FormType::class, $data);
        foreach ($texts as $index => $text) {
            foreach ($locales as $locale) {
                $builder->add(self::child($index, $locale), TextType::class, ['label' => $text->name.' ('.$locale.')', 'required' => false]);
            }
        }
        $form = $builder->getForm()->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->save($form, $this->textChanges($form, $texts, $locales));
            if (null !== $saved) {
                $this->flashSaved($saved);

                return $this->redirectToRoute('admin_text_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        $newText = $this->newTextForm($locales)->handleRequest($request);
        if ($newText->isSubmitted() && $newText->isValid() && $this->createText($newText, $texts, $locales)) {
            $this->addFlash('success', 'Text created.');

            return $this->redirectToRoute('admin_text_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/texts.html.twig', [
            'default_locale' => $this->translations->defaultLocale(),
            'targets' => $this->targetLocales(),
            'locales' => $locales,
            'texts' => $texts,
            'form' => $form,
            'new_text' => $newText,
        ]);
    }

    /**
     * The changes of the texts, the default language first: removing a text there removes it in
     * every language, so its other inputs are left alone.
     *
     * @param FormInterface<mixed> $form
     * @param list<TextDto>        $texts
     * @param list<string>         $locales the default language first
     *
     * @return list<array{string, string, string, string|null}>
     */
    private function textChanges(FormInterface $form, array $texts, array $locales): array
    {
        $changes = [];
        foreach ($texts as $index => $text) {
            foreach ($locales as $position => $locale) {
                $changes[] = [self::child($index, $locale), $locale, $text->key(), $text->texts[$locale] ?? null];
                if (0 === $position && null === self::value($form->get(self::child($index, $locale)))) {
                    break;
                }
            }
        }

        return $changes;
    }

    /**
     * Applies the inputs that changed; a refusal is shown under its input.
     *
     * @param FormInterface<mixed>                             $form
     * @param list<array{string, string, string, string|null}> $changes input name, locale, key, current text
     *
     * @return int|null the number of texts changed; null when one was refused
     */
    private function save(FormInterface $form, array $changes): ?int
    {
        $saved = 0;
        $refused = false;
        foreach ($changes as [$child, $locale, $key, $current]) {
            $text = self::value($form->get($child));
            if ($text === $current) {
                continue;
            }
            try {
                $this->manager->translate($locale, $key, $text);
                ++$saved;
            } catch (MetadataException $e) {
                foreach (self::messages($e) as $message) {
                    $form->get($child)->addError(new FormError($message));
                }
                $refused = true;
            }
        }

        return $refused ? null : $saved;
    }

    /**
     * @param list<string> $locales the default language first
     *
     * @return FormInterface<mixed>
     */
    private function newTextForm(array $locales): FormInterface
    {
        $builder = $this->forms->createNamedBuilder('new_text', FormType::class, null, ['action' => $this->generateUrl('admin_text_index')])
            ->add('name', TextType::class, ['label' => 'Name', 'help' => self::TEXT_NAME_HELP]);
        foreach ($locales as $position => $locale) {
            $builder->add($locale, TextType::class, ['label' => ucfirst(Languages::getName($locale, 'en')).(0 === $position ? '' : ' (optional)'), 'required' => 0 === $position]);
        }

        return $builder->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     * @param list<TextDto>        $texts
     * @param list<string>         $locales the default language first
     */
    private function createText(FormInterface $form, array $texts, array $locales): bool
    {
        $name = self::value($form->get('name')) ?? '';
        if ('' === $name || null === self::value($form->get($locales[0]))) {
            $form->addError(new FormError(\sprintf('Give the text a name and its %s text.', $locales[0])));

            return false;
        }
        foreach ($texts as $text) {
            if ($text->name === $name) {
                $form->get('name')->addError(new FormError(\sprintf('A text named "%s" already exists.', $name)));

                return false;
            }
        }
        try {
            foreach ($locales as $locale) {
                if (null !== $value = self::value($form->get($locale))) {
                    $this->manager->translate($locale, TranslationKey::text($name), $value);
                }
            }

            return true;
        } catch (MetadataException $e) {
            foreach (self::messages($e) as $message) {
                $form->get('name')->addError(new FormError($message));
            }

            return false;
        }
    }

    /**
     * Where the original text of a key is edited: the object, the field or the form defining it.
     */
    private function sourceUrl(string $key): ?string
    {
        $parts = explode('.', $key);
        if (\count($parts) < 2) {
            return null;
        }

        return match ($parts[0]) {
            'object' => $this->generateUrl('admin_object_show', ['object' => $parts[1]]),
            'field', 'option', 'inverse', 'message' => isset($parts[2]) ? $this->generateUrl('admin_field_edit', ['object' => $parts[1], 'field' => $parts[2]]) : null,
            'form', 'section', 'service' => $this->generateUrl('admin_form_edit', ['form' => $parts[1]]),
            default => null,
        };
    }

    /**
     * @return list<string> the enabled languages but the default one
     */
    private function targetLocales(): array
    {
        return array_values(array_filter(
            $this->translations->locales(),
            fn (string $locale): bool => $locale !== $this->translations->defaultLocale(),
        ));
    }

    private function flashSaved(int $saved): void
    {
        $this->addFlash('success', 0 === $saved ? 'Nothing changed.' : \sprintf('%d %s saved.', $saved, 1 === $saved ? 'text' : 'texts'));
    }

    private static function child(int $index, string $locale): string
    {
        return 'x'.$index.'_'.$locale;
    }

    /**
     * @param FormInterface<mixed> $input
     */
    private static function value(FormInterface $input): ?string
    {
        $value = $input->getData();

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private static function messages(MetadataException $e): array
    {
        return $e instanceof MetadataInputInvalid ? array_merge(...array_values($e->errors)) : [$e->getMessage()];
    }

    private function denyUnlessAdministrator(): void
    {
        if (!$this->access->canAdminister()) {
            throw $this->createAccessDeniedException('No permission to manage the translations.');
        }
    }
}
