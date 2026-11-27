<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Message\MessageTranslator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Every language but English translates every Message raised in src/, with the same
 * placeholders, and nothing else: a new message needs its key in translations/errors.<locale>.yaml,
 * a removed one leaves none behind. English is what each `new Message(...)` declares, read here
 * from the sources: the key and the text must be literal strings there.
 */
final class ErrorCatalogsTest extends KernelTestCase
{
    public function testTheCatalogsTranslateEveryMessageWithItsPlaceholders(): void
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');
        $messages = self::messagesInTheSources($root.'/src', ['Message', '\\App\\Shared\\Message\\Message'], '');
        self::assertNotEmpty($messages);
        // The package format's texts, translated here (see ProblemMessages).
        $texts = self::messagesInTheSources($root.'/vendor/fedale/metadata-driven-package-format/src', ['Text'], 'package_format.');
        self::assertNotEmpty($texts);
        $messages = [...$messages, ...$texts];
        ksort($messages);

        $locales = array_diff(self::getContainer()->getParameter('kernel.enabled_locales'), [self::getContainer()->getParameter('kernel.default_locale')]);
        self::assertNotEmpty($locales);
        foreach ($locales as $locale) {
            $file = \sprintf('%s/translations/%s.%s.yaml', self::getContainer()->getParameter('kernel.project_dir'), MessageTranslator::DOMAIN, $locale);
            self::assertFileExists($file);
            $catalog = Yaml::parseFile($file);
            self::assertIsArray($catalog);
            $translations = self::flatten($catalog);

            self::assertSame([], array_values(array_diff(array_keys($messages), array_keys($translations))), basename($file).' misses these keys.');
            self::assertSame([], array_values(array_diff(array_keys($translations), array_keys($messages))), basename($file).' has keys no message has.');
            foreach ($messages as $key => $text) {
                self::assertSame(self::placeholders($text), self::placeholders($translations[$key]), \sprintf('%s: "%s" has other placeholders than "%s".', basename($file), $key, $text));
            }
        }
    }

    /**
     * @param list<string> $classes the class of the texts, as `new` names it
     * @param string       $prefix  of their keys in the catalogs
     *
     * @return array<string, string> key => English text
     */
    private static function messagesInTheSources(string $directory, array $classes, string $prefix): array
    {
        $messages = [];
        foreach ((new Finder())->files()->in($directory)->name('*.php') as $file) {
            $tokens = array_values(array_filter(
                \PhpToken::tokenize($file->getContents()),
                static fn (\PhpToken $token): bool => !$token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
            ));
            foreach ($tokens as $i => $token) {
                if (!$token->is(\T_NEW) || !\in_array($tokens[$i + 1]->text ?? '', $classes, true)) {
                    continue;
                }
                $where = \sprintf('%s:%d', $file->getRelativePathname(), $token->line);
                [, $open, $key, $comma, $text] = \array_slice($tokens, $i + 1, 5) + array_fill(0, 5, null);
                self::assertTrue(
                    '(' === $open?->text && ',' === $comma?->text && self::isLiteral($key) && self::isLiteral($text),
                    \sprintf('%s: the key and the text of a Message must be literal single-quoted strings.', $where),
                );
                \assert(null !== $key && null !== $text);
                $key = $prefix.self::literal($key);
                $text = self::literal($text);
                self::assertTrue(!isset($messages[$key]) || $messages[$key] === $text, \sprintf('%s: "%s" has another text elsewhere: "%s".', $where, $key, $messages[$key] ?? ''));
                $messages[$key] = $text;
            }
        }
        ksort($messages);

        return $messages;
    }

    private static function isLiteral(?\PhpToken $token): bool
    {
        return null !== $token && $token->is(\T_CONSTANT_ENCAPSED_STRING) && str_starts_with($token->text, "'");
    }

    private static function literal(\PhpToken $token): string
    {
        return strtr(substr($token->text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]);
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%([a-z_]+)%/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @param array<mixed> $messages
     *
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            $flat += \is_array($value) ? self::flatten($value, $prefix.$key.'.') : [$prefix.$key => \is_string($value) ? $value : ''];
        }

        return $flat;
    }
}
