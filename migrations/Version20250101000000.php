<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notifications and transactional Outbox tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id UUID NOT NULL,
                destinations JSONB NOT NULL,
                template VARCHAR(128) NOT NULL,
                variables JSONB NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_outbox (
                id UUID NOT NULL,
                aggregate_type VARCHAR(64) NOT NULL,
                aggregate_id UUID NOT NULL,
                event_type VARCHAR(128) NOT NULL,
                payload JSONB NOT NULL,
                occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                status VARCHAR(16) NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                lock_token UUID DEFAULT NULL,
                published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT chk_outbox_status
                    CHECK (status IN ('pending', 'processing', 'published', 'dead')),
                CONSTRAINT chk_outbox_attempts
                    CHECK (attempts >= 0)
            )
            SQL);

        $this->addSql(
            'CREATE INDEX idx_outbox_pending '
            .'ON notification_outbox (status, available_at, occurred_at)',
        );

        $this->addSql(
            'CREATE INDEX idx_outbox_leases '
            .'ON notification_outbox (status, locked_until)',
        );

        $this->addSql(
            'CREATE INDEX idx_outbox_aggregate '
            .'ON notification_outbox (aggregate_type, aggregate_id)',
        );

        $this->addSql(
            'CREATE INDEX idx_notifications_status '
            .'ON notifications (status, created_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_outbox');
        $this->addSql('DROP TABLE notifications');
    }
}
