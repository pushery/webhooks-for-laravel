<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\WebhookManager;

/**
 * Fired when a webhook endpoint is registered, naming WHO registered it.
 *
 * The package's other events answer "what happened to a delivery". This one answers a
 * question an audit asks instead: which human did this, and when. The two do not overlap —
 * a delivery log can tell you an endpoint has been receiving events for a month and still
 * not tell you who pointed it at that URL.
 *
 * The package writes no audit trail of its own and takes no position on where one belongs;
 * listen for this and write it wherever yours lives.
 *
 * The actor is null whenever there is no authenticated user — a console command, a seeder,
 * a queued job, a host calling {@see WebhookManager::subscribe()} from its own
 * service layer. Null means "not a person acting through a session", which is information
 * rather than an absence of it: those registrations are exactly the ones an audit should
 * not attribute to whoever happens to be logged in.
 */
final readonly class WebhookEndpointRegistered
{
    public function __construct(
        public WebhookSubscription $subscription,
        public ?Authenticatable $actor,
    ) {}
}
