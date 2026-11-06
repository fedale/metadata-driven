<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106124015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The public key an index of templates is signed with; none for an index accepted unsigned';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.template_source ADD public_key VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.template_source DROP public_key');
    }
}
