<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Webhooks\Database\PartitionManager;
use Webhooks\Database\PostgresRequirement;

return new class extends Migration
{
    public function up(): void
    {
        PostgresRequirement::ensure($this->getConnection());

        // Range-partitioned by month: the delivery log grows fast, so old data is
        // dropped a partition at a time (webhooks:partition-maintenance) instead of
        // with an expensive DELETE. A partitioned table's primary key must include
        // the partition key, hence (id, created_at). Timestamps are timestamptz so
        // the dashboard can bucket created_at with date_bin over a fixed zone. The
        // owner columns are denormalized from the subscription so the dashboard can
        // scope deliveries per owner without a join, and payload_type is a stored
        // generated column mirroring the payload's own "type" field for cheap reads.
        // An over-sized payload can be offloaded to a Storage disk: the row then keeps
        // only payload_disk/payload_path plus the body's sha256, and the payload
        // column holds a compact stub.
        DB::statement(<<<'SQL'
            CREATE TABLE webhook_deliveries (
                id uuid NOT NULL,
                subscription_id bigint NOT NULL,
                owner_type varchar(255) NULL,
                owner_id bigint NULL,
                event_type varchar(255) NOT NULL,
                event_id uuid NOT NULL,
                payload jsonb NOT NULL,
                payload_type text GENERATED ALWAYS AS (payload->>'type') STORED,
                payload_disk varchar(255) NULL,
                payload_path varchar(255) NULL,
                body_sha256 char(64) NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempt integer NOT NULL DEFAULT 0,
                response_code integer NULL,
                duration_ms integer NULL,
                error text NULL,
                created_at timestamptz NOT NULL,
                delivered_at timestamptz NULL,
                CONSTRAINT webhook_deliveries_pkey PRIMARY KEY (id, created_at),
                CONSTRAINT webhook_deliveries_subscription_id_foreign
                    FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions (id) ON DELETE CASCADE
            ) PARTITION BY RANGE (created_at)
            SQL);

        // Retry/report scans only ever look at open rows; a partial index keeps
        // them tiny even as succeeded rows pile up.
        DB::statement("CREATE INDEX webhook_deliveries_open_idx ON webhook_deliveries (subscription_id, created_at) WHERE status IN ('pending', 'failed')");
        // The health/scoring aggregate range-scans one subscription's recent history
        // across ALL statuses, so it needs a NON-partial (subscription_id, created_at)
        // index: the partial open index above cannot serve an all-status read, and
        // without this the planner falls back to the event index below and scans the
        // endpoint's entire history to filter the window.
        DB::statement('CREATE INDEX webhook_deliveries_sub_created_idx ON webhook_deliveries (subscription_id, created_at)');
        // Redelivery and idempotency look a delivery up by its subscription + event.
        DB::statement('CREATE INDEX webhook_deliveries_sub_event_idx ON webhook_deliveries (subscription_id, event_id)');
        // The dashboard reads a single tenant's delivery history newest-first, always
        // filtering the WHOLE (owner_type, owner_id) morph pair, so the index leads
        // with both owner columns before created_at — leaving owner_type as a heap
        // filter would interleave a second owner type that shares an owner_id.
        DB::statement('CREATE INDEX webhook_deliveries_owner_idx ON webhook_deliveries (owner_type, owner_id, created_at)');

        $partitions = new PartitionManager;
        $partitions->ensureDefaultPartition();
        // Cover the previous month through three months ahead so inserts always land
        // in a real partition; the maintenance command keeps the window rolling. The
        // partition key is a timestamptz, so the months are UTC months — anchoring them
        // to a local calendar would shift every bound by the local offset and, since
        // that offset moves twice a year, leave a gap or an overlap between two
        // adjacent partitions.
        $partitions->ensureWindow(CarbonImmutable::now('UTC')->startOfMonth()->subMonth(), 4);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS webhook_deliveries CASCADE');
    }
};
