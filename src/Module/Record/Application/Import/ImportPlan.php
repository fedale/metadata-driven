<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Shared\Message\Message;

/**
 * An import's columns, checked once (RecordImporter::prepare()), for every batch of its rows.
 */
final readonly class ImportPlan
{
    /**
     * @param array<string, array{ObjectDefinitionDto, FieldDefinitionDto}> $targets        relation field => its target and the unique field its cells hold
     * @param string|null                                                   $filesDirectory where the paths of file cells lead; null: no file column
     * @param list<Message>                                                 $problems       the header's and the matches': none, or nothing may be imported
     */
    public function __construct(
        public ObjectDefinitionDto $object,
        public ?FieldDefinitionDto $keyField,
        public array $targets,
        public ?string $filesDirectory,
        public array $problems,
    ) {
    }
}
