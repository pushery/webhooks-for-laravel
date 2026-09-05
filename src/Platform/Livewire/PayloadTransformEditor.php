<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Pushery\Webhooks\Models\WebhookSubscription;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\Platform\Support\PortalRoutes;
use Pushery\Webhooks\Platform\Transform\DeclarativePayloadTransformer;
use Pushery\Webhooks\Platform\Transform\PayloadVersionRegistry;

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

    /**
     * The three rule lists, typed as what a public Livewire property CAN hold.
     *
     * These read `array<int, string>` and `array<int, array{from: string, to: string}>` until
     * 2026-08-27, and the narrower type was not a description but a wish. Livewire enforces the
     * array type of a public array property and nothing whatever about its elements, so the browser
     * decides what is in here. Under the narrow annotation static analysis then called the element
     * checks in buildRules() redundant, which is how a property the client writes came to look like
     * a property the type system guarded.
     *
     * EndpointForm::storedEventTypes() states the same fact for its own case, in the same words: a
     * public property "may hold anything at all, including a nested array".
     *
     * @var array<array-key, mixed>
     */
    public array $includeFields = [];

    /** @var array<array-key, mixed> */
    public array $excludeFields = [];

    /** @var array<array-key, mixed> */
    public array $renamePairs = [];

    public string $rewrapKey = '';

    /** The editable sample payload, as JSON, the preview is computed from. */
    public string $sampleJson = '';

    /**
     * Bumped on every live edit so the preview's aria-live status region re-renders and a screen
     * reader is told the output recomputed — a region whose text never changes is never announced.
     */
    public int $previewRevision = 0;

    public function mount(WebhookSubscription $subscription): void
    {
        // Route-model binding resolves the {subscription} segment UNSCOPED, so re-resolve it
        // through the owner-scoped query: a foreign id then fails not-found (404) BEFORE the
        // policy, exactly as every other panel does — otherwise a foreign-but-existing id 403s
        // while a non-existent one 404s, letting a tenant enumerate which ids exist. The policy
        // stays the second, defense-in-depth guard.
        // Neither line below can be the thing that refuses, for two different and already-known
        // reasons.
        //
        // The (int) cast: $subscription->id is an int already, so it changes nothing at run time.
        // It stays because findOwnedEndpoint() is typed for an int and this is the boundary where
        // that is made true.
        //
        // The authorize(): it cannot be the sole refusal, which InteractsWithEndpoints spells out
        // in full — the boot gate reads the same ability, and findOwnedEndpoint() has already
        // enforced the ownership the policy would add. That docblock ends "Do not 'kill' them by
        // deleting them", and this is one of the five it means.
        $subscription = $this->findOwnedEndpoint((int) $subscription->id);
        $this->authorize('update', $subscription);

        $this->endpointId = (int) $subscription->id;
        $this->endpointUrl = $subscription->url;
        $this->payloadVersion = $subscription->payload_version ?? '';

        $this->hydrateRules($subscription->transform ?? []);

        $this->sampleJson = $this->defaultSampleJson();
    }

    /**
     * Any live edit (a rule field, the sample) recomputes the preview; bump the revision so the
     * status region's key changes and the "preview updated" announcement actually fires.
     */
    public function updated(): void
    {
        $this->previewRevision++;
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

        // The only write in this component, and the only one in the package that had no
        // validate() at all. Two things got through without it. `payloadVersion` is a public
        // property, so the browser writes it straight onto a `varchar(20)` column — the same
        // hazard the URL field in the endpoint form caps at 2048, with a comment about MySQL's
        // 1406 error. Past 20 characters this is a 500 (Postgres 22001, MySQL 1406) where the
        // reader should be getting a field message. And a rule list is a public ARRAY: Livewire
        // enforces the array, never its elements, so an entry that is not a string reached
        // trim() under strict_types and raised there.
        //
        // Deliberately NOT Rule::in over the declared versions. PayloadVersionRegistry says in
        // its own words that an unknown version is a supported state — "a version may exist
        // purely to stamp its id with no field changes" — so constraining the field to the
        // configured set would narrow documented behavior to fix a column width. The width is
        // what is wrong here, so the width is what is bounded.
        // Eight of these rules cannot fire, and they stay anyway -- measured one at a time, the
        // suite is green without each of them. The five top-level properties are DECLARED
        // `string` and `array`, so PHP makes those two rules true before validation reads them,
        // whatever the caller. What is genuinely enforced here is everything under a `.*`:
        // nothing checks the type of an array ELEMENT, which is the shape that used to reach
        // trim() and raise there.
        //
        // They stay because the set is read as a whole -- somebody looking for what
        // `includeFields` accepts should find a line about `includeFields` rather than have to
        // infer it from an absence -- and because widening one of those declared types later
        // would leave the field guarded here instead of silently open.
        $this->validate([
            'payloadVersion' => ['string', 'max:20'],
            'includeFields' => ['array'],
            'includeFields.*' => ['string', 'max:255'],
            'excludeFields' => ['array'],
            'excludeFields.*' => ['string', 'max:255'],
            'renamePairs' => ['array'],
            'renamePairs.*.from' => ['string', 'max:255'],
            'renamePairs.*.to' => ['string', 'max:255'],
            'rewrapKey' => ['string', 'max:255'],
        ]);

        $this->rejectNestedPaths();

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
        $rules = $this->buildRules();

        // Resolve rules EXACTLY as the delivery path does (WebhookManager::transformFor):
        // an endpoint with no explicit per-endpoint transform inherits the selected version's
        // default rule set from the registry. save() stores an empty rule set as a null
        // transform, so empty controls always mean "inherit the version" — the preview must
        // resolve the same way, or it shows a body the endpoint would never actually receive.
        if ($rules === []) {
            $rules = Container::getInstance()->make(PayloadVersionRegistry::class)
                ->rulesFor($this->selectedVersion());
        }

        return Container::getInstance()->make(DeclarativePayloadTransformer::class)
            ->transform($this->sampleArray(), $rules, $this->selectedVersion());
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

        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
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
            // Same read-path reasoning as cleanList(), plus the keys: a pair the browser sends
            // without a `from` raised "Undefined array key" before it ever reached trim().
            if (! is_array($pair) || ! is_string($pair['from'] ?? null) || ! is_string($pair['to'] ?? null)) {
                continue;
            }

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
     * Refuse a field name that looks like a nested path.
     *
     * The rule engine matches against the payload's TOP-LEVEL keys only, so `customer.email`
     * matches nothing and is a no-op -- an exact no-op, with no message, no log line and no
     * visible difference in the preview beside it. On the one screen where a tenant configures
     * which customer data leaves the building, silence is the wrong answer: the reader believes
     * the email is being dropped while it is being delivered.
     *
     * Refused rather than supported, which is a decision about the rule model rather than about
     * effort. Making a dotted path work would give `include` and `exclude` a semantics that
     * `rename` and `rewrap` do not have, and a rule set where two of four rules understand
     * nesting is harder to reason about than one where none of them do. What was missing is
     * that the model says so.
     */
    private function rejectNestedPaths(): void
    {
        $nested = [];

        foreach (['includeFields', 'excludeFields'] as $property) {
            foreach ($this->{$property} as $index => $value) {
                if (is_string($value) && str_contains($value, '.')) {
                    $nested[$property.'.'.$index] = trans('webhooks::self-service.validation.nested_field', ['field' => $value]);
                }
            }
        }

        foreach ($this->renamePairs as $index => $pair) {
            foreach (['from', 'to'] as $side) {
                $value = is_array($pair) ? ($pair[$side] ?? null) : null;

                if (is_string($value) && str_contains($value, '.')) {
                    $nested['renamePairs.'.$index.'.'.$side] = trans('webhooks::self-service.validation.nested_field', ['field' => $value]);
                }
            }
        }

        if ($nested !== []) {
            throw ValidationException::withMessages($nested);
        }
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
        if ($this->payloadVersion !== '') {
            return $this->payloadVersion;
        }

        return null;
    }

    /**
     * Trim and drop blank entries from a list of field names, keeping it a clean list.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            // is_string, because this runs on the READ path too. render() calls preview() which
            // calls buildRules(), so a non-string element does not wait for a save to raise — it
            // takes the editor down on load and keeps it down until the state is reset. The
            // validation in save() protects the write; only this protects the render.
            if (! is_string($value)) {
                continue;
            }

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

        // This comparison cannot fail. `json_encode` returns string|false, so comparing against
        // true never matches and the ternary simply yields the encoded value, which is identical
        // behavior for every input that encodes. The two differ only when encoding fails, and what
        // reaches here is an array this component itself validated as JSON.
        //
        // It stays as a type net rather than as behavior: the method returns string, and without
        // it a failed encode would return false from a string-typed method.
        return $encoded === false ? '{}' : $encoded;
    }

    public function render(): View
    {
        /** @var array<string, mixed> $versions */
        $versions = Config::array('webhooks.platform.payload_versioning.versions', []);

        // The cast on the key below changes nothing: PHP normalizes a numeric string key to an int
        // on the way in. The cast on the value is not in that position and an existing arm goes red
        // without it, which is what makes this a note about the key rather than about the line.
        $versionOptions = ['' => __('webhooks::self-service.transform.version_none')];
        foreach (array_keys($versions) as $version) {
            $versionOptions[(string) $version] = (string) $version;
        }

        return ViewFactory::make('webhooks::self-service.livewire.payload-transform-editor', [
            'versionOptions' => $versionOptions,
            'versioningEnabled' => Config::boolean('webhooks.platform.payload_versioning.enabled', false),
            // Null when the portal's own pages are not mounted: there is nowhere to go back to.
            'portalUrl' => PortalRoutes::url('webhooks.self-service'),
            'inputJson' => $this->toJson($this->sampleArray()),
            'outputJson' => $this->toJson($this->preview()),
        ]);
    }
}
