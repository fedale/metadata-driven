<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Twig;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Twig\Attribute\AsTwigFunction;

/**
 * The labels of an object for the record pages, in the user's language: e.g. "+ Qualification"
 * under the rows of a section, named after the object of a row rather than the section.
 */
final class ObjectLabels
{
    public function __construct(
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    #[AsTwigFunction('object_singular')]
    public function singular(string $object): string
    {
        return $this->metadata->getObject($object)->labelSingular;
    }
}
