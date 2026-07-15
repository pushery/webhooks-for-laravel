<?php

declare(strict_types=1);

namespace Webhooks\Platform\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Webhooks\Models\WebhookSubscription;
use Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Webhooks\Platform\Transform\DeclarativePayloadTransformer;

/**
 * A structured editor for one endpoint's payload version and declarative transform.
 * Instead of hand-editing raw JSON rules, the tenant builds the mapping from typed
 * controls — an include allow-list, an exclude deny-list, rename pairs and an optional
 * rewrap key — and picks a known payload version (or none). A sample payload the tenant
 * can edit is run through the exact delivery-time transformer on every change, so the
 * live preview shows precisely the body an endpoint would receive.
 *
 * The endpoint is loaded and authorized against the row-level policy on mount and again
 * on save, so a tenant can only ever edit a transform for an endpoint it owns. Saving
 * persists the rules and version onto the subscription; while payload versioning is
 * disabled the edit is still stored, it simply does not reshape deliveries until the
 * feature is switched on.
 */
#[Layout('webhooks::self-service.layout')]
final class PayloadTransformEditor extends Component
{
    use InteractsWithEndpoints;

    public ?int $endpointId = null;

    public ?string $endpointUrl = null;

    public string $payloadVersion = '';

    /** @var array<int, string> */
    public array $includeFields = [];

    /** @var array<int, string> */
    public array $excludeFields = [];

    /** @var array<int, array{from: string, to: string}> */
    public array $renamePairs = [];

    public string $rewrapKey = '';

    /** The editable sample payload, as JSON, the preview is computed from. */
    public string $sampleJson = '';

    public function mount(WebhookSubscription $subscription): void
    {
        $this->authorize('update', $subscription);

        $this->endpointId = (int) $subscription->id;
        $this->endpointUrl = $subscription->url;
        $this->payloadVersion = $subscription->payload_version ?? '';

        $this->hydrateRules($subscription->transform ?? []);

        $this->sampleJson = $this->defaultSampleJson();
    }

    public function addIncludeField(): void
    {
        $this->includeFields[] = '';
    }

    public function removeIncludeField(int $index): void
    {
        unset($this->includeFields[$index]);
        $this->includeFields = array_values($this->includeFields);
    }

    public function addExcludeField(): void
    {
        $this->excludeFields[] = '';
    }

    public function removeExcludeField(int $index): void
    {
        unset($this->excludeFields[$index]);
        $this->excludeFields = array_values($this->excludeFields);
    }

    public function addRenamePair(): void
    {
        $this->renamePairs[] = ['from' => '', 'to' => ''];
    }

    public function removeRenamePair(int $index): void
    {
        unset($this->renamePairs[$index]);
        $this->renamePairs = array_values($this->renamePairs);
    }

    /**
     * Persist the built rules and chosen version onto the owned subscription. An empty
     * rule set clears the stored transform; an empty version clears the stamped version.
     */
    public function save(): void
    {
        // Scope at the query, not only at the policy: a tampered endpointId resolves to nothing and
        // fails not-found before the save runs — the row-level policy below is the second guard.
        $subscription = $this->findOwnedEndpoint((int) $this->endpointId);
        $this->authorize('update', $subscription);

        $rules = $this->buildRules();

        $subscription->transform = $rules === [] ? null : $rules;
        $subscription->payload_version = $this->payloadVersion !== '' ? $this->payloadVersion : null;
        $subscription->save();

        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.transform_saved'));
    }

    /**
     * The transformed body the current rules and version produce for the sample, run
     * through the exact declarative transformer the delivery path uses — so the preview
     * is the real output, never an approximation. Exposed as a plain method (not a
     * computed) so it can also be read directly in a test.
     *
     * @return array<array-key, mixed>
     */
    public function preview(): array
    {
        return Container::getInstance()->make(DeclarativePayloadTransformer::class)
            ->transform($this->sampleArray(), $this->buildRules(), $this->selectedVersion());
    }

