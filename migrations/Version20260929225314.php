<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929225314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the agent_run and agent_step tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE agent_run_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE agent_step_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE agent_run (workspace VARCHAR(190) NOT NULL, task TEXT NOT NULL, model VARCHAR(120) DEFAULT NULL, status VARCHAR(32) NOT NULL, stop_reason VARCHAR(40) DEFAULT NULL, final_message TEXT DEFAULT NULL, error TEXT DEFAULT NULL, iteration_count INT DEFAULT 0 NOT NULL, tool_call_count INT DEFAULT 0 NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, changed_files JSON DEFAULT \'[]\' NOT NULL, limits JSON DEFAULT \'[]\' NOT NULL, cancellation_requested BOOLEAN DEFAULT false NOT NULL, id INT NOT NULL, uuid UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, user_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AC4401AAD17F50A6 ON agent_run (uuid)');
        $this->addSql('CREATE INDEX IDX_AC4401AAA76ED395 ON agent_run (user_id)');
        $this->addSql('CREATE TABLE agent_step (sequence INT NOT NULL, type VARCHAR(20) NOT NULL, tool_name VARCHAR(60) DEFAULT NULL, tool_input JSON DEFAULT NULL, result_summary TEXT DEFAULT NULL, message TEXT DEFAULT NULL, success BOOLEAN DEFAULT true NOT NULL, duration_ms DOUBLE PRECISION DEFAULT NULL, id INT NOT NULL, uuid UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, run_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EE2244DFD17F50A6 ON agent_step (uuid)');
        $this->addSql('CREATE INDEX IDX_EE2244DF84E3FEC4 ON agent_step (run_id)');
        $this->addSql('ALTER TABLE agent_run ADD CONSTRAINT FK_AC4401AAA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_step ADD CONSTRAINT FK_EE2244DF84E3FEC4 FOREIGN KEY (run_id) REFERENCES agent_run (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE agent_run_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE agent_step_id_seq CASCADE');
        $this->addSql('ALTER TABLE agent_run DROP CONSTRAINT FK_AC4401AAA76ED395');
        $this->addSql('ALTER TABLE agent_step DROP CONSTRAINT FK_EE2244DF84E3FEC4');
        $this->addSql('DROP TABLE agent_run');
        $this->addSql('DROP TABLE agent_step');
    }
}
