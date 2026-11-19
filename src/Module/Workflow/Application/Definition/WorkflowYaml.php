<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the YAML of a workflow, the definition's and the editor's: a date written without quotes
 * (due_on: 2026-12-31) stays the text it shows.
 *
 * Without a flag, Symfony's parser turns a YAML timestamp into the Unix time, an integer that no
 * date field takes and that the editor showed as 1798675200; with Yaml::PARSE_DATETIME it gives a
 * DateTimeImmutable, turned back into text here. The original text is gone by then: a date alone
 * (midnight in UTC, as the parser reads one) is written back as Y-m-d, anything else in ISO 8601,
 * so 2026-12-31 10:00:00 reads as 2026-12-31T10:00:00+00:00.
 */
final class WorkflowYaml
{
    /**
     * @param int-mask-of<Yaml::PARSE_*> $flags
     *
     * @throws ParseException
     */
    public static function parse(string $yaml, int $flags = 0): mixed
    {
        return self::dates(Yaml::parse($yaml, $flags | Yaml::PARSE_DATETIME));
    }

    private static function dates(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return self::text($value);
        }
        if (\is_array($value)) {
            return array_map(self::dates(...), $value);
        }

        return $value;
    }

    private static function text(\DateTimeInterface $date): string
    {
        if ('00:00:00.000000' === $date->format('H:i:s.u') && 0 === $date->getOffset()) {
            return $date->format('Y-m-d');
        }

        return $date->format('000000' === $date->format('u') ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
    }
}
