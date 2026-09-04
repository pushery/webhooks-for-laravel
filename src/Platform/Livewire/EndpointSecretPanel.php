<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Platform\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Pushery\Webhooks\Platform\Livewire\Concerns\InteractsWithEndpoints;
use Pushery\Webhooks\WebhookManager;

/**
 * Reveal and rotate an endpoint's signing secret. Opened by a reveal-secret event, it
 * shows the current secret (and, during a rotation window, the previous one) for a
 * bounded time — secret_reveal_ttl seconds — after which the value is withheld again.
 * The expiry is enforced server-side by the visibleSecret guard, so a stale panel can
 * never keep leaking the secret; an Alpine timer mirrors it by auto-hiding in the
 * browser.
 *
 * Both actions re-resolve the endpoint through the owner-scoped query and re-authorize
 * the row-level policy, so a tenant can only ever reveal or rotate a secret it owns.
 */
final class EndpointSecretPanel extends Component
{
    use InteractsWithEndpoints;

    /**
     * Every value the reveal window is decided from, and each one is #[Locked].
     *
     * The class docblock says the expiry is "enforced server-side by the visibleSecret guard,
     * so a stale panel can never keep leaking the secret". The guard is isExpired(), isExpired()
     * reads $expiresAt, and $expiresAt was an ordinary public property — which means the browser
     * wrote the value the guard compared against. A window enforced by a number the client picks
     * is not enforced. #[Locked] is what makes the sentence true: Livewire refuses an incoming
     * update to these and raises rather than accepting it.
     *
     * The two secrets and the endpoint are locked for a different reason, and it is not
     * confidentiality — a revealed secret is already in that browser. It is that reveal() and
     * rotate() resolve the endpoint through the owner-scoped query and authorize the row
     * policy; a client-writable endpointId would let the next request act on an id that never
     * went through either. $hidden stays writable: it is the one value here the browser
     * legitimately owns, and it decides nothing but an announcement.
     */
    #[Locked]
    public ?int $endpointId = null;

    #[Locked]
    public ?string $endpointUrl = null;

    #[Locked]
    public ?string $currentSecret = null;

    #[Locked]
    public ?string $previousSecret = null;

    /** Unix timestamp after which the revealed secret is withheld again. */
    #[Locked]
    public ?int $expiresAt = null;

    /**
     * Whether the last reveal window has just closed. Drives a persistent live region
     * so a screen reader is told the secret was withdrawn even though the panel that
     * showed it is gone from the DOM.
     */
    public bool $hidden = false;

    #[On('reveal-secret')]
    public function reveal(int $id): void
    {
        $subscription = $this->findOwnedEndpoint($id);
        $this->authorize('view', $subscription);

        $this->endpointId = $subscription->id;
        $this->endpointUrl = $subscription->url;
        $this->currentSecret = $subscription->secret;
        $this->previousSecret = $subscription->previous_secret;
        $this->expiresAt = now()->addSeconds($this->secretRevealTtl())->getTimestamp();
        $this->hidden = false;
    }

    /**
     * Generate a new current secret through the manager, keeping the old one as the
     * verify-only rotation secret so a consumer can migrate at its leisure. The new
     * secret is revealed once, subject to the same TTL.
     */
    public function rotate(): void
    {
        if ($this->endpointId === null) {
            return;
        }

        $subscription = $this->findOwnedEndpoint($this->endpointId);
        $this->authorize('rotateSecret', $subscription);

        $newSecret = Container::getInstance()->make(WebhookManager::class)->rotateSecret($subscription);

        $this->currentSecret = $newSecret;
        $this->previousSecret = $subscription->previous_secret;
        $this->expiresAt = now()->addSeconds($this->secretRevealTtl())->getTimestamp();
        $this->hidden = false;

        $this->dispatch('secret-rotated');
        $this->dispatch('wirekit-toast', variant: 'success', message: __('webhooks::self-service.toast.secret_rotated'));
    }

    public function hide(): void
    {
        $this->reset(['endpointId', 'endpointUrl', 'currentSecret', 'previousSecret', 'expiresAt']);
        $this->hidden = true;
    }

    /**
     * Once the reveal window has elapsed, drop the plaintext secrets from the
     * component state on the very next request. The render guard alone only stops the
     * value being shown — the raw props would otherwise linger on the server instance
     * and keep being serialized into the client snapshot; nulling them here retracts
     * the plaintext entirely rather than merely hiding it.
     */
    public function hydrate(): void
    {
        if ($this->isExpired()) {
            $this->currentSecret = null;
            $this->previousSecret = null;
        }
    }

    /**
     * Whether the reveal window has elapsed. A panel with no reveal in flight is not
     * considered expired (there is simply nothing to show).
     */
    private function isExpired(): bool
    {
        return $this->expiresAt !== null && now()->getTimestamp() >= $this->expiresAt;
    }

    /**
     * The current secret while the reveal window is open, or null once it elapses —
     * the single server-side guard the view reads, so the secret is never rendered
     * past its TTL even if the client timer never fired.
     */
    #[Computed]
    public function visibleCurrentSecret(): ?string
    {
        return $this->isExpired() ? null : $this->currentSecret;
    }

    /**
     * The previous (rotation-window) secret while the reveal window is open, or null.
     */
    #[Computed]
    public function visiblePreviousSecret(): ?string
    {
        return $this->isExpired() ? null : $this->previousSecret;
    }

    /**
     * Remaining seconds in the reveal window, for the client-side auto-hide timer.
     */
    public function remainingSeconds(): int
    {
        // Removing this guard looks like it would be a TypeError and is not: PHP coerces the
        // null to 0, and `max(0, 0 - timestamp)` answers 0 — the same number. So no test can
        // tell the two versions apart.
        //
        // It stays because the guard states the case rather than relying on a coercion: "no
        // window open" is a state this panel is in for most of its life, and reading it out of
        // arithmetic on null is how a later edit turns it into a negative countdown.
        if ($this->expiresAt === null) {
            return 0;
        }

        return max(0, $this->expiresAt - now()->getTimestamp());
    }

    public function render(): View
    {
        return ViewFactory::make('webhooks::self-service.livewire.endpoint-secret-panel');
    }
}
