<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter as FrameworkEncrypter;
use Illuminate\Support\Facades\Config;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Server\Data\WebhookDeliveryData;
use Pushery\Webhooks\Server\Models\WebhookOutboxEntry;
use Pushery\Webhooks\Support\WebhookConnection;

/**
 * Encrypts every value this package keeps at rest under the application key again, under the
 * current key: the signing secrets of the endpoints and the deliveries waiting in the outbox.
 *
 * Laravel decrypts a value encrypted under a key listed in `app.previous_keys`, but it encrypts
 * under the current key only what it writes, and an endpoint's secret is written when it is
 * rotated and at no other time. Until every value has been written again, the previous key cannot
 * leave the list: without it, an endpoint that was not rotated in between can no longer sign, and
 * an outbox entry can no longer be sent.
 *
 * Each row is read again under a lock, in a transaction of its own on the package's connection,
 * so a secret rotated meanwhile is not overwritten with the old one. Only the encrypted columns
 * are written: the values decrypt to the same bytes as before, no model event fires and
 * `updated_at` keeps its moment. A value that decrypts with the current key alone is left as it
 * is, so a second run writes nothing and its count says whether anything is left under a previous
 * key. Only the framework's encrypter can be asked which key a value is under; with an encrypter
 * of the host's own, every value is written again on every run.
 *
 * @internal
 */
final class ReencryptCommand extends Command
{
    protected $signature = 'webhooks:reencrypt';

    protected $description = 'Encrypt the endpoint secrets and the outbox entries again under the current APP_KEY, so a previous key can be removed.';

