<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Translation;

use App\Module\Metadata\Application\Translation\MetadataTranslator;
use App\Module\Metadata\Application\Translation\TranslationLookup;
use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\ManyToManyReference;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationReference;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * The metadata in the user's language: the same definitions, with their labels, options and
 * titles translated and the {t:name} tokens replaced (see MetadataTranslator).
 *
 * For the pages showing records to the users. Autowired as
 * #[Target('localizedMetadata')] MetadataRegistryInterface; every other service gets the
 * metadata as written, so that a translated text can never be saved back as a label.
 *
 * The language follows the request (LocaleAwareInterface): the user's preference, or the
 * browser's. The translated copies are kept for the request.
 */
#[AsAlias(MetadataRegistryInterface::class.' $localizedMetadata')]
#[AsEventListener(event: MetadataChanged::class, method: 'reset')]
final class LocalizedMetadataRegistry implements MetadataRegistryInterface, LocaleAwareInterface, ResetInterface
{
    private string $locale;
    private ?TranslationLookup $lookup = null;
    /** @var array<string, ObjectDefinitionDto>|null */
    private ?array $objects = null;
    /** @var array<string, FormDefinitionDto>|null */
    private ?array $forms = null;

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly TranslationCatalog $catalog,
        private readonly MetadataTranslator $translator,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
        $this->locale = $defaultLocale;
    }

    public function getObject(string $name): ObjectDefinitionDto
    {
        return $this->objects()[$name] ?? $this->metadata->getObject($name);
    }

    public function hasObject(string $name): bool
    {
        return $this->metadata->hasObject($name);
    }

    public function allObjects(): array
    {
        return $this->objects();
    }

    /**
     * As defined: the archived objects are an admin matter, never shown to the users.
     */
    public function archivedObjects(): array
    {
        return $this->metadata->archivedObjects();
    }

    public function incomingRelations(string $targetName): array
    {
        $references = [];
        foreach ($this->metadata->incomingRelations($targetName) as $reference) {
            $object = $this->getObject($reference->object->name);
            $field = $object->field($reference->field->name);
            $references[] = new RelationReference($object, $field, $field->relation() ?? $reference->settings);
        }

        return $references;
    }

    public function incomingManyToMany(string $targetName): array
    {
        $references = [];
        foreach ($this->metadata->incomingManyToMany($targetName) as $reference) {
            $object = $this->getObject($reference->object->name);
            $field = $object->field($reference->field->name);
            $references[] = new ManyToManyReference($object, $field, $field->manyToMany() ?? $reference->settings);
        }

        return $references;
    }

    public function getForm(string $name): FormDefinitionDto
    {
        return $this->forms()[$name] ?? $this->metadata->getForm($name);
    }

    public function hasForm(string $name): bool
    {
        return $this->metadata->hasForm($name);
    }

    public function allForms(): array
    {
        return $this->forms();
    }

    public function setLocale(string $locale): void
    {
        if ($locale !== $this->locale) {
            $this->locale = $locale;
            $this->reset();
        }
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function reset(): void
    {
        $this->lookup = null;
        $this->objects = null;
        $this->forms = null;
    }

    /**
     * @return array<string, ObjectDefinitionDto>
     */
    private function objects(): array
    {
        return $this->objects ??= array_map(
            fn (ObjectDefinitionDto $object): ObjectDefinitionDto => $this->translator->object($object, $this->lookup()),
            $this->metadata->allObjects(),
        );
    }

    /**
     * @return array<string, FormDefinitionDto>
     */
    private function forms(): array
    {
        return $this->forms ??= array_map(
            fn (FormDefinitionDto $form): FormDefinitionDto => $this->translator->form($form, $this->lookup()),
            $this->metadata->allForms(),
        );
    }

    private function lookup(): TranslationLookup
    {
        if (null === $this->lookup) {
            $defaults = $this->catalog->textsOf($this->defaultLocale);
            $this->lookup = new TranslationLookup($this->locale === $this->defaultLocale ? $defaults : $this->catalog->textsOf($this->locale), $defaults);
        }

        return $this->lookup;
    }
}
