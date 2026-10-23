<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Application\Command\ConfigureObjectLabelCommand;
use App\Module\Metadata\Application\Command\ConfigureObjectSearchCommand;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Handler\ConfigureObjectLabelHandler;
use App\Module\Metadata\Application\Handler\ConfigureObjectSearchHandler;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesAccountObject;
use Doctrine\DBAL\Connection;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;
use Fedale\GridviewBundle\DataSource\SortOrder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The richer field types through the record pipeline, against the real database.
 */
final class FieldTypesInRecordsTest extends KernelTestCase
{
    use CreatesAccountObject;

    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        self::createAccountObject(self::getContainer());
        $this->records = self::getContainer()->get(RecordManagerInterface::class);
    }

    public function testAPasswordIsStoredHashedAndNeverReadBack(): void
    {
        $account = $this->records->create(new RecordInput('account', ['username' => 'mario', 'password' => 'secret123']));

        self::assertArrayNotHasKey('password', $account->values);
        $hash = $this->storedPassword($account->id);
        self::assertNotSame('secret123', $hash);
        self::assertTrue(password_verify('secret123', $hash));

        // Left empty on update, the password is kept; given, it is replaced.
        $this->records->update($account->id, new RecordInput('account', ['username' => 'mario.rossi', 'password' => '']));
        self::assertSame($hash, $this->storedPassword($account->id));
        $this->records->update($account->id, new RecordInput('account', ['password' => 'another456']));
        self::assertTrue(password_verify('another456', $this->storedPassword($account->id)));
    }

    public function testThePasswordRulesApplyToThePlainValue(): void
    {
        try {
            $this->records->create(new RecordInput('account', ['username' => 'mario', 'password' => 'nodigits']));
            self::fail('A password without a digit must be refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['password' => ['The password must contain a digit.']], $e->errors);
        }

        $this->expectException(RecordValidationFailed::class);
        $this->records->create(new RecordInput('account', ['username' => 'anna']));
    }

    public function testFieldsNotGivenTakeTheirDefault(): void
    {
        $account = $this->records->create(new RecordInput('account', ['username' => 'mario', 'password' => 'secret123']));

        self::assertTrue($account->get('active'));
        self::assertNull($account->get('gender'));
    }

    public function testPatternUrlAndPhoneAreChecked(): void
    {
        try {
            $this->records->create(new RecordInput('account', [
                'username' => 'mario', 'password' => 'secret123', 'zipcode' => '2012', 'website' => 'not a link', 'phone' => 'call me',
            ]));
            self::fail('Invalid values must be refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['A postal code has five digits.'], $e->errors['zipcode']);
            self::assertSame(['This value is not a valid URL.'], $e->errors['website']);
            self::assertContains('This value is not a valid phone number.', $e->errors['phone']);
        }

        $account = $this->records->create(new RecordInput('account', [
            'username' => 'mario', 'password' => 'secret123', 'zipcode' => '00184', 'website' => 'https://example.com', 'phone' => '+39 333  123 4567',
        ]));
        self::assertSame('00184', $account->get('zipcode'));
        self::assertSame('+39 333 123 4567', $account->get('phone'));
    }

    public function testTheGridCannotReadAPassword(): void
    {
        $this->records->create(new RecordInput('account', ['username' => 'mario', 'password' => 'secret123']));
        $source = self::getContainer()->get(RecordGridSourceInterface::class);

        $rows = iterator_to_array($source->fetch(new GridQuery(source: ['object' => 'account'])), false);
        self::assertArrayNotHasKey('password', $rows[0]);

        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($source->fetch(new GridQuery(source: ['object' => 'account'], sort: [new SortOrder('password', SortDirection::Asc)])));
    }

    public function testAPasswordCannotBeUniqueSearchedOrNameTheRecords(): void
    {
        $refusals = [
            fn () => (self::getContainer()->get(CreateFieldHandler::class))(new CreateFieldCommand('account', 'pin', 'password', 'PIN', isUnique: true)),
            fn () => (self::getContainer()->get(ConfigureObjectSearchHandler::class))(new ConfigureObjectSearchCommand('account', null, ['username', 'password'])),
            fn () => (self::getContainer()->get(ConfigureObjectLabelHandler::class))(new ConfigureObjectLabelCommand('account', 'password')),
        ];
        foreach ($refusals as $refusal) {
            try {
                $refusal();
                self::fail('A write-only field must be refused.');
            } catch (MetadataException $e) {
                self::assertStringContainsString('is write-only', $e->getMessage());
            }
        }
    }

    private function storedPassword(string $id): string
    {
        $hash = self::getContainer()->get(Connection::class)->fetchOne('SELECT password FROM data.c_account WHERE id = ?', [$id]);
        self::assertIsString($hash);

        return $hash;
    }
}
