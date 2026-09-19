<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Pushery\WireKit\WireKitServiceProvider;

/**
 * Which rendering of the two operator screens a host gets, without publishing anything.
 *
 * The package ships the same two components twice — neutral Tailwind markup under
 * `resources/views/livewire`, WireKit markup under `resources/views/wirekit`. Until this existed
 * the only way to reach the second was `vendor:publish --tag=webhooks-ui-wirekit`, and a publish
 * takes the views out of the update path: the host then owns a copy that has to be kept
 * byte-identical to the package or a fix lands in one and not the other.
 *
 * And the failure is an unstyled screen, which is not an error. A consumer kept those copies for
 * exactly this reason, deleted them when a release brought accessibility fixes they wanted, and the
 * screen fell back to the neutral rendering. Nothing threw, nothing logged, no test went red — a
 * view rendered, and it was the wrong one. That is why the choice belongs in config rather than in
 * a publish.
 *
 * @internal
 */
final class UiVariant
{
    /**
     * The view name for one of the two operator components.
     */
    public static function view(string $component): string
    {
        $neutral = 'webhooks::livewire.'.$component;

        // A published view always wins, and this is the compatibility line. Both publish tags land
        // at resources/views/vendor/webhooks/livewire, so a host that published either of them —
        // including the WireKit one, before this config existed — has its copy under the neutral
        // name. Rendering the package's WireKit view by name would walk straight past it and
        // silently discard their customizations, which is the same class of failure this class
        // exists to end, aimed at the people who did the work.
        if (self::isPublished($component)) {
            return $neutral;
        }

        return self::rendersWireKit() ? 'webhooks::wirekit.'.$component : $neutral;
    }

    /**
     * The view Livewire's paginationView() should return for a length-aware paginator.
     *
     * The package shipped its own pager because neither alternative worked: Livewire's built-in
     * view paints a raw palette no design token reaches and carries a hardcoded English landmark,
     * and WireKit's `pagination` could only page by navigating — every control was a real link, so
     * a click reloaded the document and discarded any component state that was not in the URL. On
     * a filtered table that is not cosmetic: page two came back unfiltered.
     *
     * WireKit 2.51 closed that (`livewire` on the component, plus the two views to point at), so
     * on the WireKit rendering the package's own pager is now a second copy of a capability the
     * dependency has — which is the thing a package is meant to remove from its consumers, not
     * keep. It is adopted here rather than in six components, because the question "which pager"
     * has exactly the same answer as "which markup", and asking it twice is how the two drift.
     *
     * Note that the neutral rendering keeps the package's own pager, and that is not a leftover. WireKit
     * is optional here — `require-dev` and `conflict`, never `require` — so the neutral screens
     * render on hosts where no `<x-wirekit::…>` tag resolves at all. `webhooks::pagination` works
     * there because it is hand-written markup over design TOKENS, which are CSS custom properties
     * and need no package. Pointing it at a `wirekit::` view would turn an optional dependency
     * into a required one for anything that paginates.
     */
    public static function paginationView(): string
    {
        return self::rendersWireKit() ? 'wirekit::pagination.livewire' : 'webhooks::pagination';
    }

    /**
     * The view Livewire's paginationSimpleView() should return.
     *
     * Livewire resolves the two independently, so a component that overrides only the first keeps
     * the built-in view for `simplePaginate()` — the defect `webhooks::pagination` documents in its
     * own header. Both seams therefore exist here, and every paginating component sets both.
     *
     * The neutral side answers with the SAME view for both, exactly as it does today: one view
     * reads the two things a simple paginator cannot answer defensively rather than having a second
     * copy that drifts. The WireKit side does not need that, because the two views it ships are
     * three lines each and differ only in the variant they ask the component for — `mini`, which is
     * previous-and-next, which is all a simple or cursor paginator can drive anyway.
     */
    public static function simplePaginationView(): string
    {
        return self::rendersWireKit() ? 'wirekit::pagination.livewire-simple' : 'webhooks::pagination';
    }

    /**
     * Whether what resolves for this component is something OTHER than the package's own view.
     *
     * Asked as "not ours" rather than as "under the publish path", and the difference is whose
     * override gets respected. `vendor:publish` is the common way a host takes a view over, and it
     * was the only way this recognized, but it is not the only way there is. A host can prepend
     * their own namespace hint from a service provider, and a package that ships view overrides
     * does exactly that. Under the narrower question those hosts were told their override did not
     * count, and the package rendered its own WireKit markup straight over it.
     *
     * The broader question also makes the arm that proves this isolatable: what matters is that the
     * resolution is not ours, so a test may put its override anywhere.
     *
     * Both sides are normalized, and skipping that inverts the answer. The provider registers its
     * own views as `__DIR__.'/../resources/views'` and the finder hands that string back unchanged,
     * so a plain prefix test against the package root reads every view as an override and the whole
     * setting silently does nothing — measured, on the first version of this.
     *
     * The `?:` fallbacks are in assignment position on purpose: neither realpath can fail on a tree
     * that resolved a view at all, and a branch for that would be one nothing can enter.
     */
    private static function isPublished(string $component): bool
    {
        $shippedRoot = dirname(__DIR__, 2).'/resources/views';
        $shipped = realpath($shippedRoot) ?: $shippedRoot;

        $found = View::getFinder()->find('webhooks::livewire.'.$component);

        return ! str_starts_with(realpath($found) ?: $found, $shipped);
    }

    /**
     * Whether the WireKit rendering is the one to use.
     *
     * `auto` asks whether WireKit is REGISTERED, not merely installed, and the difference is
     * the honest one: a library present in the vendor tree but kept out of discovery cannot
     * render an `<x-wirekit::…>` tag, so choosing its markup would produce the unstyled
     * screen this class exists to prevent — with the config now claiming otherwise.
     *
     * There is deliberately NO version comparison here. `composer.json` already refuses
     * `pushery/wirekit <2.53` outright, so a resolvable install is a tested one, and the
     * check happens where a version problem can still be fixed. A second copy of the floor
     * would drift from the constraint and would fail at render time, on a screen.
     *
     * The number is written out in prose here, which makes it the half that rots, and it has, more
     * than once. `WirekitFloorContractTest` holds every statement of it to one constant, this
     * docblock included, because the alternative is a sentence that reads as authoritative while
     * naming a version the package stopped requiring weeks ago.
     */
    private static function rendersWireKit(): bool
    {
        // Read null-safe rather than through Config::string(), and the difference is an exception
        // on a shipped screen. The key is `env('WEBHOOKS_UI_VARIANT', 'auto')`, and a host who
        // switches a setting off the way one switches a flag off — `WEBHOOKS_UI_VARIANT=false` —
        // hands env() the boolean false. The typed getter then throws "must be a string, boolean
        // given" and takes both operator screens down, which is the opposite of what this method
        // promises two lines below. Measured against the real repository: a missing key returns the
        // default, a present non-string throws. That asymmetry is the trap — the value is not
        // absent, it is present and of the wrong type, which is the one state a default cannot
        // cover.
        $configured = Config::get('webhooks.ui.variant', 'auto');

        return match (is_string($configured) ? $configured : 'auto') {
            'wirekit' => true,
            'plain' => false,
            // Anything else, an unreadable value included, is `auto`. A typo that threw would
            // take down an operator screen over a styling preference.
            default => class_exists(WireKitServiceProvider::class)
                && app()->getProvider(WireKitServiceProvider::class) !== null,
        };
    }
}
