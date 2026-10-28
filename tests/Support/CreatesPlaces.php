<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Regions, provinces and municipalities, and a "venue" whose address picks them in a cascade.
 */
trait CreatesPlaces
{
    /**
     * @return array<string, string> record ids keyed by name: lombardia, lazio, mi, rm, milano, monza, roma
     */
    private static function createPlaces(ContainerInterface $container): array
    {
        $metadata = $container->get(MetadataManagerInterface::class);
        $records = $container->get(RecordManagerInterface::class);

        $metadata->createObject('region', 'Region', 'Regions');
        $metadata->createField('region', 'name', 'text', 'Name', true);
        $metadata->createObject('province', 'Province', 'Provinces');
        $metadata->createField('province', 'name', 'text', 'Name', true);
        $metadata->createField('province', 'region', 'relation', 'Region', true, settings: self::relationTo('region'));
        $metadata->createObject('municipality', 'Municipality', 'Municipalities');
        $metadata->createField('municipality', 'name', 'text', 'Name', true);
        $metadata->createField('municipality', 'province', 'relation', 'Province', true, settings: self::relationTo('province'));

        $metadata->createObject('venue', 'Venue', 'Venues');
        $metadata->createField('venue', 'name', 'text', 'Name', true);
        $metadata->createField('venue', 'region', 'relation', 'Region', settings: self::relationTo('region'));
        $metadata->createField('venue', 'province', 'relation', 'Province', settings: self::relationTo('province', ['dependsOn' => 'region', 'dependsVia' => 'region']));
        $metadata->createField('venue', 'municipality', 'relation', 'Municipality', settings: self::relationTo('municipality', ['dependsOn' => 'province', 'dependsVia' => 'province']));

        $ids = ['lombardia' => self::place($records, 'region', ['name' => 'Lombardia']), 'lazio' => self::place($records, 'region', ['name' => 'Lazio'])];
        $ids['mi'] = self::place($records, 'province', ['name' => 'Milano', 'region' => $ids['lombardia']]);
        $ids['rm'] = self::place($records, 'province', ['name' => 'Roma', 'region' => $ids['lazio']]);
        $ids['milano'] = self::place($records, 'municipality', ['name' => 'Milano', 'province' => $ids['mi']]);
        $ids['monza'] = self::place($records, 'municipality', ['name' => 'Sesto San Giovanni', 'province' => $ids['mi']]);
        $ids['roma'] = self::place($records, 'municipality', ['name' => 'Roma', 'province' => $ids['rm']]);

        return $ids;
    }

    /**
     * @param array<string, string> $more
     *
     * @return array<string, mixed>
     */
    private static function relationTo(string $target, array $more = []): array
    {
        return ['target' => $target, 'onDelete' => 'restrict'] + $more;
    }

    /**
     * @param array<string, string> $values
     */
    private static function place(RecordManagerInterface $records, string $object, array $values): string
    {
        return $records->create(new RecordInput($object, $values))->id;
    }
}
