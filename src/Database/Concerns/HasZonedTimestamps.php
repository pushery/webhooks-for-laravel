<?php

declare(strict_types=1);

namespace Webhooks\Database\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Webhooks\Support\Timestamp;

/**
 * Writes and reads this model's timestamps as INSTANTS, not as wall-clock strings.
 *
 * Eloquent's default date format ("Y-m-d H:i:s") is naive, so the value it binds is
 * resolved against a DATABASE setting (PostgreSQL's session time zone), unrelated to
 * app.timezone. The moment an application runs on a non-UTC timezone the two disagree
 * and every row is stored at the wrong instant; worse, during the DST fall-back hour
 * two instants an hour apart format to the identical naive string and collapse onto one
 * created_at, corrupting the log's chronology and every window that reads it.
 *
 * PostgreSQL columns are timestamptz, so binding the offset with the value removes the
 * ambiguity in both directions. MySQL's DATETIME(6) cannot store an offset, so the value
 * is instead always written and read as UTC and only shifted to app.timezone for display —
 * which makes the instant exact regardless of the MySQL session zone, by construction.
 *
 * @internal
 */
trait HasZonedTimestamps
{
    /**
     * The date format Eloquent binds this model's timestamps with: an explicit offset, so an
     * equality lookup against a stored created_at still matches. On MySQL the read and write
     * are handled by asDateTime/fromDateTime below (which store and parse UTC-naive directly),
     * so this format is used there only for JSON serialization, where the offset is harmless.
     */
    public function getDateFormat(): string
    {
        return Timestamp::ELOQUENT_FORMAT;
    }

    /**
     * Format a value for storage. On MySQL the instant is converted to UTC first, so a
     * naive DATETIME(6) column always holds UTC whatever timezone the value arrived in;
     * on PostgreSQL the offset-bearing format already carries the instant, so the parent
     * behaviour is exact.
     */
    public function fromDateTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if ($this->onMySql()) {
            return Timestamp::mysql($this->asDateTime($value));
        }

        return parent::fromDateTime($value);
    }

    /**
     * Return a stored timestamp in the application's timezone. On PostgreSQL the stored
     * value carries its offset, so the instant is exact whatever the session zone is. On
     * MySQL the stored value is UTC-naive, so it is parsed as UTC before being shifted —
     * never resolved against the PHP default zone, which is what the parent would do.
     *
     * Typed CarbonInterface, not Illuminate\Support\Carbon: an application may pin the date
     * class to CarbonImmutable (Date::use(CarbonImmutable::class)), and Eloquent then hands
     * back a CarbonImmutable — narrowing the return to the mutable class made every timestamp
     * read throw a TypeError there. CarbonInterface is the honest contract.
     */
    protected function asDateTime(mixed $value): CarbonInterface
    {
        $appZone = Config::string('app.timezone', 'UTC');

        if ($this->onMySql() && is_string($value)) {
            return Date::parse($value, 'UTC')->setTimezone($appZone);
        }

        return parent::asDateTime($value)->setTimezone($appZone);
    }

    private function onMySql(): bool
    {
        return $this->getConnection()->getDriverName() === 'mysql';
    }
}
