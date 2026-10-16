<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server;

use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Events\WebhookAttemptsExhausted;
use Pushery\Webhooks\Server\Exceptions\OrderedDeliveryUnavailable;
use Pushery\Webhooks\Server\Jobs\CallWebhookJob;
use Pushery\Webhooks\Server\Streams\StreamLedger;
use Pushery\Webhooks\Support\Settings;
use Pushery\Webhooks\Testing\FakedWebhook;
use Pushery\Webhooks\Testing\WebhookFake;

/**
 * What an application does with a delivery that ended without success.
 *
 * Every such delivery fires {@see WebhookAttemptsExhausted}, which carries its data. A delivery
 * sent with {@see PendingWebhook::orderedBy()} also keeps its place in its stream, and the
 * deliveries behind it wait, until it is sent again and succeeds or is skipped. A listener on
 * that event is the usual place to decide: resend a delivery whose time to keep trying ran out,
 * skip one the endpoint refused for good.
 *
 * Resolve it from the container, or construct it; it holds no state of its own.
 */
final readonly class DeliveryStreams
{
    public function __construct(
        private StreamLedger $ledger = new StreamLedger,
    ) {}

    /**
     * Queue the delivery again, under the same message id, with no attempt counted and its whole
     * time to keep trying ahead of it. An ordered delivery goes out from the place it holds in
     * its stream, or joins the end of the stream again if it was skipped. The job goes onto the
     * queue and connection the Server layer is configured with.
     *
     * @throws OrderedDeliveryUnavailable when the delivery is ordered and ordered delivery cannot run here
     */
    public function resend(WebhookDeliveryData $data): WebhookDeliveryData
    {
        $settings = new Settings;
        $queue = $settings->queue();
        $connection = $settings->connection();
        $restarted = $data->restarted();
        $stream = $restarted->stream();

        if ($stream !== null) {
            StreamLedger::assertCanOrder($connection, $queue);
        }

        $fake = WebhookFake::active();

        if ($fake instanceof WebhookFake) {
            $fake->record(FakedWebhook::sentThroughTheServer($restarted));

            return $restarted;
        }

        $job = new CallWebhookJob($restarted);
        $job->onQueue($queue);
        $job->onConnection($connection);

        if ($stream === null) {
            dispatch($job);
        } else {
            StreamLedger::queueInStream($stream, $restarted->messageId, static function () use ($job): void {
                dispatch($job);
            });
        }

        return $restarted;
    }

    /**
     * Take an ordered delivery out of its stream, so the deliveries behind it go out. One that
     * is still waiting for its turn is not sent at all. A delivery without an ordering key is
     * left as it is.
     */
    public function skip(WebhookDeliveryData $data): void
    {
        $stream = $data->stream();

        if ($stream !== null) {
            $this->ledger->leave($stream, $data->messageId);
        }
    }
}
