<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\TranslateCommand;
use App\Module\Metadata\Application\Translation\TranslationKeys;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Domain\Model\Translation;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Sets or removes the text of a key in one language.
 *
 * A key derived from the metadata takes a text in the other languages only: in the default
 * language the label itself is the text. A text key ("text.<name>") is defined by its text in
 * the default language; removing that removes the text in every language, unless a {t:name}
 * token still places it.
 */
final class TranslateHandler
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly TranslationRepository $translations,
        private readonly TranslationKeys $keys,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    public function __invoke(TranslateCommand $command): void
    {
        $text = null === $command->text || '' === trim($command->text) ? null : trim($command->text);
        $isDefault = $command->locale === $this->defaultLocale;
        $this->assertLocale($command->locale);

        if (TranslationKey::isText($command->key)) {
            $this->assertText($command->key, $isDefault, $text);
        } elseif (!isset($this->keys->derived()[$command->key])) {
            throw new MetadataInputInvalid(['key' => [\sprintf('"%s" is no key of the metadata (see Admin > Translations for the keys).', $command->key)]]);
        } elseif ($isDefault) {
            throw new MetadataInputInvalid(['locale' => [\sprintf('In %s, the text of "%s" is the one of the metadata: change the label itself.', $this->defaultLocale, $command->key)]]);
        }

        $this->transactions->run(function () use ($command, $text, $isDefault): void {
            $translation = $this->translations->find($command->locale, $command->key);
            if (null === $text) {
                if ($isDefault) {
                    $this->translations->removeKey($command->key);
                } elseif (null !== $translation) {
                    $this->translations->remove($translation);
                }

                return;
            }
            if (null === $translation) {
                $this->translations->save(new Translation(Uuid::v7(), $command->locale, $command->key, $text));
            } elseif ($translation->getText() !== $text) {
                $translation->change($text);
                $this->translations->save($translation);
            }
        });
        // The texts are served with the metadata: the same cache, dropped the same way.
        $this->events->dispatch(new MetadataChanged(''));
    }

    private function assertLocale(string $locale): void
    {
        if (!\in_array($locale, $this->enabledLocales, true) && $locale !== $this->defaultLocale) {
            throw new MetadataInputInvalid(['locale' => [\sprintf('"%s" is no enabled language (%s).', $locale, implode(', ', array_unique([$this->defaultLocale, ...$this->enabledLocales])))]]);
        }
    }

    private function assertText(string $key, bool $isDefault, ?string $text): void
    {
        $name = substr($key, \strlen(TranslationKey::TEXT));
        if (1 !== preg_match(TranslationKey::TEXT_NAME_PATTERN, $name) || \strlen($name) > 50) {
            throw new MetadataInputInvalid(['key' => [\sprintf('Invalid text "%s": after "text.", 1 to 50 lowercase letters, digits and single underscores, starting with a letter.', $key)]]);
        }
        $defined = null !== $this->translations->find($this->defaultLocale, $key);
        if (!$isDefault && !$defined) {
            throw new MetadataInputInvalid(['key' => [\sprintf('The text "%s" is not defined: give it a text in %s first.', $key, $this->defaultLocale)]]);
        }
        if ($isDefault && null === $text && [] !== ($usages = $this->keys->textUsages()[$name] ?? [])) {
            throw new MetadataInputInvalid(['text' => [\sprintf('{t:%s} is still placed by %s: remove the tokens first.', $name, implode(', ', $usages))]]);
        }
    }
}
