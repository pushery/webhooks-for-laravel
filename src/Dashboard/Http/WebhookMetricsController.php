<?php

declare(strict_types=1);

namespace Webhooks\Dashboard\Http;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Webhooks\Dashboard\DashboardScope;
use Webhooks\Dashboard\Data\KpiSet;
use Webhooks\Dashboard\Metrics\WebhookMetrics;
use Webhooks\Dashboard\WindowResolver;

/**
 * The optional, read-only JSON metrics endpoint behind webhooks.dashboard.expose_json_api.
 * It serves the SAME read model the Livewire panels render — the hourly rollup and the
 * window-level latency percentiles — so a host can drive its own charts, a status page or
 * an alerting rule from the numbers the dashboard shows.
 *
 * It is aggregates only. The recent-delivery queue, request/response bodies, headers and
 * signing material are deliberately NOT exposed: nothing here can leak a payload or a
 * secret, and the response carries no row-level delivery data at all.
 *
 * Every read is scoped to the acting tenant's WHOLE morph pair via DashboardScope, exactly
 * as the panels scope theirs — two tenants sharing an owner_id under different owner types
 * are different tenants and never see each other's numbers. The route also carries the
 * dashboard's own middleware stack, and the view-webhook-dashboard gate is re-checked here
 * so the endpoint stays authorized even if a host swaps that stack out.
 *
 * The window parameter is validated against the configured allow-list; an unknown window is
 * a 422 rather than a silent fall back to a default the caller did not ask for. Validation
 * is done here rather than through a FormRequest on purpose: the group runs the host's own
 * (web-session) middleware, where a failed form request answers a browser-shaped request
 * with a 302 redirect. A JSON endpoint must answer every client the same way, so the check
 * is explicit and always renders the 422 envelope.
 *
 * @internal
 */
final class WebhookMetricsController
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('view-webhook-dashboard');

        $windows = WindowResolver::allowed();
        $window = $this->window($request, $windows);

        if ($window === null) {
            return $this->unsupportedWindow($windows);
        }

        $metrics = $this->metricsFor($window);

        // The latencies and the retry rate are floats and must stay floats on the wire:
        // without JSON_PRESERVE_ZERO_FRACTION a whole-numbered 20.0 ms serializes as 20, and
        // a typed client would see the field flip between integer and float from one window
        // to the next.
        return new JsonResponse([
            'window' => $window,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'kpis' => $this->kpis($metrics->kpis()),
            'hourly' => $this->hourly($metrics),
            'top_events' => $this->topEvents($metrics),
        ], Response::HTTP_OK, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * The requested window token, or null when the caller asked for one the host does not
     * offer. Omitting the parameter selects the first configured window (24h by default).
     *
     * @param  non-empty-list<string>  $windows
     */
    private function window(Request $request, array $windows): ?string
    {
        $requested = $request->query('window');

        if ($requested === null) {
            return $windows[0];
        }

        return is_string($requested) && in_array($requested, $windows, true) ? $requested : null;
    }

    /**
     * The 422 for a window the host does not offer, naming the ones it does.
     *
     * @param  non-empty-list<string>  $windows
     */
    private function unsupportedWindow(array $windows): JsonResponse
    {
        $supported = ['windows' => implode(', ', $windows)];

        return new JsonResponse([
            'message' => __('webhooks::dashboard.api.unsupported_window', $supported),
            'errors' => [
                'window' => [__('webhooks::dashboard.api.invalid_window', $supported)],
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The metrics query object for the acting tenant over the given window — the same
     * construction the panels make through InteractsWithDashboard.
     */
    private function metricsFor(string $window): WebhookMetrics
    {
        return Container::getInstance()->make(WebhookMetrics::class, [
            'tenant' => DashboardScope::current(),
            'window' => WindowResolver::interval($window),
        ]);
    }

    /**
     * The window's KPIs: the additive counts, the derived retry rate, and the window-level
     * latency percentiles in milliseconds.
     *
     * @return array<string, float|int>
     */
    private function kpis(KpiSet $kpis): array
    {
        return [
            'total' => $kpis->total,
            'delivered' => $kpis->delivered,
            'pending' => $kpis->pending,
            'failed' => $kpis->failed,
            'retried' => $kpis->retried,
            'retry_rate' => $kpis->retryRate(),
            'p50_ms' => $kpis->p50,
            'p90_ms' => $kpis->p90,
            'p95_ms' => $kpis->p95,
            'p99_ms' => $kpis->p99,
        ];
    }

    /**
     * The hourly rollup buckets in the window, oldest first.
     *
     * @return array<int, array<string, float|int|string>>
     */
    private function hourly(WebhookMetrics $metrics): array
    {
        return $metrics->hourly()
            ->map(fn (stdClass $row): array => $this->bucket(get_object_vars($row)))
            ->all();
    }

    /**
     * One rollup bucket, with the raw database values coerced to stable JSON types.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, float|int|string>
     */
    private function bucket(array $row): array
    {
        return [
            'bucket' => $this->toIso($row['bucket'] ?? null),
            'total' => $this->toInt($row['total'] ?? 0),
            'delivered' => $this->toInt($row['delivered'] ?? 0),
            'pending' => $this->toInt($row['pending'] ?? 0),
            'failed' => $this->toInt($row['failed'] ?? 0),
            'retried' => $this->toInt($row['retried'] ?? 0),
            'p50_ms' => $this->toFloat($row['p50'] ?? 0),
            'p95_ms' => $this->toFloat($row['p95'] ?? 0),
        ];
    }

    /**
     * The busiest event types in the window. Event types are the caller's own catalog
     * names — never payload content.
     *
     * @return array<int, array{event_type: string, total: int}>
     */
    private function topEvents(WebhookMetrics $metrics): array
    {
        return $metrics->topEvents()
            ->map(function (stdClass $row): array {
                $columns = get_object_vars($row);

                return [
                    'event_type' => $this->toText($columns['event_type'] ?? null),
                    'total' => $this->toInt($columns['total'] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * A rollup bucket as a stable ISO-8601 timestamp. PostgreSQL hands the bucket back as
     * a string; anything else is not a timestamp this view can produce.
     */
    private function toIso(mixed $value): string
    {
        return is_string($value) ? CarbonImmutable::parse($value)->toIso8601String() : '';
    }

    /**
     * Coerce a raw database value (PostgreSQL returns numerics as strings) to int.
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Coerce a raw database value to float — an empty window yields a NULL percentile.
     */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Coerce a raw database value to a string column.
     */
    private function toText(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
