<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Database\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Pushery\Webhooks\Enums\DeliveryStatus;

/**
 * A status write that cannot land on a finished delivery.
 *
 * A queue delivers a job at least once. When an attempt outlives the queue's visibility timeout
 * or `retry_after`, a second worker takes the same delivery, and both then write a status. A
 * guard that reads the row first and writes after leaves a gap in which the other worker's write
 * can land, so a success could be overwritten by its duplicate's failure. Here the guard is part
 * of the UPDATE, scoped to the row exactly as the model's own `save()` is, so no second write can
 * come between the two.
 *
 * @internal
 *
 * @phpstan-require-extends Model
 */
trait SavesUnlessTerminal
{
    /**
     * Save the changed attributes unless the row has reached a terminal status, and say whether
     * they were saved.
     *
     * It fires the model events `save()` fires for an update, in the same order, so an observer a
     * host attaches to a replaced model sees the same writes. A write the guard turns away fires
     * no `updated` and no `saved`, because nothing was saved.
     */
    public function saveUnlessTerminal(): bool
    {
        if (! $this->exists) {
            throw new LogicException(static::class.'::saveUnlessTerminal() writes a row that exists, and this one has not been inserted.');
        }

        $this->mergeAttributesFromCachedCasts();

        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if ($this->isDirty() && ! $this->updateUnlessTerminal()) {
            return false;
        }

        $this->finishSave([]);

        return true;
    }

    /**
     * `performUpdate()`, with the guard in the WHERE and the answer read from the row count.
     */
    private function updateUnlessTerminal(): bool
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $terminal = array_map(
            static fn (DeliveryStatus $status): string => $status->value,
            array_filter(DeliveryStatus::cases(), static fn (DeliveryStatus $status): bool => $status->isTerminal()),
        );

        $written = $this->setKeysForSaveQuery($this->newModelQuery())
            ->whereNotIn('status', $terminal)
            ->update($this->getDirtyForUpdate());

        if ($written === 0) {
            return false;
        }

        $this->syncChanges();
        $this->fireModelEvent('updated', false);

        return true;
    }
}