    /**
     * The sample payload decoded to an array, or an empty array when the JSON in the
     * editor is not currently a valid object.
     *
     * @return array<array-key, mixed>
     */
    public function sampleArray(): array
    {
        $decoded = json_decode($this->sampleJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The error the sample field shows while its JSON cannot be read, or null while it
     * can. Without it the only signal a malformed sample gives is both preview panes
     * quietly collapsing to an empty object — which reads as "my transform rules broke
     * the payload" rather than "my sample has a stray comma". An empty field is not an
     * error: it is simply nothing to preview yet.
     */
    public function sampleError(): ?string
    {
        if (trim($this->sampleJson) === '') {
            return null;
        }

        $decoded = json_decode($this->sampleJson, true);

        if (is_array($decoded)) {
            return null;
        }

        // A scalar (`42`, `"x"`, `true`) is valid JSON but not a payload the transformer
        // can reshape, so it is refused with the same sentence as unparsable text.
        return __('webhooks::self-service.transform.invalid_json');
    }

    /**
     * Assemble the declarative rule set from the typed controls, in the transformer's
     * own fixed order. A rule section is omitted entirely when it is empty, so an empty
     * include never accidentally strips the whole payload.
     *
     * @return array<string, mixed>
     */
    public function buildRules(): array
    {
        $rules = [];

        $include = $this->cleanList($this->includeFields);
        if ($include !== []) {
            $rules['include'] = $include;
        }

        $exclude = $this->cleanList($this->excludeFields);
        if ($exclude !== []) {
            $rules['exclude'] = $exclude;
        }

        $rename = [];
        foreach ($this->renamePairs as $pair) {
            $from = trim($pair['from']);
            $to = trim($pair['to']);
            if ($from !== '' && $to !== '') {
                $rename[$from] = $to;
            }
        }
        if ($rename !== []) {
            $rules['rename'] = $rename;
        }

        $rewrap = trim($this->rewrapKey);
        if ($rewrap !== '') {
            $rules['rewrap'] = $rewrap;
        }

        return $rules;
    }

    /**
     * Populate the typed controls from a stored transform rule set.
     *
     * @param  array<array-key, mixed>  $transform
     */
    private function hydrateRules(array $transform): void
    {
        $this->includeFields = $this->stringList($transform['include'] ?? []);
        $this->excludeFields = $this->stringList($transform['exclude'] ?? []);

        $rename = $transform['rename'] ?? [];
        if (is_array($rename)) {
            foreach ($rename as $from => $to) {
                if (is_string($to)) {
                    $this->renamePairs[] = ['from' => (string) $from, 'to' => $to];
                }
            }
        }

        $rewrap = $transform['rewrap'] ?? null;
        $this->rewrapKey = is_string($rewrap) ? $rewrap : '';
    }

    /**
     * The chosen version to stamp, or null when none is selected.
     */
    private function selectedVersion(): ?string
    {
        return $this->payloadVersion !== '' ? $this->payloadVersion : null;
    }

    /**
     * Trim and drop blank entries from a list of field names, keeping it a clean list.
     *
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }

        return $clean;
    }

    /**
     * Extract only the string entries from a raw rule value as a clean list.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $names[] = $item;
            }
        }

        return $names;
    }

    private function defaultSampleJson(): string
    {
        return $this->toJson([
            'invoice_id' => 'in_123',
            'amount' => 4200,
            'currency' => 'eur',
            'internal_note' => 'do not send downstream',
            'customer' => ['id' => 42, 'email' => 'jane@example.com'],
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function toJson(array $value): string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    public function render(): View
    {
        /** @var array<string, mixed> $versions */
        $versions = Config::array('webhooks.platform.payload_versioning.versions', []);

        $versionOptions = ['' => __('webhooks::self-service.transform.version_none')];
        foreach (array_keys($versions) as $version) {
            $versionOptions[(string) $version] = (string) $version;
        }

        return ViewFactory::make('webhooks::self-service.livewire.payload-transform-editor', [
            'versionOptions' => $versionOptions,
            'versioningEnabled' => Config::boolean('webhooks.platform.payload_versioning.enabled', false),
            'inputJson' => $this->toJson($this->sampleArray()),
            'outputJson' => $this->toJson($this->preview()),
        ]);
    }
}
