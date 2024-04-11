<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240411115721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE label (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE label_artist (label_id INT NOT NULL, artist_id INT NOT NULL, INDEX IDX_E673A53633B92F39 (label_id), INDEX IDX_E673A536B7970CF8 (artist_id), PRIMARY KEY(label_id, artist_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE label_artist ADD CONSTRAINT FK_E673A53633B92F39 FOREIGN KEY (label_id) REFERENCES label (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE label_artist ADD CONSTRAINT FK_E673A536B7970CF8 FOREIGN KEY (artist_id) REFERENCES artist (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE label_artist DROP FOREIGN KEY FK_E673A53633B92F39');
        $this->addSql('ALTER TABLE label_artist DROP FOREIGN KEY FK_E673A536B7970CF8');
        $this->addSql('DROP TABLE label');
        $this->addSql('DROP TABLE label_artist');
    }
}
