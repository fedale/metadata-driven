<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Message;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;
use App\Shared\Message\MessageTranslator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * The MessageTranslator on Symfony's translator.
 *
 * A message declares its English text where it is raised, and that is the fallback: there is no
 * English catalog to keep in step, and a message added later shows its English text until it is
 * translated. Hence the lookup in the catalog rather than trans(), which would answer the key.
 * The parameters that are messages themselves are translated first.
 */
final class SymfonyMessageTranslator implements MessageTranslator
{
    public function __construct(
        // The interface has no alias of its own; the translator service implements it.
        #[Autowire(service: 'translator')]
        private readonly TranslatorBagInterface $translator,
    ) {
    }

    #[AsTwigFilter('message')]
    public function trans(string|Message|MessageList $message, ?string $locale = null): string
    {
        if (\is_string($message)) {
            return $message;
        }
        if ($message instanceof MessageList) {
            return implode($message->separator, array_map(fn (string|Message $item): string => $this->trans($item, $locale), $message->items));
        }

        $parameters = array_map(
            fn (string|int|float|Message|MessageList $value): string => $value instanceof Message || $value instanceof MessageList ? $this->trans($value, $locale) : (string) $value,
            $message->parameters,
        );
        $catalogue = $this->translator->getCatalogue($locale);

        // has() looks into the fallback catalogues too: English has none of these keys.
        return strtr($catalogue->has($message->key, self::DOMAIN) ? $catalogue->get($message->key, self::DOMAIN) : $message->text, Message::placeholders($parameters));
    }

    public function transAll(array $messages, ?string $locale = null): array
    {
        return array_map(fn (string|Message|MessageList $message): string => $this->trans($message, $locale), $messages);
    }
}
