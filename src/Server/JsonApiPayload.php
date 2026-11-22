<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Server;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Date;

/**
 * The payload of an event about one resource, in the shape of the API that serves it.
 *
 * The envelope is the one Standard Webhooks recommends: the event's `type`, the moment it happened as `timestamp`, and
 * `data`. `data` holds the resource object a `JsonApiResource` answers under `data` in the API, with its `type`, `id`,
 * `attributes` and, where the resource has them, `relationships`, `links` and `meta`, so a client reads a resource in a
 * webhook with the code it reads it with in the API. The envelope is no JSON:API document: `data` is the resource
 * object itself, and the delivery is sent as `application/json`.
 *
 * The resource is resolved without the request of the moment, so neither an `include` nor a sparse fieldset that
 * request asked for changes what a webhook carries.
 */
final class JsonApiPayload
{
    /**
     * @return array{type: string, timestamp: string, data: mixed}
     */
    public static function for(string $type, JsonApiResource $resource, ?DateTimeInterface $at = null): array
    {
        return [
            'type' => $type,
            'timestamp' => Date::instance($at ?? Date::now())->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'data' => $resource->resolve(Request::create('/'))['data'],
        ];
    }
}
