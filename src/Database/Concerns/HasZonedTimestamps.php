<?php

declare(strict_types=1);

namespace Webhooks\Database\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Webhooks\Support\Timestamp;

/**
 * Writes and reads this model's timestamps as INSTANTS, not as wall-clock strings.
 *
 * Every timestamp column in this package is timestamptz. Eloquent's default date
 * format ("Y-m-d H:i:s") is naive, so the value it binds is resolved by PostgreSQL
 * against the SESSION time zone — which is a database setting, unrelated to
 * app.timezone. The moment an application runs on a non-UTC timezone the two disagree
 * and every row is stored at the wrong instant; worse, during the DST fall-back hour
 * two instants an hour apart format to the identical naive string and collapse onto
 * one created_at, corrupting the log's chronology and every window that reads it.
 *
 * Binding the offset with the value removes the ambiguity in both directions: the
 * write carries the instant it means, and the read hands back that instant, rendered
 * in the application's own timezone so the dashboard still shows local times.
 *
 * @internal
 */
trait HasZonedTimestamps
{
    /**
     * The date format Eloquent binds this model's timestamps with: an explicit offset.
     */
    public function getDateFormat(): string
    {
        return Timestamp::ELOQUENT_FORMAT;
    }

    /**
     * Return a stored timestamp in the application's timezone. The value read from
     * PostgreSQL carries its offset, so the instant is exact whatever the session zone
     * is; shifting it to app.timezone is a presentation step, not a correction.
     */
    protected function asDateTime(mixed $value): Carbon
    {
        return parent::asDateTime($value)->setTimezone(Config::string('app.timezone', 'UTC'));
    }
}
