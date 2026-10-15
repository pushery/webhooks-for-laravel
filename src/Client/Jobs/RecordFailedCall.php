<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Client\Jobs;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\Events\JobFailed;
use Pushery\Webhooks\Client\WebhookCallStatus;

/**
 * Records a stored call as failed once the queue gives up on the job that handles it. It listens
 * for the queue's JobFailed, which the queue raises after the job's own failed() has run, so the
 * record no longer depends on a handler that overrides failed() calling the parent's.
 *
 * Only jobs that extend ProcessWebhookJob are read, by the class name the queue stores beside the
 * job. The job itself is restored the way the queue restores it to call failed(): unserialized,
 * and decrypted first when it implements ShouldBeEncrypted. The encrypter is resolved only for
 * such a job, because this listener hears every failed job of the application.
 *
 * @internal
 */
final readonly class RecordFailedCall
{
    public function __construct(private Container $container) {}

    public function handle(JobFailed $event): void
    {
        $data = $event->job->payload()['data'] ?? null;

        if (! is_array($data)) {
            return;
        }

        $name = $data['commandName'] ?? null;

        if (! is_string($name) || ! is_a($name, ProcessWebhookJob::class, true)) {
            return;
        }

        $job = $this->restore($data['command'] ?? null);

        if ($job instanceof ProcessWebhookJob) {
            RecordCallOutcome::record($job->webhookCall, WebhookCallStatus::Failed);
        }
    }

    private function restore(mixed $stored): mixed
    {
        if (! is_string($stored)) {
            return null;
        }

        try {
            $serialized = str_starts_with($stored, 'O:')
                ? $stored
                : $this->container->make(Encrypter::class)->decrypt($stored);
            $job = is_string($serialized) ? unserialize($serialized) : null;
        } catch (DecryptException|ModelNotFoundException) {
            // A job the key in use cannot open, or one whose stored call is gone: nothing is left
            // to record.
            return null;
        }

        return $job;
    }
}
