<?php

declare(strict_types=1);

namespace App\Migrations\Landlord;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260306134638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE plan CHANGE free_credit_amount free_credit_amount NUMERIC(10, 2) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE school CHANGE wallet_balance wallet_balance NUMERIC(12, 2) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE plan CHANGE free_credit_amount free_credit_amount NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE school CHANGE wallet_balance wallet_balance NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL');
    }
}
