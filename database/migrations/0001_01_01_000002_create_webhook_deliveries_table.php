<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Webhooks\Database\PartitionManager;

return new class extends Migration
{
    public function up(): void
    {
        // Range-partitioned by month: the delivery log grows fast, so old data is
        // dropped a partition at a time (webhooks:partition-maintenance) instead of
        // with an expensive DELETE. A partitioned table's primary key must include
        // the partition key, hence (id, created_at).
        DB::statement(<<<'SQL'
            CREATE TABLE webhook_deliveries (
                id uuid NOT NULL,
                subscription_id bigint NOT NULL,
                event_type varchar(255) NOT NULL,
                event_id uuid NOT NULL,
                payload jsonb NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempt integer NOT NULL DEFAULT 0,
                response_code integer NULL,
                response_ms integer NULL,
                error text NULL,
                created_at timestamp(0) without time zone NOT NULL,
                delivered_at timestamp(0) without time zone NULL,
                CONSTRAINT webhook_deliveries_pkey PRIMARY KEY (id, created_at),
                CONSTRAINT webhook_deliveries_subscription_id_foreign
                    FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions (id) ON DELETE CASCADE
            ) PARTITION BY RANGE (created_at)
            SQL);

        // Retry/report scans only ever look at open rows; a partial index keeps
        // them tiny even as succeeded rows pile up.
        DB::statement("CREATE INDEX webhook_deliveries_open_idx ON webhook_deliveries (subscription_id, created_at) WHERE status IN ('pending', 'failed')");
        // Redelivery and idempotency look a delivery up by its subscription + event.
        DB::statement('CREATE INDEX webhook_deliveries_sub_event_idx ON webhook_deliveries (subscription_id, event_id)');

        $partitions = new PartitionManager;
        $partitions->ensureDefaultPartition();
        // Cover the previous month through three months ahead so inserts always land
        // in a real partition; the maintenance command keeps the window rolling.
        $partitions->ensureWindow(CarbonImmutable::now()->startOfMonth()->subMonth(), 4);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS webhook_deliveries CASCADE');
    }
};
