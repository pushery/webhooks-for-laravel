<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webhooks\Database\PostgresRequirement;

return new class extends Migration
{
    public function up(): void
    {
        PostgresRequirement::ensure($this->getConnection());

        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('name')->nullable();
            $table->text('url');
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->jsonb('event_types');
            $table->boolean('is_active')->default(true);
            $table->timestamp('disabled_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamps();
        });

        // GIN index accelerates the fan-out lookup "which subscriptions listen for
        // event type X" (WHERE event_types @> '["X"]'). jsonb_path_ops is the
        // smallest, fastest operator class for pure containment queries.
        DB::statement('CREATE INDEX webhook_subscriptions_event_types_gin ON webhook_subscriptions USING gin (event_types jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