    public function handle(Encrypter $encrypter): int
    {
        $failed = false;

        if (Config::boolean('webhooks.platform.enabled', true)) {
            // The `encrypted` cast encrypts with the encrypter a host set through
            // Model::encryptUsing(), and with the application's otherwise.
            $failed = $this->summarize(
                'Endpoint secrets',
                'endpoints',
                $this->reencryptSecrets(Model::$encrypter ?? $encrypter),
                'without that key those secrets are lost, and the endpoints have to be registered again',
            );
        } else {
            $this->info('The Platform layer is switched off (webhooks.platform.enabled), so there are no endpoint secrets to encrypt again.');
        }

        if (Config::boolean('webhooks.server.outbox.enabled', false)) {
            $failed = $this->summarize(
                'Outbox entries',
                'entries',
                $this->reencryptOutbox($encrypter),
                'without that key those entries can never be sent',
            ) || $failed;
        } else {
            $this->info('The transactional outbox is switched off (webhooks.server.outbox.enabled), so there are no outbox entries to encrypt again.');
        }

        $this->line('Deliveries already in the queue carry values sealed under the key they were queued with: keep the previous key in APP_PREVIOUS_KEYS until they have been sent.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{again: int, already: int, unreadable: list<string>}
     */
    private function reencryptSecrets(Encrypter $encrypter): array
    {
        return $this->eachRow(WebhookSubscription::model(), function (WebhookSubscription $subscription) use ($encrypter): array {
            $changes = [];

            foreach (['secret', 'previous_secret'] as $column) {
                $sealed = $subscription->getRawOriginal($column);
                $again = is_string($sealed) ? $this->sealedAgain($sealed, $encrypter) : null;

                if ($again !== null) {
                    $changes[$column] = $again;
                }
            }

            return $changes;
        });
    }

    /**
     * An entry is sealed twice: the whole delivery, and inside it the signing secrets, the proxy
     * and the client certificate's passphrase. Each layer is asked on its own, because the relay
     * may have written the outer one again under the current key while the inner ones kept the
     * key the entry was recorded with.
     *
     * @return array{again: int, already: int, unreadable: list<string>}
     */
    private function reencryptOutbox(Encrypter $encrypter): array
    {
        return $this->eachRow(WebhookOutboxEntry::model(), function (WebhookOutboxEntry $entry) use ($encrypter): array {
            $sealed = $entry->getRawOriginal('delivery');
            $data = $entry->deliveryData();

            if (! is_string($sealed) || ! $data instanceof WebhookDeliveryData) {
                throw new DecryptException('The delivery of this outbox entry cannot be decrypted.');
            }

            $changed = ! $this->isUnderTheCurrentKey($sealed, $encrypter);

            $resealed = $data->resealed(function (string $value) use ($encrypter, &$changed): string {
                $again = $this->sealedAgain($value, $encrypter);

                if ($again === null) {
                    return $value;
                }

                $changed = true;

                return $again;
            });

            if ($changed) {
                return ['delivery' => $encrypter->encrypt($resealed)];
            }

            return [];
        });
    }

    /**
     * Walks every row of $model's table and writes the columns $reseal returns for it, each row
     * read again under a lock in a transaction of its own.
     *
     * The global scopes of a host's subclass are left out: a row such a scope hides holds its
     * values under the previous key all the same, and they are lost with that key.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(TModel): array<string, string>  $reseal  the encrypted columns to write, none when every value is under the current key
     * @return array{again: int, already: int, unreadable: list<string>}
     */
    private function eachRow(string $model, Closure $reseal): array
    {
        /** @var array{again: int, already: int, unreadable: list<string>} $tally */
        $tally = ['again' => 0, 'already' => 0, 'unreadable' => []];

        $model::query()
            ->withoutGlobalScopes()
            ->select(new $model()->getKeyName())
            ->eachById(function (Model $row) use ($model, $reseal, &$tally): void {
                $key = $row->getKey();

                try {
                    $written = WebhookConnection::db()->transaction(static function () use ($model, $key, $reseal): ?bool {
                        $locked = $model::query()->withoutGlobalScopes()->whereKey($key)->lockForUpdate()->first();

                        // Deleted since the walk read its key: nothing left to encrypt.
                        if ($locked === null) {
                            return null;
                        }

                        $changes = $reseal($locked);

                        if ($changes === []) {
                            return false;
                        }

                        // Past the model on purpose: an update through it would stamp updated_at
                        // and fire the model's events for a row whose values did not change.
                        $model::query()->withoutGlobalScopes()->whereKey($key)->toBase()->update($changes);

                        return true;
                    });
                } catch (DecryptException) {
                    // The key, never the value: what could not be decrypted is a secret.
                    $tally['unreadable'][] = is_scalar($key) ? (string) $key : 'unknown';

                    return;
                }

                if ($written === true) {
                    $tally['again']++;
                } elseif ($written === false) {
                    $tally['already']++;
                }
            });

        return $tally;
    }

    /**
     * $sealed encrypted again under the current key, or null when it already is.
     *
     * @throws DecryptException when no configured key decrypts it
     */
    private function sealedAgain(string $sealed, Encrypter $encrypter): ?string
    {
        if ($this->isUnderTheCurrentKey($sealed, $encrypter)) {
            return null;
        }

        // Without unserializing: the bytes inside are written back exactly as they were.
        return $encrypter->encrypt($encrypter->decrypt($sealed, false), false);
    }

    private function isUnderTheCurrentKey(string $sealed, Encrypter $encrypter): bool
    {
        if (! $encrypter instanceof FrameworkEncrypter) {
            return false;
        }

        try {
            (clone $encrypter)->previousKeys([])->decrypt($sealed, false);
        } catch (DecryptException) {
            return false;
        }

        return true;
    }

    /**
     * Writes what one kind of row came to.
     *
     * @param  array{again: int, already: int, unreadable: list<string>}  $tally
     * @return bool whether a row could not be decrypted
     */
    private function summarize(string $kind, string $rows, array $tally, string $consequence): bool
    {
        $this->info(sprintf('%s: %d encrypted again under the current key, %d already under it.', $kind, $tally['again'], $tally['already']));

        if ($tally['unreadable'] === []) {
            return false;
        }

        $this->error(sprintf(
            '%s: %d cannot be decrypted with APP_KEY or any key in APP_PREVIOUS_KEYS (%s %s). '
            .'Add the key they were encrypted under to APP_PREVIOUS_KEYS and run this again; %s.',
            $kind,
            count($tally['unreadable']),
            $rows,
            implode(', ', $tally['unreadable']),
            $consequence,
        ));

        return true;
    }
}
