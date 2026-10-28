<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Tests\Support\CreatesPlaces;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A cascade of relation fields through HTTP: the filtered lookup and the form's hooks.
 */
final class DependentRelationsControllerTest extends WebTestCase
{
    use CreatesPlaces;
    use SignsIn;

    private KernelBrowser $client;
    /** @var array<string, string> */
    private array $ids;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::signInAsSuperAdmin($this->client);
        $this->ids = self::createPlaces(self::getContainer());
    }

    public function testTheLookupFiltersByTheParent(): void
    {
        self::assertSame(['Milano', 'Sesto San Giovanni'], $this->lookup('/o/municipality/lookup?filter[province]='.$this->ids['mi']));
        // Nothing chosen yet: nothing to pick, rather than every municipality.
        self::assertSame([], $this->lookup('/o/municipality/lookup?filter[province]='));

        $this->client->request('GET', '/o/municipality/lookup?filter[name]=x'.$this->ids['mi']);
        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/o/municipality/lookup?filter[name]='.$this->ids['mi']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testTheFormChainsTheFields(): void
    {
        $crawler = $this->client->request('GET', '/o/venue/new');
        self::assertResponseIsSuccessful();

        $municipality = $crawler->filter('[data-gv-field="municipality"]');
        self::assertSame('gridview-dependent-select', $municipality->attr('data-controller'));
        self::assertSame('province', $municipality->attr('data-gridview-dependent-select-field-value'));
        self::assertSame('filter[province]', $municipality->attr('data-gridview-dependent-select-param-value'));
        self::assertSame('/o/municipality/lookup', $municipality->attr('data-gridview-dependent-select-url-value'));
        self::assertNull($crawler->filter('[data-gv-field="region"]')->attr('data-controller'));

        // A new venue has no province yet: the municipality lists nothing until one is chosen.
        self::assertStringContainsString('filter%5Bprovince%5D=', (string) $municipality->filter('select')->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));

        // The options come from the lookup (AJAX): post the values as the browser would.
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $this->client->request('POST', '/o/venue/new', array_replace_recursive($values, ['record' => [
            'name' => 'Teatro', 'region' => $this->ids['lazio'], 'province' => $this->ids['mi'],
        ]]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.gv-form-row:has(#record_province) .gv-form-error', 'does not belong to the chosen region');
    }

    /**
     * @return list<string>
     */
    private function lookup(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['results']);

        return array_map(static fn (mixed $result): string => \is_array($result) && \is_string($result['text'] ?? null) ? $result['text'] : '', array_values($data['results']));
    }
}
