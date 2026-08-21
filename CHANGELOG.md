# Changelog

All notable changes to `pushery/webhooks-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-08-21

### Fixed

- **An event type read from a producer's header could lose the delivery it named.**
  `event_type` became host-configurable in 2.0.0 — `'header:X-GitHub-Event'`, a body path,
  or a resolver — and the column it lands in is `varchar(255)`. Nothing between the two
  applied a length.

  **The failure is a lost webhook, not a truncated string.** The row is written *after* the
  signature verifies and *before* the 2xx goes back, so an over-long value fails the insert,
  the request answers 500, and the producer retries into the same failure until its budget
  runs out. The delivery was authentic and accepted, and then it was gone.

  The value is now truncated to the column width — the same lossy-but-valid trade the stored
  payload already makes for NUL bytes. The exact bytes stay in the raw body, and a value that
  long routes to the catch-all either way, because no `process` map key is 255 characters
  long. **Nothing changes for an ordinary event type.**

- **The operator console's endpoint list is paginated.** It is unscoped by design — it reads
  every tenant's subscriptions, which is why it must sit behind an operator-only gate — and
  that means its size is the size of the installation. It asked for all of them at once, so
  opening one screen hydrated every endpoint anyone had ever registered. It now asks for one
  page of 25, the same way and the same size as the delivery log beside it, which has worked
  like that since 1.4.4.

  ⚠️ **If you published the console view, add `{{ $subscriptions->links() }}`** below the
  table — your copy still receives the full set otherwise, which is the trade a published
  view always makes.

- **Both operator console components can be subclassed again.** Three docblocks and the
  config reference offer the same two ways to authorize an action: set
  `webhooks.admin.ability`, *or* override `authorizeAction()` in a subclass. The second was
  impossible — `SubscriptionManager` and `DeliveryLog` were `final`, so the subclass was a
  fatal error.

  That mattered more than a documentation slip, because **the first way does not work on a
  host using spatie/laravel-permission**. That package installs a `Gate::before` hook which
  reads the first positional gate argument as a *guard* name and shifts it off the list. The
  action name travels in exactly that position, so `'create'` becomes the guard, the
  permission lookup asks for a guard nobody defined, and the check falls through to an
  ability that does not exist — a deny. The console then refuses every action to every
  operator, **silently**: nothing throws, nothing is logged, the form simply does nothing.

  Such a host had one route broken and the other barred. The classes are no longer `final`,
  so the documented override is real, and the incompatibility is now named at the config key
  and at the seam — including the one-line way through: declare an ability that asks the
  permission itself, since a closure written `fn ($user) => …` ignores an argument it does
  not accept.

  **If you relied on either class being `final`, nothing breaks** — removing it only widens
  what a host may do.

### Changed

- **`SubscriptionManager`'s docblock no longer offers an override it cannot honor**, and the
  delivery-log stub beside it says why it is not `final` either.

## [2.0.0] - 2026-08-21

### Changed

- **Every `return` whose fallback is a constant is now an early exit.** 37 of them across 22
  files, mechanically: `return is_numeric($v) ? (int) $v : 0;` became an `if` with the fallback
  on its own line. **No behavior changes** — the condition is not negated and the two arms are
  the same two arms, only as two statements.

  Why it is worth 22 files: line coverage records *was this line executed*, not *which arm ran*.
  On one line, the fallback counts as covered the first time anything takes the true arm, so it
  can go years unexercised under a green 100% floor. That matters most where mutation cannot
  help either — no mutator moves a `null` or `[]` fallback while leaving the true arm standing,
  so for those the floor is the only instrument there is, and one-lining them switched it off.

- **Both receiving events name their config instead of carrying it, and the refusal event no
  longer carries the request.** `InvalidWebhookSignature` now has `source`, `reason`, `ip`,
  `path` and `userAgent`; `UnreadableWebhookPayload` has `call`, `source` and `contentType`.
  `$event->source` is the client config's name, and `WebhookConfig::forName($event->source)`
  returns the whole config wherever you need it, queued listeners included.

  **Two defects closed at once, and both were silent.** A `WebhookConfig` holds the signing
  secret and the rotation secret in cleartext, so an event carrying one wrote `whsec_…` into any
  log that recorded it and into any queue payload that shipped it — copies no retention policy
  covers, and it needed no queue to happen. And a listener marked `ShouldQueue` on
  `InvalidWebhookSignature` turned **every forged, unsigned or expired request into a 500
  instead of a 401**, on the default configuration: Laravel serializes a job even on the `sync`
  driver, a request holds unserializable closures, and the throw landed before the line that
  answers 401. The listener never ran, so the rate-limiting it was queued for did not happen
  either — on the one path three docblocks promise is never a 500.

  **If you have a listener on either event**, replace `$event->config` with
  `WebhookConfig::forName($event->source)`, or read `$event->source` where you only wanted the
  name. On `InvalidWebhookSignature`, `$event->request` is gone: `ip`, `path` and `userAgent`
  are on the event, and a listener that needs the rest of the request can call `request()` —
  but must then not be queued.

- **The root namespace is now `Pushery\Webhooks\`.** It was `Webhooks\`, which made this the
  only package in the family without the shared prefix — `pushery/wirekit` is
  `Pushery\WireKit\`, `pushery/legal-consent` is `Pushery\LegalConsent\`. A consuming
  application wrote `Pushery\Webhooks\Enums\DeliveryStatus` from memory and got a
  class-not-found: right about the convention, wrong about this package.

  **Nothing else changes.** Every class keeps its name and its position, no configuration key
  moves, no behavior differs, and the Composer package name is still
  `pushery/webhooks-for-laravel`. For most applications the upgrade is a search and replace
  over `use Webhooks\` and `\Webhooks\`.

  **What that does not cover, in the order it matters:**

  1. **Drain the queue before deploying.** A queued job carries its own class name in its
     serialized payload, so any `CallWebhookJob` or `ProcessWebhookJob` still waiting when the
     new code goes live cannot be unserialized. Check `failed_jobs` too — a retry after the
     upgrade hits the same wall. This is the only step with a deadline.
  2. **Fix `bootstrap/providers.php` by hand — the search and replace does not reach it.** A
     provider list holds a **bare** class name, with no `use` and no leading backslash, so
     neither pattern matches. Four providers are auto-discovered and move on their own; the
     three opt-in ones you registered yourself do not (`WebhooksDashboardServiceProvider`,
     `SelfServicePortalServiceProvider`, `WebhooksUiServiceProvider`). Laravel resolves that
     list on every request, so a missed line is a fatal on boot rather than a feature that
     quietly stops working.
  3. **Re-publish, or fix the imports in, anything you published** — migrations and views hold
     `use Webhooks\…` in your application, not in ours. Livewire component aliases are plain
     strings and need no edit.
  4. **`config:clear`, `route:clear`, `optimize:clear`.** A cached configuration holds the
     resolved signature-scheme class; a cached route table holds the controller class.
  5. **Config values that name a package class** — `core.signing.scheme`,
     `client.configs.*.{scheme,profile,response,model,dedupe_id}`, `dashboard.source_model`. A
     class of your own in any of them is unaffected.

  **No database migration, and no stored row holds a package class name** — the package
  registers no morph map, `owner_type` holds *your* model, and no shipped migration writes a
  class name into a column. Deliveries, subscriptions and secrets all survive untouched.

  Full instructions: [Upgrading from 1.x](https://docs.pushery.com/webhooks-for-laravel/guides/upgrading-from-1x).

- **The security policy now covers `2.x`.** `1.x` is end of life as of this release.

- **`require` no longer lists `illuminate/*` components beside `laravel/framework`.** The
  four that were there — `console`, `contracts`, `database`, `support` — are all `replace`d
  by the framework at the same version, so they bought no resolution: removing them left the
  resolved dependency graph **bit-identical**, 76 runtime and 106 development packages, same
  names and same versions. **Nothing about what gets installed changes.**

  What they did buy was a second constraint per component to carry across the next Laravel
  major, whose failure message would point at `illuminate/support` rather than at the
  framework that had just moved — and a manifest asserting two postures at once, "I am lean,
  I name what I use" and "I need all of it", with no way for a reader to tell which holds.

  The framework is declared deliberately: the shipped tree makes 88 calls across 14 helpers
  that only `Illuminate\Foundation` declares, and Foundation ships exclusively inside the
  metapackage.

- **The delete action on both consoles is now `destroy()`.** Under a strict
  Content-Security-Policy, Livewire serves its CSP-safe bundle, which parses a `wire:` value
  with its own parser instead of handing it to the JS engine — `eval` is exactly what the
  policy forbids. `delete` is a **keyword** in that parser, so `wire:click="delete(1)"` read
  as the delete *operator* rather than a call to the method.

  It failed in the worst possible way: nothing threw, the page rendered, the button was
  there — and clicking it did nothing at all. No error, no log, no toast. An operator who
  clicked "delete" and saw no complaint concluded the endpoint was gone. It also blocked a
  consumer from adopting the operator console at all.

  **Nothing breaks.** `delete()` stays as a `@deprecated` forwarder, so a view you published
  before this release keeps working. ⚠️ **But that published copy was already broken under a
  strict CSP** — it still calls `delete(…)`, which still parses as the operator there.
  Re-publish the view, or change that one line to `destroy(…)`, to get the button back.

  The gate ability is **unchanged**: `admin.ability` still receives `'delete'`, so a host's
  authorization callback needs no edit. The forwarder deliberately carries no `@deprecated`
  tag either — on PHP 8.4 that becomes `#[\Deprecated]`, which raises a deprecation on every
  call, and a shim that fails the test suites of the hosts who have not migrated yet is worse
  than no shim.

  `CspSafeMethodNameTest` now holds the whole class, not this one name — `new`, `in`,
  `typeof` and `void` are plausible method names too. It reads the keyword list out of the
  shipped Livewire bundle rather than restating it, so a keyword added upstream arrives with
  the next `composer update`.

- **The KPI ribbon's loading skeleton no longer carries the `wh-dash-kpis` class**; it uses
  `wh-dash-kpis-placeholder`, matching the three other dashboard placeholders. A host that
  styled `.wh-dash-kpis` was styling both states without a way to tell them apart.

- **The two WireKit stubs now say that they are a bad pair on one page.** The delivery log's
  filter is a WireKit select bound with `wire:model.live`; the subscription manager ships the
  delete dialogs. Together on one screen, the dialog's destructive action stops being
  clickable — it opens, the click never lands, and nothing is logged. The package's own portal
  hits this and answers it with a native `<select>` carrying the same tokens. Both stubs now
  carry the warning and name the escape; neither renders anything different. Copy them onto
  one screen and you were reproducing a defect the portal already worked around.

- **The README badge row follows the shared layout used across the published packages.** It is
  now two rows — identity first (version, PHP range, Laravel range, license, all read live from
  Packagist), then the toolchain — and the singular "Laravel Version" label is corrected to the
  plural the rest of the family uses. A new badge names the databases the package is exercised
  against, **PostgreSQL and MySQL**; MariaDB is absent because the package rejects it outright,
  which the requirements section has always said in words.

### Added

- **`InboundWebhookVerified` — close a rotation window on evidence instead of on a guess.** Every
  authenticated delivery now fires an event naming the secret that verified it, so the one
  question a rotation raises has an answer: *is anything still arriving on the old secret?* Set
  `previous_secret`, listen for `matchedKeyId === SecretSet::PREVIOUS`, and retire the old key
  when it stops appearing. `matchedKeyId` is `current` or `previous` for a static secret, the
  JWKS `kid` when the keys come from a JWKS document, and whatever a custom verifier reported.

  The value was always there — `VerificationResult` has carried it from the start and its
  docblock says "so a rotation can be observed" — and every shipped scheme filled it in. Nothing
  read it back, so the promise was kept by the schemes and dropped by the pipeline; the config
  template said as much in a comment, which is now gone because it is no longer true.

  It fires on **every** verified delivery rather than only the rare one, deliberately: firing
  only on `previous` makes silence ambiguous, because a finished migration and no traffic at all
  look identical and call for opposite actions. Filter in your listener if you only want the rare
  case. A listener that throws cannot cost the delivery — the dispatch is guarded and the failure
  is reported.

- **`event_type` — say where an inbound delivery's event type comes from.** It was read from
  the body's `type` field and nowhere else. GitHub puts it in `X-GitHub-Event` and leaves the
  body carrying only `action`, so a GitHub installation logged **every** delivery with an
  empty `event_type`: the generated column empty too, and per-type `process` routing falling
  to the catch-all every single time. Nothing went red — receiving worked, dedupe worked, the
  payload was all there; only the column a stream is split on was blank.

  Same grammar as `dedupe_id`, deliberately, so there is one validator and one thing to learn:
  `'header:X-GitHub-Event'`, `'body:data.kind'`, or a `Webhooks\Client\EventTypeResolver`
  class-string. The resolver form is what GitHub actually wants — the useful type is the
  header **and** `action` together (`release.published`), which neither half gives alone. A
  malformed spec now fails at config load rather than at an empty column.

  Unset, behavior is unchanged.

- **`Webhooks::dispatchTo()` — deliver an application event to exactly one endpoint.**
  `dispatch()` fans out, and its third argument narrows to a *tenant*, not to an endpoint:
  two endpoints of the same customer share one. An application that routes rule → endpoint
  rather than event → every endpoint of that type had nowhere to go. The two methods that
  do take a single subscription were not a way in either — `ping()` sends a fixed
  `webhooks.ping` body, `redeliver()` needs a delivery that already exists.

  One subscription in, one `WebhookDelivery` out, and **no other endpoint gets a delivery
  row** — not even one that would then fail to send. It runs the same chain as a fan-out:
  catalog schema, NUL scrub, payload offload, SSRF re-validation, signing, circuit breaker,
  and the rate limit *shaping* the send rather than discarding it.

  An endpoint the fan-out would not have reached — inactive, auto-disabled, or never
  subscribed to that event type — raises the new `SubscriptionNotListening` instead. Not
  delivered, because sending an endpoint an event it never asked for is the one thing a
  subscription list exists to prevent; and not skipped, because a caller that named one
  subscription and got nothing back has an event that vanished.

- **The self-service portal can send a test event.** Every row of the endpoint list now
  carries a **Test** action that sends one `webhooks.ping` delivery to that endpoint. It was
  the one question the portal could not answer: until a real product event fired, a customer
  who had just registered an endpoint learned nothing about it — and by then a failure is a
  lost event rather than a test. The capability already existed on the manager and in the
  operator console; only the tenant-facing surface was missing it.

  It is bounded by the existing `platform.test_ping.max_per_minute` (default `5`). Note that
  the allowance is **per endpoint, not per tenant** — pair it with
  `platform.self_service.max_endpoints_per_tenant` if the total matters for your
  installation.

  A **disabled** endpoint is refused rather than pinged: it would otherwise accept the
  request, record a delivery, and have it dropped at send time, leaving the customer reading
  "sent" over nothing arriving. A spent allowance is reported with the seconds to wait rather
  than raised as an error. Copy is shipped in all seven locales.

### Fixed

- **A timestamp you assign yourself now means the same instant on MySQL as on PostgreSQL.** A
  naive string is a wall clock, and only a timezone turns it into an instant. Reading one back
  out of a MySQL column follows the column's rule — those bytes are UTC. Assigning one in your
  own code follows yours — it is your application's local time. Both went through the same
  branch, so the column's rule was applied to your value: under `Europe/Berlin`,
  `WebhookCall::create([… 'created_at' => '2026-07-12 14:30:00'])` stored **14:30Z on MySQL and
  12:30Z on PostgreSQL** — two hours apart from the identical line of code, with nothing to
  notice it. Retention and every window query read those rows, so the same delivery was pruned
  on different days depending on the engine.

  The write path is now resolved the way PostgreSQL has always resolved it, so the two engines
  agree. **This changes stored values on MySQL** for timestamps an application assigns as a
  naive string; the package's own writes were never affected, because it always passes a date
  object. A numeric string is also read as the unix timestamp it is — that case did not diverge
  quietly, it threw `InvalidFormatException` out of a model setter on MySQL while PostgreSQL
  stored it.

- **The envelope a handler receives is NUL-scrubbed, like the row the package stores.** A NUL
  byte in a payload string is never intentional but entirely real, and PostgreSQL's `jsonb` type
  categorically refuses one. The package removed them before its own insert and left the copy a
  handler reads untouched, so an application writing `$this->message->payload` into a `jsonb`
  column of its own hit the wall the package had already cleared for itself — and hit it badly:
  the producer already had its 2xx so nothing was retried, the call row stayed on `received`
  because the status line sits after the write that threw, and the exception carried the whole
  payload into `failed_jobs` and from there into whatever collects errors, a second copy no
  retention policy on the payload column covers.

  The removal now happens where the body is read, so both copies come from one place. **Nothing
  else changes**: the raw body, its SHA-256 and the format a body was read as are all untouched,
  `$call->body()` still returns the exact bytes that were received and signature-verified, and a
  body made only of NUL bytes is still reported as unreadable rather than as absent. If you
  wrapped a handler in a scrub of your own, you can drop it.

- **The PostgreSQL dedupe upsert no longer runs on the read connection.** Reading the id back
  out of `INSERT … ON CONFLICT … RETURNING id` means the statement travels through
  `selectOne()`, whose third argument defaults to the **read** PDO — so on a host running the
  webhooks connection with Laravel's `read`/`write` split, every inbound delivery sent its
  insert to a replica. Against a streaming replica that is `SQLSTATE 25006` and a 500 on
  authentic traffic; against a read node that accepts writes it is quieter and worse, because
  the row lands off the write path and the read that follows reports the delivery as a
  **duplicate**. The connection is now also marked as modified, so a `sticky` split protects
  that read. The MySQL arm was never affected — `affectingStatement()` does both by itself.

- **The JSON metrics endpoint reports the right hour on MySQL.** The hourly rollup stores its
  bucket the way this package stores every timestamp on MySQL — UTC-naive in a `DATETIME` — and
  the controller parsed that string without saying which zone it was in. PHP then supplied its
  default, which is `app.timezone`, so on a host in any non-UTC zone every bucket came back at an
  instant off by that host's own offset: two hours in a German summer. **The counts inside each
  bucket were right**, which is what made it survive — a chart, a status page or an alerting rule
  driven off this endpoint drew a completely plausible curve beside the truth, and nothing
  anywhere went red. PostgreSQL puts the offset in the string it returns and was never affected.

  The gap that hid it was in the suite's shape rather than in anyone's attention: the endpoint's
  tests run only on PostgreSQL, and the MySQL lane drives no HTTP route at all, so no run ever
  crossed the two. That crossing now exists as its own lane, and it is what fails when this
  regresses.

- **The JWKS key window is documented as what it is.** The config template promised that
  without a pinned `kid` "the current plus previous key" are tried — a claim about **age**. The
  code takes the **first two keys of the document**, and a JWK carries no reliable age (RFC 7517
  defines no ordering for `keys`). With one or two keys the two readings agree; with three or
  more, everything past the second is never tried and a delivery signed with one of those keys
  is refused as unsigned — no error, no log, just a producer retrying until its budget is gone.
  The template and the interop page now say so and point at `kid`, and a test pins the limit
  rather than leaving it implicit.

- **The dedupe example no longer points at the object id.** The config template and the
  receiving page both offered `body:data.object.id` in the same breath as Stripe — and that
  path is the invoice or the charge, which **every** event about that object carries. Copied
  as written, `invoice.payment_failed` became a duplicate of the `invoice.paid` before it:
  acknowledged, dropped, no trace, and the producer never repeats a delivery it was told
  succeeded. Both now read `body:id` — Stripe's delivery id is the envelope's own `evt_…` —
  and both name the wrong answer beside the right one, because a reader who already wrote it
  needs to be able to find out why.

- **`previous_secret` is documented.** The receive-side key has been implemented for a long
  time and was named in no configuration template and no page — and a capability nobody can
  find is one that does not exist. It is what keeps a producer's old secret verifying during a
  rotation; without it a rotation is an outage, with the producer's retries burning down while
  every new-secret delivery is refused `401`. The occasion for looking it up is usually an
  incident. The template now also says what makes the key usable: **both secrets verify at the
  same time**, current first, so the rotation window has no gap in it and the normal case still
  costs one comparison.

- **A published config file no longer has to be a complete copy.** The merge was Laravel's
  `mergeConfigFrom`, which is an `array_merge` at the top level only. This package ships
  twelve top-level keys and every one is a deep tree, so a host that published the file and
  kept just the block it changed replaced that whole layer: the siblings it trimmed away
  became **undefined**, and every key a later release added to that layer never reached it.

  Nothing reported it, and it failed in the bad direction — an absent key reads as `null`,
  and `null` on a brake means "no brake". A host that set
  `platform.self_service.max_endpoints_per_tenant` and deleted the rest was running with the
  registration and test-ping brakes switched off, silently.

  The merge is now recursive, at all **six** providers rather than one — the layers are
  independently mountable, so a host may boot any single one of them.

  **A list is replaced whole, never merged by index.** `dashboard.windows`,
  `core.ssrf.allowed_hosts`, `platform.catalog`, `server.retryable_4xx` and the six other
  lists are values you set, not containers to descend into. Narrowing `windows` to `['7d']`
  gives exactly `['7d']` — a recursive merge without that distinction would hand back the
  entries you removed, which on an allow-list is not a cosmetic difference.

  Switching something off is still spelled `=> null`. **Behavior change:** a host that
  switched a brake off by *deleting* its key instead gets the shipped default back — 5/min
  for test pings, 10/min for registrations. Deleting a key now means "no opinion", which is
  what a trimmed publish always looked like it meant.

  **If you run `config:cache`, rebuild it after upgrading.** The merge happens at boot, so a
  cache built by the previous version keeps serving what that version merged.

- **A dashboard panel that was still loading when the time window changed stayed on the old
  window — permanently.** The header read `7d` while the panel counted `24h`, and nothing
  reported it: no error, no log, and no later refresh that corrected it. It needed the panel
  to be mid-load at the moment of the click, so it showed up as an occasional oddity rather
  than a reproducible fault.

  The window switch was a broadcast, and a broadcast cannot reach a panel that has not
  finished loading — Livewire drops the event for one. The panel then finished loading from
  parameters captured when the page first rendered, which still named the old window, and
  the page's own re-render could not correct them.

  The window is now part of each panel's identity instead of an event, so changing it loads
  those panels again on the new window. **Visible change:** switching the window now shows
  the panels' skeletons for one round trip rather than replacing the numbers in place.

- **Under a Content-Security-Policy without `unsafe-eval`, the delivery drawer's focus trap
  and the secret panel's countdown did not run at all.** Both were inline Alpine object
  literals, and such a literal does not parse under Alpine's CSP evaluator — the directive is
  simply skipped, in the browser, with nothing in any server log.

  It removed the focus trap from a panel that shows delivery payloads: focus escaped behind
  the overlay and never returned to the control that opened it, so the drawer became
  unusable by keyboard and screen reader while looking correct to everyone else. In the
  portal it left a revealed signing secret on screen past the window it was meant to close in.

  Both are registered Alpine components now, injected once per page with the same CSP nonce
  the layouts already use for the theme mirror. Behavior is unchanged. `AlpineExpressionShapeTest`
  holds every shipped `x-data` to a bare factory call so logic cannot creep back into an
  attribute.

  **If you run a strict CSP: give the nonce.** Pinning `ui.theme` used to remove the only
  inline script; it no longer does, and the styling guide now lists all three.

- **The drawer's timestamps did not say which clock they were showing.** They rendered
  through a bare `LLL` pattern, which prints a wall-clock time with no zone. The detail panel
  is where an operator holds a delivery against their own records, and an unexplained hour of
  offset there reads as a delivery that did not happen when it did. The pattern is a
  translatable key now and carries the zone (`July 12, 2026 2:30 PM CEST`). The display zone
  itself is unchanged — it is `app.timezone`, as before.

- **`AddressClassifier`'s docblock explained the CIDR list with two addresses PHP's own filter
  already blocks.** The behavior of the class is unchanged and was never wrong — only the
  reason given for it was, and it was wrong in both directions. It cited `fd00:ec2::254` and
  `fe80::/10` as ranges that `FILTER_FLAG_NO_RES_RANGE` lets through; the filter blocks both.
  The ranges it genuinely reports as public went unmentioned: carrier-grade NAT, benchmarking,
  site-local IPv6, the IPv4-compatible loopback, the transition prefixes, the documentation
  blocks and multicast — 17 of the 28 entries in the list.

  That is the worst shape a wrong comment can take, because it invites the wrong action:
  anyone checking the stated reason finds both addresses blocked and concludes the list is
  redundant. The rationale now names ranges the filter really does pass, and a unit-test arm
  asserts that claim against `filter_var()` itself rather than against this class — so the
  sentence cannot rot again through a PHP upgrade or a change to the list.

## [1.12.0] - 2026-08-16

### Changed

- **The WireKit floor is now enforced instead of merely claimed — `conflict` on `<2.27`.** The
  v1.10.0 entry said a host pinned below WireKit 2.25 had to move up to install the package. It
  did not: the constraint sat in `require-dev`, which a consumer never installs, and in a
  `suggest` line that carries no version at all. A host on 2.20 installed it without resistance
  and got the defect the floor existed to prevent — a control that misreports its own stored
  state, quietly, on the first paint.

  `composer.json` now carries `"conflict": {"pushery/wirekit": "<2.27"}`. That **refuses** an
  older WireKit; it never installs one, so a headless host — or one on a different UI kit that
  publishes the views — is unaffected. **It is a real narrowing:** an installation that resolves
  WireKit below 2.27 today will not resolve this version.

  The number moved from 2.25 to 2.27 because that is where the *last* of the shipped screens'
  requirements landed, measured tag by tag: 2.27.0 is the first release carrying all seven
  locales this package ships **and** wrapping its own `alert-dialog` cancel label in `__()`.
  2.25 would have left the newest of the three quiet failures unguarded and put the same question
  back on the table in a few weeks. Every place the number appears is held to one value by
  `WirekitFloorContractTest`, so the constraint and the prose cannot drift apart again.

### Added

- **A secret the operator console reveals no longer rides along in the component state.**
  `newSecret` is a public Livewire property, so the plaintext was re-serialized into every
  request of the session after a registration or a rotation — long after it had been copied. It
  is dropped in `dehydrate()` now, which is the one place that gets both halves: Livewire renders
  before it and snapshots after it, so the value still reaches the one response that reveals it
  and reaches **no** snapshot at all, not even that response's own.

  No reveal window and no new action, deliberately. The self-service panel can afford a TTL
  because it also has `reveal()` — when the window closes the tenant asks again. This console has
  no way back, so a timer would only decide how long an operator has before the secret is
  unrecoverable, and the alternative (a reveal action on a screen that is unscoped across every
  tenant) is a larger security surface than the one being closed.

- **The operator console can edit an endpoint and rotate its signing secret.** It could
  register, switch and destroy one, and nothing else — which cost something on both ends of
  the severity scale.

  `rotate` is the security action: a leaked signing secret has to be rollable from the surface
  that manages it, and without the action an operator was left with tinker or a database write
  at the moment speed matters most. Rotating issues a new secret immediately and reveals it
  once, while the previous secret stays valid as the verify-only rotation secret until the
  window closes — so the leak is closed without knocking the receiver offline while it
  redeploys. Both shipped view variants confirm it first.

  `edit` is the everyday one: without it, correcting a URL or an event selection meant
  delete-and-recreate, which is not the same operation. The endpoint got a **new identity**,
  and its delivery history, its health state and its active secret went with the old row.
  Editing keeps all three, and the destination is re-vetted through the SSRF guard on the way
  in, so an edit is not a route around the check that vets a registration.

  **If you published the stub, re-publish it or the two buttons will not appear.** A
  published view is yours and the package never overwrites it, so an installation running its
  own copy keeps the old three-action screen — `create()` is unchanged and still works, but
  the form now submits `save` and the row actions are new markup. Nothing breaks either way;
  the capability simply is not on screen until the view carries it.

  Both authorize through the existing `authorizeAction()` seam under the names `edit` and
  `rotate`, so one ability keeps answering per action, and both remain no-ops while
  `webhooks.admin.ability` is unset. Whatever an endpoint already holds stays acceptable and
  stays offered when the event catalog no longer declares it — the usual order writes the
  catalog after the endpoints exist, and without that a rename would be refused over a value
  nobody touched. Reported from a consuming application whose own 235-line console existed for
  exactly these two actions.

- **A receiver can now tell "I could not check" apart from "this is not authentic".**
  `VerificationResult` knew four outcomes and none of them meant *undetermined*. For a
  signature scheme that is complete — a pure function of body, headers and secret always
  reaches a verdict. An `InboundVerifier` does I/O, and I/O has a third exit, so a provider
  answering `404` ("this payment never existed" — a forgery) and a provider not answering at
  all (a timeout, a 5xx) both became `invalid`. A **provider outage** and someone **probing
  the endpoint** were therefore indistinguishable to every listener, and an alert built on
  one fires on the other.

  `VerificationResult::undetermined()` and `VerificationStatus::Undetermined` separate them.
  Nothing about the refusal softens: `isValid()` stays false, nothing is stored, nothing is
  dispatched. What changes is that the reason reaches `InvalidWebhookSignature` as
  `'undetermined'`.

  The second half is what the **sender** is told. A new per-config `undetermined_status`
  answers that one outcome separately — `503` asks the producer to try again, where `401`
  asks it to give up, which is the wrong instruction for a delivery that was in all
  likelihood genuine. It is **unset by default** and falls back to `invalid_status`, so an
  installation that configures nothing answers every refusal exactly as before: a
  distinguishable answer is information a prober can read too, which makes it the host's
  decision rather than the package's. The distinction says whether the check *completed*,
  never which part of it failed. Reported from a consuming application verifying Mollie by
  API callback, where an hourly reconciliation run was the only thing catching the
  deliveries refused during an outage.

- **The self-service portal can hide instead of deny — `platform.self_service.refuse_with`.**
  A reader without `manage-webhook-endpoints` is refused with 403, which is the honest answer
  and stays the default. For a host whose convention is to hide, it is also a disclosure:
  "real, but not yours" confirms that the installation runs an endpoint portal at all, which is
  the question a reader guessing URLs is asking. Set the key to `404` and the portal's pages
  and its embedded panels both answer that instead.

  The gate does not change and is not weakened: the reader is refused before any panel renders
  and on every later interaction, exactly as before. Left unset, the original authorization
  exception is rethrown untouched — message and gate response included — so an installation
  that never sets the key cannot tell the option exists. Same decision, and the same reasoning,
  as `client.*.undetermined_status`: a distinguishable answer is information a prober can read
  too, which makes it the host's call rather than the package's.

  Endpoint **ownership** is a separate, already-settled question and needs no configuration: a
  foreign endpoint id fails not-found before its policy everywhere in this package. Reported
  from a consuming application that had written the same exception mapping into its own
  middleware once, and would have written it again in the next one.

### Fixed

- **A shipped view comment named a superseded WireKit floor.** The transform-editor view told a
  reader that 2.25 "is the declared floor". The floor is 2.27, enforced in this same release
  through `conflict`; 2.25 is only the release that began honoring `value` on a select. Because
  `resources/` ships — through `vendor:publish` and through the public mirror — a reader who
  followed that comment would pin below the floor and reinstate the very defect the paragraph
  above it warns about: a version select that misreports the stored value on first paint.
  `WirekitFloorContractTest` held that shape for the styling guide only; it reads the shipped
  views now as well.

- **A single letter in a self-service URL answered 500 instead of 404.** The portal's transform
  route took `{subscription}` unconstrained, so the segment reached route-model binding — and the
  query — exactly as typed. `GET /webhooks/endpoints/abc/transform` made Postgres refuse the cast
  and returned a server error, where `/42/transform` correctly returned 404. It needed no
  knowledge of the installation, and the answer confirmed both that the route exists and that a
  database sits behind it. MySQL coerces instead of refusing, so the same defect was invisible on
  a suite running only that engine.

  The segment is now bounded to `[0-9]{1,18}`, which refuses the match rather than catching
  anything. Mapping the query exception to 404 would have swallowed genuine database errors on
  the same route — a worse trade than the defect.

  **The length bound is load-bearing, not decoration.** A digits-only constraint leaves the same
  500 reachable from the other end: `9999999999999999999999999` is all digits, so it passes the
  pattern and the column then rejects it for *range* rather than syntax. Eighteen digits is the
  widest run that always fits a signed bigint, so the pattern now refuses exactly what the key
  column cannot hold. Reported from a consuming application, and the range half was found while
  proving the reported half.

## [1.11.0] - 2026-08-15

### Fixed

- **A webhook that is not JSON no longer arrives as an empty payload that everyone treats as a
  success.** The receive side had one decoder and it was `json_decode`, so a producer posting
  `application/x-www-form-urlencoded` — Mollie sends exactly one field, `id=tr_…` — reached the
  handler with nothing in it. The handler found no fields, had nothing to do, marked the call
  processed and answered `200`, and the producer, told the delivery succeeded, never sent it
  again. No exception, no log line, nothing queued: a total loss that reads as success from both
  ends. Reported from a consuming application against a real provider account, where the whole
  receive path was built and every delivery would have been lost silently.

  The body is now read by its content type, with one rule that matters more than the change
  itself: **the declared type is permission to try the form decoder, never evidence about the
  body.** JSON is attempted first whatever the request declared, because a request built without
  a content type is stamped `application/x-www-form-urlencoded` by the HTTP layer and real
  producers send JSON under a wrong type or none — a decoder that believed the header would hand
  a JSON document to `parse_str` and break deliveries that work today. `application/vnd.x+json`
  needs no special handling for the same reason.

- **The idempotency key had the same defect, one layer up.** `dedupe_id => 'body:…'` and every
  `DedupeKeyResolver` are fed by a second decoder that runs before the envelope is built, and it
  was JSON-only too. A form producer therefore got a null key — and a null collides with nothing
  in the partial-unique index, so dedupe did nothing at all and every retry stored a fresh row.
  Both decoders now share one implementation, so they cannot disagree about one delivery.

  **This changes behavior for an unchanged config.** A source that already declares
  `dedupe_id => 'body:…'` (or a resolver) and receives form bodies starts de-duplicating on
  upgrade: repeat deliveries carrying the same key are answered with the configured success
  response and are no longer dispatched to a handler. That is the documented intent of the
  setting, and until now it was silently inert for exactly those producers — but if a handler
  was relying on seeing every repeat, it will stop.

  One limit is worth knowing: no signature scheme covers the `Content-Type` header, so a replay
  that strips it off an authentic form delivery still verifies, arrives unread, and yields no
  dedupe key. It is no longer silent — that is what the new event is for — and a scheme with a
  replay window bounds it in time, but a body-only HMAC has no window to bound it with.

- **A form field whose bytes are not UTF-8 no longer fails the delivery after it verified.**
  Percent-escapes decode to arbitrary bytes, and a JSON payload could never carry any, so
  everything downstream was built on the assumption that it could not be there: storing the
  payload and serializing the handler job onto the queue both encode as JSON and throw on such a
  byte, the first of them before the row is even written. Invalid UTF-8 is now substituted where
  the bytes enter, the lossy-but-valid trade the stored payload already makes for NUL bytes — the
  exact bytes stay on the row, and `$call->body()` returns them.

### Added
- **The self-service portal can answer "did my last delivery arrive?".** A fourth panel,
  `webhooks.self-service.endpoint-deliveries`, lists what was sent to a customer's endpoints —
  event, outcome, response code and when — newest first, paginated, and optionally narrowed to one
  endpoint. None of the three existing panels ever mentioned a delivery, so a customer could see
  THAT they had an endpoint and never whether anything had reached it; the health badge does not
  answer it either, because a score says an endpoint is broadly fine, not whether one particular
  event went out. Without the list, a receiver seeing nothing arrive cannot rule out "you did not
  send", which makes the list less a feature than the alternative to a support ticket.

  It is owner-scoped on the delivery row's own denormalized `(owner_type, owner_id)` pair, with no
  join for the scope to be widened through, and it renders **no body of any kind** — not the
  outbound payload, and not the stored `error`, which is an HTTP client's exception message and can
  quote back whatever the receiver wrote. The empty state names the retention window, because after
  it there provably are no rows by design.

  Reported from the same consuming application as the catalog validation above, comparing what its
  own screen did before replacing it with the shipped panels.

- **A populated event catalog is now the allowlist for registrations.** The self-service form and
  the operator console validated event types as `['string']`, so a tenant could register for a type
  nothing publishes: the endpoint saved, looked configured, and never fired — and a typo
  (`user.registred`) was indistinguishable from a correct registration until someone noticed weeks
  of nothing had arrived. The package already knew its catalog; only the two forms never asked it.

  The catalog **ships empty and an empty catalog constrains nothing**, so an application that keeps
  none is unaffected. Writing one turns it into a list. With `platform.wildcards` on, the prefix
  wildcards covering a declared type (`invoice.*` for `invoice.paid`) are accepted alongside it.

  **An endpoint that already holds a type the catalog does not declare stays editable**, and the
  form goes on offering that type so its owner can drop it deliberately. Writing a catalog after
  endpoints exist is the ordinary way to adopt this, and every save re-validates the whole list —
  without that, renaming such an endpoint would be refused over a type nobody touched, with no way
  out but deleting and re-registering, which mints a new secret and a new endpoint id.

  What a populated catalog constrains is REGISTRATION, not dispatch: the fan-out never consults it,
  so an application can still emit a type it does not document. The refusal carries the package's
  own translated sentence in all seven shipped locales rather than the framework's default line.

  Reported from a consuming application that was replacing its own registration screen with the
  shipped panels and compared what it would lose: its screen validated against its catalog, and
  adopting the panels would have dropped that check silently.

- **`Webhooks\Client\PayloadFormat`, on the `InboundMessage` your handler receives.** An empty
  payload used to mean four things at once, and the one that mattered was invisible. `format`
  separates them — `Json`, `Form`, `None` (nothing was sent) and `Unreadable` (something was
  sent and nothing read it) — and `$message->format->readable()` is false for `Unreadable`
  alone. Check it before acting on an empty payload; the bytes are still on the row.

- **`Webhooks\Client\Events\UnreadableWebhookPayload`.** Fires when an authentic delivery
  arrives in a format nothing could read, carrying the stored `call`, its source `config` and the
  declared `contentType`, so an application can alert without changing every handler —
  `$event->call->body()` returns the exact unread bytes. It fires only once the row and the
  handler job are durable, and a listener that throws cannot take the delivery down with it: the
  failure is wrapped in a `Webhooks\Client\Exceptions\UnreadablePayloadListenerFailed` and
  reported, and the delivery still succeeds. Wrapped rather than reported as-is because Laravel's
  handler skips a documented set of exceptions — a listener that ran `firstOrFail()`,
  `Gate::authorize()`, `validate()` or `abort()` throws one of them, and reporting it would be a
  silent no-op. The
  call is still stored and still answered normally, because the delivery is authentic and asking
  the producer to retry bytes that will fail the same way buys nothing.

  A form body PHP only partly read is reported as unread rather than as a partial read, because
  a handler acts on a half-read payload: that covers a body carrying more fields than
  `max_input_vars` and one whose nesting leaves `parse_str` nothing at all. A body that mixes
  ordinary fields with a single over-nested one is the case PHP reports nothing about, and it
  arrives carrying the fields that survived.

  `multipart/form-data` is answered as unread rather than parsed. PHP consumes a multipart POST
  into `$_POST` and `$_FILES` before any middleware can capture the bytes, so the body usually
  arrives empty — and calling that "nothing was sent" would be a confident false claim about a
  delivery that carried fields. JSON is still attempted first, so an envelope mislabeled
  `multipart/*` is read rather than refused.

  An envelope serialized by an earlier release — a queue backlog, a delayed dispatch, a
  `queue:retry` out of `failed_jobs` — carries no `format`, and PHP does not apply a promoted
  parameter's default when it unserializes. Those envelopes are filled in as `Json`, the only
  thing their payload could have come from, so the guard above is safe to write on the first line
  of a handler during a rolling upgrade rather than after the queue has drained. The reverse
  direction is not covered and cannot be: an envelope written by this release does not
  unserialize under an earlier one, so a rollback strands whatever it enqueued.

## [1.10.1] - 2026-08-14

### Fixed

- **Every table row in the shipped screens now identifies itself to a screen reader.** The cell
  that names the row was rendered as a data cell, so navigating a row's other columns announced
  "3 events" or "Failed" with no way to tell WHICH endpoint or delivery they belonged to — on
  surfaces where endpoints are disabled and secrets rotated, that is the row you must not
  confuse (WCAG 2.2 1.3.1, Level A). Six tables were affected: the self-service endpoint list
  and health matrix, the dashboard's deliveries table and recent queue, and both the plain and
  WireKit variants of the subscription manager and delivery log.

  The recent queue is the one that is not simply "the first cell": it leads with a status badge,
  so its row header is the event column instead. A row header does not have to come first, and
  promoting the badge would have satisfied the rule while still announcing nothing identifying.

  Reported by a consuming application whose accessibility sweep noticed the screen it replaced
  had a row header where the package's did not — the class of regression no test of ours could
  see, because the markup stays valid and the page looks identical.

## [1.10.0] - 2026-08-12

### Added

- **The self-service panels can be embedded without the portal's own pages.**
  `platform.self_service.register_routes` (default `true`) splits two decisions that used to be
  one: registering the provider registered the Livewire components *and* mounted routes with
  their own prefix and middleware. A host that wanted `<livewire:webhooks.self-service.endpoint-list />`
  inside a screen it already guards had to accept a second URL onto the same surface, carrying
  the portal's middleware instead of its own — or decline the provider and get no components at
  all. Set it to `false` and the provider registers the panels and mounts nothing.

- **The operator console can check each action, not only the page.**
  `admin.ability` (default `null`) makes `create`, `toggle`, `delete`, `redeliver` and `ping`
  authorize on every request; the action name is passed to the gate, so one ability can answer
  differently per action. For a rule no ability expresses, subclass either component and
  override `authorizeAction(string $action): void`.

  This is not a duplicate of the operator-only gate you put in front of the page, and it does
  not replace it. A page gate decides who receives a Livewire snapshot; every interaction after
  that is a separate request to Livewire's own endpoint. So a capability revoked *during* a
  session keeps working until the reader navigates, and a component embedded in a second place
  inherits that page's gate rather than the one you reasoned about. The default is `null`,
  which is exactly the previous behavior — and none of this is tenant scoping: the console
  still reads every tenant's rows.

- **Two events that name a person rather than a delivery.** `WebhookEndpointRegistered` and
  `WebhookSecretRotated` carry the subscription and the acting user, and fire from
  `WebhookManager`, so an endpoint registered through your own screen is recorded like one
  registered through the portal. The actor is `null` when nobody is authenticated — a console
  command, a seeder, a queued job — which is information rather than a gap. Neither secret
  travels on the rotation event: events reach every listener, are serialized into queue
  payloads and are frequently logged wholesale. The package still writes no audit trail of its
  own; hang a listener on these and write wherever yours lives.

### Changed

- **Two brakes now ship switched on, and one of them can be met by an existing integration.**
  `platform.self_service.registrations_per_minute` (`10`) bounds how fast a single tenant may
  register endpoints through the portal; `platform.test_ping.max_per_minute` (`5`) bounds how
  often one endpoint may be manually test-pinged. Set either to `null` to remove it.

  `max_endpoints_per_tenant` bounds how *many* endpoints a tenant ends up with and says nothing
  about how fast — nothing at all when it is unset. The test ping had no bound of any kind: it
  bypasses the delivery rate limit on purpose, so that an operator can prove an endpoint answers
  while it is over its allowance, and that exemption left the one send a person repeats at will
  unlimited, aimed at a destination the requester chose.

  **What to check before upgrading:** a bulk import driven through the self-service portal will
  meet the registration brake. Bulk registration belongs on `Webhooks::subscribe()`, which is
  deliberately not braked. An over-allowance ping is refused with the new
  `Webhooks\Exceptions\TestPingThrottled`, which carries `secondsUntilAvailable` so a screen can
  say when to try again; the shipped operator stub does exactly that.

- **The WireKit floor is now 2.25, up from 2.12.** 1.9.1 fixed the self-service payload-version
  select by passing `:value` alongside `wire:model`, so the server render carries `selected` for
  the stored version. WireKit's select only honors `value` from **2.25.0** on — before that it
  declares no such prop at all — so on 2.12 through 2.24 that fix does nothing and the control
  goes on showing the first option, the empty "inherit" entry, for an endpoint that is pinned.
  The constraint said the fix was supported three minors before the behavior existed.

  This narrows the supported range: a host pinned below WireKit 2.25 has to move up to install
  this version. The alternative was to keep the wider range and weaken the test that proves the
  first paint, which would have gone green while every host on 2.12–2.24 kept a control that
  misreports its own stored state — visibly, and for a reader without JavaScript exclusively.
  Both floors are now named in [Styling the UI](https://docs.pushery.com/webhooks-for-laravel/guides/styling-the-ui),
  with what each one buys.

  > **Correction (2026-08-15).** The sentence above was not true when it was published. The
  > constraint lived in `require-dev`, which a consumer never installs, and in an unversioned
  > `suggest` — so nothing stopped a host on WireKit 2.20 from installing 1.10.0 and getting
  > exactly the defect the floor was meant to prevent. It said what we *tested* against and
  > claimed what we *enforced*. It is enforced from the next release on, at 2.27 rather than
  > 2.25 — see the `[Unreleased]` entry. The sentence is left standing rather than rewritten,
  > because the history is published and a silently corrected claim is worse than a visibly
  > corrected one.

### Fixed

- **A self-service panel embedded outside the portal no longer throws on its first real row.**
  The endpoint list linked each row to the payload-transform editor unconditionally, and that
  route exists only while the portal mounts its own pages. Rendered anywhere else the link threw
  from inside the view, so the whole screen failed — but only once there was an endpoint to draw
  a row for. An empty account rendered perfectly, which is how an adoption could be verified as
  complete and still be one customer away from a 500. Three further links had the same shape: the
  health-board link on the portal shell, and the back links on the health board and the transform
  editor. All four now render only when their route is registered.

### Security

- **The endpoint cap now holds when two registrations arrive at once.** It was read-then-act —
  count the tenant's endpoints, then insert one — with no lock, transaction or constraint between
  the two steps, so two concurrent requests both read one below the limit, both passed, and both
  inserted. The check and the insert now share a per-tenant lock. A double-submit is the ordinary
  way one customer produces two simultaneous registrations, so this was reachable without trying.

  A registration that finds another in flight waits briefly and then re-reads the cap, which
  gives a true verdict either way. Only a wait past a few seconds is reported, and it is
  reported as contention rather than as the cap — a tenant with slots left is not told it is
  full. With no cap configured no lock is taken at all.

- **The SSRF guard now refuses the IPv6 transition prefixes.** 6to4 (`2002::/16`), its
  deprecated relay anycast (`192.88.99.0/24`), Teredo (`2001::/32`) and both ORCHID blocks were
  not on the blocklist, and their absence was not a completeness gap. 6to4 and Teredo *embed* an
  IPv4 address, and not in the low bits where the guard unwraps one: it knew the mapped,
  translated and compatible forms, all of which carry the address behind a fixed 12-byte prefix,
  while 6to4 carries it in bits 16–47. `2002:7f00:1::` is `127.0.0.1` written in an encoding the
  unwrapper could not see, and a registered endpoint at that address passed validation.

## [1.9.1] - 2026-08-04

### Fixed

- **The payload-version select now shows the stored version on the first paint, not the first
  option.** The control was bound with `wire:model` alone, which sets the choice once Alpine has
  run — so the server render carried no `selected`, and an endpoint pinned to `v2` rendered as
  though it inherited. That is the state a reader acts on before hydration, and the only state a
  reader without JavaScript ever sees.

- **The six shipped tables now name their own scroll region.** Each table sits inside a focusable,
  horizontally scrolling container, and the container took its accessible name from WireKit's
  generic fallback — so a screen-reader user tabbing through the dashboard met several identically
  named regions. They now carry a translated, table-specific name in all seven locales, which also
  makes the name independent of which locales WireKit itself happens to ship. Two of the six had no
  table name at all and gained one.

### Changed

- **The bundled Boost skill now states the UI prerequisites.** Switching on the dashboard or the
  self-service portal needs `livewire/livewire` and `pushery/wirekit`, neither of which is a hard
  dependency — so an agent following the skill turned a layer on and met `Unable to locate a class
  or view for component [wirekit::card]` at render rather than a clear message at boot. The layer
  table's dependency notes now name both packages, the install line, and the publish-and-restyle
  route for a host on another UI kit.

- **The two publishable delete dialogs now say why they set their own cancel label, and when it
  would be safe to stop.** WireKit ships translations of its own from 2.26 on, which reads like an
  invitation to drop the override — but it ships `en` and `de`, and these screens ship seven
  locales. Taking the invitation would return `es`, `fr`, `it`, `nl` and `pt` to English with
  nothing turning red: the button still renders, in the wrong language. The comment now names the
  condition to check (`vendor/pushery/wirekit/lang/` carrying all seven) rather than the release
  note that prompted the question.

## [1.9.0] - 2026-08-03

### Added

- **The delivery drawer now says when a body was offloaded, instead of showing its stub without
  comment.** Past `server.large_payload.threshold` the log keeps only a stub — the event type, or
  nothing — and the body moves to a disk. The drawer rendered that stub silently, so the
  *largest* deliveries appeared as the smallest ones in the log, and an operator reasonably read
  "this payload was tiny" when the opposite was true.

  The payload gate sharpened the confusion rather than easing it: with the body redacted, a stub
  renders as `{"type":"[string]"}`, which is indistinguishable from a delivery that really did
  carry almost nothing. The drawer now names the disk the body went to, above the body, in all
  seven locales.

  Deliberately a notice and not a fetch. Rehydrating from the disk on every open would undo the
  reason offloading was switched on, and it would add a second read path over the same data that
  the payload ability would then have to cover as well. The pointer stays out of the Livewire
  snapshot, exactly like the body itself.

### Changed

- **`symfony/yaml` is now listed under `suggest`.** `webhooks:asyncapi --format=yaml` has always
  needed it — the command defaults to JSON and reports a clear error when the package is absent —
  but it was the only optional dependency with no entry, so the requirement was discoverable
  only by hitting it. Nothing about the behaviour changes.

- **The published `composer.json` no longer carries a `config` block.** It held `sort-packages`
  and an `allow-plugins` entry for a `require-dev` package: composer reads `config` only from the
  root package, so both were inert text in an installed dependency. No consumer behaviour
  changes; the manifest simply stops describing this repository's own tooling.

- One piece of English UI copy in the self-service portal was corrected to US spelling
  (`recognise` → `recognize`), matching the rest of the shipped surface.

## [1.8.0] - 2026-08-03

### Security

- **The delivery body in the dashboard drawer is now gated separately from the dashboard
  itself, and it fails closed.** Until now any user who could open the dashboard could read
  every delivery payload in full, next to a copy button — and in a real integration that
  payload is the business record: the order, the customer, the shipping address. It hung on a
  single ability, `view-webhook-dashboard`, which a per-tenant dashboard necessarily grants
  broadly, because every customer needs it to see their own deliveries. "May see that a
  delivery failed" therefore implied "may see whose order it was", which is not a coarse
  permission level but the absence of one.

  The body now has its own ability, `dashboard.payload.ability` (default
  `view-webhook-payload`). Values are shown only when that ability is defined **and** the
  acting user passes it.

  **This changes behaviour on upgrade.** A host that defines nothing gets the safe default
  rather than the previous one. To keep the old behaviour, say so explicitly — one line,
  greppable, unlike an absence:

  ```php
  Gate::define('view-webhook-payload', fn (): bool => true);
  ```

  A denied read is not a blank space. By default the drawer shows the body's structure with
  every value replaced by its type, which is the half debugging actually needs, and it always
  explains why the values are missing — a panel that stops after its heading reads as a defect
  and invites the guard's removal. Set `dashboard.payload.denied` to `hidden` for the notice
  alone; any other value is read as `hidden`, because a typo in a security setting must not be
  the permissive reading.

  The drawer holds the delivery and the rendered body as computed properties rather than public
  ones, so a body the user may not see is never serialized into the Livewire snapshot. The gate
  is a boundary, not a template that declines to print.

### Added

- **`Webhooks\Client\Http\RawBody::of($request)` — a supported way for an `InboundVerifier` to
  read a delivery's exact bytes.** A signature scheme is handed the body already; a verifier is
  not, it receives the `Request`. And at least one of the two cases the verifier seam exists for
  *needs* the bytes: a provider that verifies through a callback API checks the document it
  sent. The package already captured those bytes before anything downstream could re-encode
  them, but the reader was `private` and the attribute constant sat on an `@internal` class — so
  a verifier reaching for it got a static-analysis error for doing the right thing.

  The trap this closes is worth naming, because it is invisible. `$request->json()->all()`
  re-encoded is **not** the delivery: `/` comes back as `\/`, non-ASCII as `\uXXXX`, and key
  order need not survive. The provider then answers about a different document — with HTTP 200
  and a negative verdict. Nothing throws, nothing logs, and no test goes red while the provider
  is faked; in production it simply reads as "the provider rejects our webhooks", with no cause
  anywhere. The verifier contract and the receiving guide now both say so.

  The package's own inbound pipeline resolves the body through this same helper rather than a
  second copy, so a verifier and the pipeline can never disagree about what the body was for a
  given delivery.

- **An umbrella publish tag, `webhooks`.** `php artisan vendor:publish --tag=webhooks` now
  publishes the config, the views and the language files in one command, instead of three.

  It covers exactly those three, and the two omissions are deliberate rather than partial work.
  **Migrations stay out**: a published migration *runs*, and the per-layer migration tags exist
  so a send-only host never receives the client, server and dashboard tables it never switched
  on — an "everything" tag would undo that in one command. **The two operator-console variants
  stay out** as well: `webhooks-ui` and `webhooks-ui-wirekit` write to the same destination by
  design, so publishing both would resolve to whichever ran last, and an order-dependent publish
  is worse than none. Both exclusions are held by tests, so neither can be "completed" by
  accident later.

### Changed

- **Laravel Scout 11 is now covered for the searchable delivery and inbound-call logs.** The
  `suggest` block points a host at Scout for `Webhooks\Search\SearchableWebhookDelivery` and
  friends, while the development requirement was pinned to Scout 10 — so a host on 11 had the
  suggestion with nothing behind it. The requirement is `^10.0 || ^11.0` now, and the suite
  exercises that surface against the highest version the constraint resolves to, which today
  is 11.

  Worth stating precisely, because the difference matters to anyone still on 10: Scout 10
  remains supported and installable, but the gate proves **one** resolution per run, not both
  majors side by side. Nothing changed in the shipped code, and nothing about the requirement
  itself: Scout stays optional and the search layer stays off until `webhooks.search.enabled`
  is set.

## [1.7.1] - 2026-07-26

### Fixed

- **The static-analysis gate no longer dies on a fresh dependency resolution.** `phpstan/phpstan`
  2.2.6 removed a private property that `rector/rector` 2.5.7 reaches into, so every
  `rector process` aborted outright — the gate did not report a finding, it crashed. A library
  does not commit its lockfile, so CI resolves fresh on every run and hit this before any
  developer machine did. PHPStan is pinned below 2.2.6 until Rector supports it, and every
  upper-bound pin in `require-dev` now has to be registered with its reason and its retirement
  condition or the build fails. **Dev-only — `require-dev` never reaches the published package,
  so no consumer inherits the pin.**

### Added

- **A reference page for the stored status values and the exceptions.** The versioning page
  promised Semantic Versioning on "the enums, the exceptions" as categories — which tells you
  the promise exists but not what it covers, so writing a `catch` block or comparing
  `$delivery->status` meant reading the source. Every backed enum is now named with its exact
  stored string, and every exception with what throws it and whether catching it is the right
  layer. Derived from the code by a guard, so a new case or exception cannot ship unnamed.

### Changed

- The mutation release gate now **adopts a green isolated CI run** for the tree being tagged,
  and only falls back to the local ~2-hour serial run when no such proof exists. The receipt
  could previously be produced only locally, which forced that run onto the developer machine
  at every release — the contention the fleet rule moves to CI in the first place. Adoption is
  verified, not assumed: the pipeline's commit must resolve to the same tree, and the mutation
  step itself must be green, and that step must have **produced a score** — the nightly lane is
  state-deduped and exits 0 when nothing changed, so a green step alone can mean no mutation
  ran at all. `just mutate-local` forces the local run. Internal tooling only — nothing shipped
  changes.

## [1.7.0] - 2026-07-26

### Added

- **A cross-tenant dashboard scope for support consoles.** `dashboard.all_tenants`
  (`WEBHOOKS_DASHBOARD_ALL_TENANTS`) reads every delivery, owner-less and tenant-owned alike —
  the "what did we send to this customer's endpoint?" view. It is deliberately separate from
  `dashboard.operator`, which stays *global rows only*: those are different permission levels,
  and merging them would silently widen every existing operator dashboard on upgrade. It wins
  when both flags are on, and carries its own ability
  (`dashboard.all_tenants_ability`, default `view-all-tenant-webhooks`) on top of the route
  gate. If that ability is defined and the user fails it the request is **denied**, never
  narrowed to a smaller scope.
- `WebhookSubscription` now carries the timestamp query scopes the three log models already
  had, so `->whereTimestamp('disabled_at', '>=', $moment)` binds correctly per dialect.
  Filtering that column by hand — or borrowing the binding from another model — was the only
  previous option, and the plain `->where()` it invites is silently wrong: PostgreSQL resolves
  a naive literal against the database session zone, shifting the window by that offset.

### Security

- The cross-tenant dashboard scope **requires** its ability rather than defaulting open. An
  undefined ability now denies instead of reading every tenant's deliveries. This matters
  because the scope sits behind `view-webhook-dashboard`, which a per-tenant dashboard grants
  broadly — every customer needs it for their own data — so an install that enabled the flag
  without defining a second ability would have exposed every customer's history to every
  customer. To run with no second gate, define the ability as always-true explicitly.

### Fixed

- **The PostgreSQL delivery log now has a `created_at` index.** Every existing index led with
  another column, so a global newest-first read — the operator delivery log, which is not
  owner-scoped — could not be served by any of them: PostgreSQL sequentially scanned every
  live partition and sorted the union before it could take the first page. The index is
  declared on the partitioned parent, so new partitions inherit it. MySQL already had one.
  **Existing installations get it through a new additive migration** — run `php artisan
  migrate` (re-publish the migration tag first if you publish them). On a large log the index
  build locks each partition while it runs; the migration's docblock describes the manual
  online path if that window matters.

## [1.6.0] - 2026-07-26

### Security

- The `guzzlehttp/guzzle` floor is raised to **^7.15.1**. The previous `^7.8` let an install
  resolve to a version carrying four advisories — a `Proxy-Authorization` header forwarded to
  origin servers on redirect, URI fragments disclosed in `Referer`, host-only cookie scope not
  preserved, and unbounded response cookies. This package drives outbound HTTP to
  customer-controlled URLs, so those are squarely in its path.

### Added

- The package now ships a **Laravel Boost skill** at
  `resources/boost/skills/webhooks-for-laravel/SKILL.md`. Boost surfaces it inside consuming
  applications, so an agent integrating the package gets the layer gates, the per-layer
  publish tags and the smallest correct send/receive setup without reading the whole
  documentation. It is required at release.

### Changed

- The documentation moved to <https://docs.pushery.com/webhooks-for-laravel/>. Everything the
  README used to carry — installation, the layer guides, signatures and interop, events,
  security, reliability, the configuration and command reference, the upgrade and migration
  guides — is published there, restructured into pages instead of one long file. Nothing was
  dropped.
- The README is now a showcase: what the package does, how to install it, what you get, and
  links into the documentation. It no longer duplicates the pages it points at.
- The five shipped files that referred a reader to the README's "Styling the UI" section now
  link the guide directly, so the pointer resolves for anyone reading the installed package.

## [1.5.2] - 2026-07-16

### Changed

- The dashboard delivery table and the self-service health board now sort their columns
  through WireKit's native keyboard-operable `sort-action`, replacing the package's own
  header-button markup. The headers keep their focus ring and `aria-sort` and gain a
  sort-direction indicator. **This raises the WireKit floor for the shipped tables to 2.12**
  — on an older WireKit the sort headers still render but become mouse-only.

## [1.5.1] - 2026-07-16

### Documentation

- The Localization surface table in the README now lists the `pagination` file alongside the
  other four, matching the five translation files every locale ships — so a host localizing the
  shipped UI knows the pagination control's page links and screen-reader labels are overridable
  under the `webhooks` namespace too.

## [1.5.0] - 2026-07-16

### Added

- **`webhooks:prune-orphaned-payloads`** reclaims offloaded payload objects on a Storage disk that
  no delivery-log or call-log row still references — the app-side alternative to a bucket lifecycle
  policy when `large_payload` offload is enabled. It is content-addressing-safe (an object shared by
  several rows is kept until the last one is gone), sweeps the Server offload disk by default (or
  `--disk=<disk>`), supports `--dry-run`, and is not scheduled by default. Prefer a disk lifecycle
  policy for object storage; reach for this on a local disk or when compliance requires
  app-controlled deletion, and run it off-peak (it assumes offload writes are quiesced for the sweep).

## [1.4.12] - 2026-07-16

### Documentation

- Documented the endpoint lifecycle API — `Webhooks::enable()`, `disable()` and `unsubscribe()` —
  and corrected the circuit-breaker section: once an endpoint auto-disables it receives no traffic
  and does **not** self-recover, so `Webhooks::enable()` (which also clears the failure streak) is
  the recovery path, typically wired to the `WebhookEndpointAutoDisabled` event.
- Documented how the self-service portal and the dashboard resolve the acting tenant and how to
  override it (`SubscriptionScope::resolveUsing()` / `DashboardScope::resolveUsing()`) for a custom
  tenant model — without which a team-owned installation scopes to the wrong owner and shows an
  empty endpoint list.
- Documented the `webhooks:asyncapi` options (an optional output path, `--format=yaml`, and
  `--title` / `--doc-version`) and clarified that YAML is opt-in via the flag, not selected
  automatically once `symfony/yaml` is installed.
- Documented `client.raw_body_capture` (previously an uncommented toggle) and noted at first
  contact that a receiving `process` job must extend `ProcessWebhookJob`.
- Corrected a config comment that named the outbound builder `WebhookCall`; the send-side builder
  is `PendingWebhook` (via the `WebhookSender` facade).

## [1.4.11] - 2026-07-16

### Fixed

- **A stored header carrying an invalid UTF-8 byte no longer loses a verified inbound webhook.**
  With `store_headers` enabled, a header value containing a stray non-UTF-8 byte (a Latin-1
  accent, an intermediary's injected byte) made the header-JSON encode throw after the signature
  had already verified — a 500 on every retry, silently losing an authenticated webhook. The
  header JSON now substitutes invalid UTF-8, the same lossy-but-valid guarantee already applied to
  NUL bytes in the payload; the exact received bytes remain in the stored raw body and its SHA-256.

## [1.4.10] - 2026-07-16

### Fixed

- **The mutual-TLS client-certificate passphrase is now sealed at rest, like the signing
  secret.** A passphrase set via `useMutualTls()` was serialized in cleartext into the queue
  store and into every delivery-attempt event payload, while the signing secret in the same
  object was encrypted. It is now sealed with the app encrypter and unsealed only at send time,
  so it never sits in cleartext in the queue or reaches an event listener.
- **The tdigest percentile guard now also verifies the digest column, not just the extension.**
  The `latency_digest` column is added to the hourly rollup only when the extension is present as
  the migrations run, so installing the extension afterwards left the column missing — and the
  guard, which checked only the extension, passed and let the percentile query fail with a cryptic
  `column "latency_digest" does not exist`. It now names the rebuild, the actionable error the
  guard was always meant to give.
- **An empty payload is no longer wrongly rejected against an object schema.** With payload
  validation on, dispatching an event with an empty payload (`[]`) against a `{"type":"object"}`
  schema failed, because an empty PHP array is ambiguous and was validated as a JSON array rather
  than the empty object it represents. An empty payload now validates as `{}`.

## [1.4.9] - 2026-07-16

### Fixed

- **Documented that offloaded payload objects are not reclaimed by retention.** With `large_payload`
  offload enabled, over-threshold bodies are written to a Storage disk and the row keeps only a
  content-addressed pointer — but row pruning and partition drops remove rows only, never the disk
  objects, so the disk grew without bound and the retention docs were misleading. The offload config
  blocks and the README now state that offloaded objects must be reclaimed by a lifecycle policy on
  the disk (expire by last-modified age of at least the retention window); this is safe because every
  offload re-writes the object, so an object past that age has no live row referencing it.

## [1.4.8] - 2026-07-16

### Fixed

- **Self-service endpoint creation now fails closed when no tenant is in scope.** With a resolver
  that yields no current tenant, every read and manage action already refused; creation did not, and
  passed a null owner through to the manager — registering a global, owner-less endpoint that would
  then receive every tenant's payloads while staying invisible in the creator's own list. Creation
  now requires a tenant, like every other self-service action. The unscoped operator console is
  unchanged: registering a global endpoint there is intentional.
- **Raising a delivery's Retry-After cap on a single call now also raises the delay it is clamped
  to.** `retryAfterCapInSeconds()` moved only the defer threshold, not the schedule's clamp, so a
  hint under the new cap was still shortened to the configured default — the delivery came back
  while the endpoint was still rate-limiting it, and the attempt was charged. Both move together
  now.
- **A processing-dispatch failure after an inbound call is stored no longer swallows the producer's
  retry.** If the queue push failed once the row was committed, the call was marked seen yet never
  handled, and every retry short-circuited to a bare success — stored, acknowledged, silently never
  processed. The row is now rolled back and left unseen on a dispatch failure, so the retry stores
  and dispatches it.
- **The endpoint URL is capped at 2048 characters on the self-service and operator forms.** The
  column is `varchar(2048)` on MySQL but unbounded `text` on PostgreSQL, so an over-long URL stored
  on one engine and errored on the other; it is now refused as a field error identically on both.

## [1.4.7] - 2026-07-16

### Fixed

- **Scout search now actually populates an external engine (Meilisearch, Algolia, …).** The
  delivery log is written through the base model and the inbound call log through a raw SQL upsert,
  neither of which fires Scout's per-model observer — so with an external engine the index was never
  written and search silently returned nothing (the shipped `collection`/`database` engines read
  the table directly and hid the gap). Each row is now indexed explicitly after it is written, when
  search is enabled and the configured model is a searchable one; a host that has not opted into
  search, or is on a database-backed engine, is unaffected.

## [1.4.6] - 2026-07-16

### Fixed

- **The self-service payload-transform preview now matches what the endpoint actually receives.**
  When an endpoint names a payload version but stores no per-endpoint transform, delivery inherits
  that version's default rules — but the editor's live preview only reflected its own typed
  controls, so it showed a body (fields kept, envelope shape) different from the one delivered. The
  preview now resolves rules exactly as delivery does.
- **The dashboard defaults to the first configured time window rather than a hardcoded 24h.** A
  host that narrows `dashboard.windows` to a set without `24h` no longer lands on an un-offered
  window that no control selects (and that disagreed with the JSON metrics API).
- **A momentary JWKS provider blip is no longer cached as an hour-long outage.** The Ed25519/JWKS
  key set is cached only when it is non-empty; an empty result from a maintenance page or a 5xx
  body is retried on the next request instead of being pinned for the full TTL, during which every
  JWKS-verified webhook would have been rejected.

## [1.4.5] - 2026-07-16

### Fixed

- **⚠ The MySQL minimum (8.4, the LTS) is now enforced.** The README, config and error message have
  always stated MySQL 8.4+, but the runtime guard's floor was 8.0.17 — so it silently accepted
  MySQL 8.0.17 through 8.3, versions the package neither supports nor tests against. The guard now
  refuses anything below 8.4, matching the documented requirement. A host running on an unsupported
  8.0.17–8.3 server (against the docs) will now get a clear message to upgrade; PostgreSQL and MySQL
  8.4+ are unaffected.

## [1.4.4] - 2026-07-16

### Fixed

- **The optional operator delivery-log screen no longer runs a full row count on every render.**
  It reads the whole delivery log unscoped, and a `count(*)` over a partitioned table with millions
  of rows does not scale; it now uses simple (previous/next) pagination, which needs no total. The
  tenant-facing dashboard tables are owner-scoped and were never affected.
- **Accessibility: the payload-transform editor now announces to screen readers when the live
  preview recomputes.** Its `aria-live` status region carried a constant string, so it never
  actually fired; it now re-renders on each edit. The Pulse card's failure-rate text was also
  darkened to meet the AA contrast minimum.

## [1.4.3] - 2026-07-16

### Fixed

- **The exponential backoff cap is floored at one second, like the base.** The base delay was
  already floored so a misconfigured `0` could not collapse retries into a zero-delay storm; the
  cap was not, so a `backoff.cap` of `0` would have floored every retry delay to zero regardless of
  the base. Both bounds are now floored.

## [1.4.2] - 2026-07-16

### Fixed

- **On MySQL, the endpoint health window no longer slides with the database session time zone.**
  MySQL converts an offset-bearing timestamp literal into the database's session time zone, so the
  health scorer's window bound — rendered in the PostgreSQL offset form — shifted the 24-hour window
  by that offset against the UTC-naive column. East of UTC the window silently narrowed and dropped
  its oldest hours, so an endpoint that had recently been failing could score a perfect, unearned
  health and never be auto-disabled. The bound is now rendered for the connection's own dialect, and
  the whole window is scored whatever the session zone is.
- **On MySQL, retention pruning no longer deletes rows before their window has closed.** The inbound
  call log and the standalone server delivery log bound their retention cutoff in the same
  session-zone-sensitive way, so a scheduled prune east of UTC removed rows up to the session offset
  early. The cutoff is now bound for the connection's dialect.
- **The self-service payload-transform editor fails not-found for an endpoint the tenant does not
  own, before the authorization check** — so a foreign-but-existing id can no longer be told apart
  from a non-existent one, closing an id-enumeration seam. This matches how every other portal panel
  already scopes a lookup; no legitimate access changes.
- **The spatie backfill import now masks credential-bearing request headers (Authorization, Cookie)
  before writing them to the call log,** exactly as the live receive path does — both now redact
  through one shared component, so they can never disagree on which headers are secret.

## [1.4.1] - 2026-07-16

### Fixed

- **The self-service portal re-checks its authorization gate on every request, not only the first
  one.** Livewire runs `mount()` once; every later interaction is an update request that skips it,
  so a gate authorized in `mount()` alone was replayable — a tenant whose `manage-webhook-endpoints`
  ability was withdrawn mid-session kept being served by the panels it already had open, until it
  reloaded the page. Every mutation was already safe (each carries a row-level policy, and that
  policy re-checks the ability), but a read path with no policy — the endpoint list's refresh
  listener — kept re-rendering the tenant's endpoints against an ability it no longer had. The gate
  now runs in `boot()`, the first hook on both the initial and the update path, for every portal
  panel and the portal page; it answers the same 403 an unauthorized mount always has. No data ever
  crossed a tenant boundary — the panels scope every query to the acting tenant regardless. The
  dashboard was never affected: its gate travels with the route as middleware, which Livewire
  re-applies on update requests.

## [1.4.0] - 2026-07-16

### Added

- **UUID and ULID owner keys.** A webhook subscription's owner may now be keyed by a UUID or a
  ULID, not only a bigint. Set `platform.owner_key_type` (`WEBHOOKS_OWNER_KEY_TYPE`) to `uuid` or
  `ulid` before migrating and the denormalised `owner_id` column is rendered to match across all
  three tables it spans — the subscriptions table, the delivery log and the dashboard rollup — so a
  host whose tenants key by UUID/ULID no longer has to hand-patch the published migrations after
  every `vendor:publish`. The default stays `bigint`, so existing installs are unaffected;
  `subscribe()` rejects an owner whose key does not match the configured type up front, with a clear
  error, instead of failing on the first fan-out. The global (owner-less) row's MySQL rollup
  sentinel follows the type too (the nil UUID / all-zero ULID), keeping operator-mode reads correct
  on every engine. Proven end to end on both PostgreSQL and MySQL.

## [1.3.1] - 2026-07-15

### Fixed

- **On MySQL, Platform delivery-log lifecycle updates silently failed — every delivery stayed
  `pending`, the circuit breaker never tripped, endpoint health never updated.** The lifecycle
  listener locates the delivery row by `(id, created_at)`, and the manager rendered that `created_at`
  key as a PostgreSQL offset literal (`…+00:00`) regardless of engine — which matches zero rows
  against MySQL's UTC-naive `DATETIME(6)` column under strict mode. So on any MySQL deployment of the
  Platform layer the row was never found: outcome columns stayed null, `consecutive_failures` never
  advanced (a dead endpoint was never auto-disabled), and the succeeded/failed events never fired.
  The key is now rendered for the webhook connection's dialect.
- **The SQL dialect now follows the webhook connection, not the application default — the dedicated
  side-car topology worked in name only.** Every runtime query already ran against the connection
  `webhooks.database.connection` points at, but the SQL *dialect* for those queries was chosen from
  the application's default connection. When both are the same engine they agree, so this was
  invisible; but in the documented side-car deployment — an app on one engine keeping the webhook
  tables on a dedicated connection of the other — the dialect was wrong for every runtime path:
  inbound webhooks were never persisted (a MySQL-shaped insert issued against a PostgreSQL side-car),
  the metrics rollup never refreshed, and health/partition maintenance errored. The dialect is now
  resolved from the webhook connection everywhere, and a guard keeps it that way.
- **The optional tdigest percentile extension is now probed on the webhook connection, not the app
  default.** The presence check ran on the application-default connection while the tdigest SQL ran on
  the webhook (side-car) connection — so under a side-car it could either wrongly report the extension
  missing (disabling a supported feature) or pass the check and then fail the query with the exact
  cryptic error the check exists to prevent.
- **`ui.csp_nonce` no longer has to be a config closure that breaks `php artisan config:cache`.** A
  per-request nonce is a closure, and a closure placed in `config/webhooks.php` makes
  `config:cache` (part of a normal production deploy) fail — the exact deploy the CSP audience runs.
  Register the nonce source at runtime instead, `UiTheme::resolveNonceUsing(fn () => Vite::cspNonce())`
  from a service provider; the config value is now a static string only, and a closure left in config
  raises a clear error naming the migration path rather than silently dropping the nonce.
- **The PostgreSQL hourly-rollup buckets stay on whole UTC hours under any database session time
  zone.** The bucket origin was resolved against the session zone, so a sub-hour-offset zone shifted
  every bucket boundary to `:30`, diverging from the MySQL rollup and from the package's own
  epoch-floor fallback. The origin is now pinned to UTC. Affects fresh installs; an existing install
  recreates the materialized view to pick it up.
- **A typo in the `dedupe` driver key is caught at config load instead of silently disabling the
  cache fast path.** `dedupe` was read without validation, so an unrecognised value quietly fell
  through to the database-only path (a performance regression under a retry storm, with no error).
  It is now validated against `redis+db` / `db` and throws on anything else, like every sibling
  config key.

## [1.3.0] - 2026-07-15

### Security

- **⚠ Behaviour change (action required): the dashboard and self-service authorization gates are now
  fail-closed.** `view-webhook-dashboard` and `manage-webhook-endpoints` previously returned `true`
  for **every authenticated user** when the host had not defined a `webhooks.view` / `webhooks.manage`
  ability — so a host that registered the provider but overlooked the ability silently exposed an
  operator surface to all logged-in users. They now **deny** until the host defines the ability
  (`Gate::define('webhooks.view', …)` / `Gate::define('webhooks.manage', …)`; see the README's
  dashboard and self-service sections). A host that relied on the permissive default must add the
  ability to restore access.
- **The self-service health matrix and payload-transform editor now scope at the query.** They loaded
  a subscription by id and authorized only afterwards; a foreign or tampered id is now filtered out at
  the query and fails not-found before any action runs — defence in depth, so a single policy
  regression can no longer be the only guard.

### Added

- **`InboundVerifier` — a verification seam that may do I/O, for providers a signature cannot
  express.** `SignatureScheme::verify()` is a pure function of body, headers and secret — the right
  contract for HMAC dialects, but some providers cannot be verified that way: one that signs nothing
  (authenticity is an authenticated API callback) or verifies through a cert-chain API keyed on a
  webhook ID rather than a secret. A client config may now set `verifier` to a
  `Webhooks\Client\Verification\InboundVerifier` class: container-resolved (so it may hold an HTTP
  client or API credentials), handed the `Request` and `WebhookConfig`, taking precedence over
  `scheme`, and making `secret` optional. Everything after verification — rate limit, dedupe, store,
  dispatch, the 401 path — is unchanged.
- **`dedupe_id` — derive the inbound idempotency key from the body, not only a header.** The receiver
  read the dedupe key exclusively from a configured header, but many providers carry no delivery-id
  header (the id is in the body, or none is sent), so the key stayed `NULL`, a `NULL` never collides
  with the partial-unique index, and dedupe **silently did nothing** for those producers. A client
  config may now set `dedupe_id` to `'header:Name'`, `'body:dotted.path'` (a path into the decoded
  JSON body), or a `Webhooks\Client\Dedupe\DedupeKeyResolver` class the container resolves — evaluated
  after signature verification, so the body is authentic. Unset keeps the previous header behaviour.
- **Signature header names are configurable for every scheme, not just the two first-class ones.**
  `WebhookConfig::scheme()` injected the configured `signature_headers` only into
  `StandardWebhooksScheme` and `Ed25519Scheme`; every other scheme — including the shipped
  `PlainHmacScheme` — kept its hard-coded default header, so a host binding a provider with a
  different header name silently rejected every webhook as malformed. Schemes now opt in via a
  `Webhooks\Core\Signing\AcceptsSignatureHeaders` interface (implemented by `PlainHmacScheme`,
  `GitHubScheme`, `StripeStyleScheme`), and the config injects **only** the header names the host
  explicitly set — an omitted key keeps the scheme's own default, so `GitHubScheme` keeps
  `X-Hub-Signature-256` and is never clobbered by the Standard-Webhooks fallback.
- **`PendingWebhook::dispatch()` now returns the queued `WebhookDeliveryData`.** A Server-only host
  had no delivery row and so nothing to correlate a send against its own log or a later status
  callback. `dispatch()`, `dispatchSync()`, `dispatchIf()` and `dispatchUnless()` now return the
  dispatched `WebhookDeliveryData` (the conditional ones return `null` when nothing is sent), whose
  `messageId` — stable across retries — is the correlation key. Backward-compatible: callers that
  ignored the `void` return are unaffected.
- **Timestamp query scopes on the log models, so a host querying the tables cannot bind a naive,
  silently-wrong timestamp.** Every timestamp column is `timestamptz` (PostgreSQL) or a UTC-naive
  `DATETIME(6)` (MySQL); a plain `->where('created_at', '<', …)` binds a naive literal the database
  resolves against its **session time zone** — unrelated to `app.timezone` and routinely not UTC — so
  the comparison is off by that offset and quietly returns the wrong rows. `WebhookDelivery`,
  `WebhookCall` and `WebhookServerDelivery` now carry `createdBefore()`, `createdAfter()`,
  `createdBetween()` (half-open) and the general `whereTimestamp(column, operator, moment)` scopes,
  plus `WebhookDelivery::pendingSince()`; each binds the instant per dialect.
  `(new WebhookDelivery)->boundTimestamp($moment)` exposes the same offset-correct literal for a raw
  statement. New README section "Querying the tables yourself".
- **Operator dashboard mode — observe the global, owner-less endpoints.** The package supports global
  (owner-less) subscriptions that receive every event, but the dashboard could not show them: every
  read scoped hard to the owner morph pair (which SQL equality never matches against `NULL`). Setting
  `dashboard.operator = true` (`WEBHOOKS_DASHBOARD_OPERATOR`) now scopes the whole dashboard to the
  owner-less rows. It shows *global rows only* — never one tenant's rows to another — to whoever the
  `view-webhook-dashboard` gate admits, so gate that ability to operators.
- **Prefix-wildcard subscriptions (`order.*`).** With `platform.wildcards` on (off by default), a
  subscription may list a prefix wildcard: a concrete `order.line.added` is delivered to subscribers
  of `order.line.added`, `order.line.*` and `order.*` — one prefix per dot boundary. Each arm is still
  an indexed `whereJsonContains`, so the GIN / multi-valued index serves the fan-out unchanged; a
  dot-less type still matches only exactly.
- **`webhooks.schedule.enabled` — opt out of the package's own scheduled maintenance.** A
  DB-per-tenant host must not run partition rolling, secret revocation, the rollup refresh, health
  sweeps and log pruning against the central database only. Setting `webhooks.schedule.enabled =
  false` now makes the package register nothing in the scheduler; the commands are unchanged and the
  host runs them inside its own tenant loop. Defaults to `true`, so a single-database app is
  unaffected.
- **The shipped UI mounts in a host app with its own asset pipeline and a strict CSP.** Two additive
  config options fix both blockers without forking the layout: `ui.assets` names a Blade partial the
  full-page layouts `@include` in `<head>` (your `@vite` tags), and `ui.csp_nonce` (a string or
  per-request callable, e.g. `fn () => Vite::cspNonce()`) puts a nonce on the inline theme script.
  Both default to null. New README section "Embedding in an app with its own asset pipeline and a
  strict CSP".

### Fixed

- **The owner morph-key type is consistent, and a non-integer owner is rejected up front.** The
  `webhook_subscriptions` table created its owner columns with `nullableMorphs()` on PostgreSQL —
  which follows `Schema::defaultMorphKeyType()` — while the delivery-log and dashboard-rollup DDL
  hard-coded `owner_id` as `bigint`. A host that set UUID morph keys got a subscriptions table it
  could populate but a delivery log it could not. The owner columns are now explicitly `bigint`
  everywhere, and `WebhookManager` rejects a non-integer owner key with a clear message at
  `subscribe()` time. Integer-keyed owners (the default) are unaffected.
- **`large_payload` offload no longer defaults its threshold to 0.** `Settings::largePayloadThreshold()`
  fell back to `0` instead of the documented `262144`, so a host that enabled `large_payload` in a
  trimmed config block without an explicit `threshold` would offload **every** delivery payload to
  disk rather than only the large ones. The accessor now carries the documented 256 KiB default.

## [1.2.0] - 2026-07-15

### Added

- **`webhooks:import-spatie-calls` — a one-command backfill from `spatie/laravel-webhook-client`.**
  Adopting the inbound Client layer no longer means starting with an empty log: this artisan
  command copies an existing spatie `webhook_calls` backlog into this package's own table, on
  **PostgreSQL or MySQL**. It maps their columns onto the superset (`name → source`,
  `payload`, `headers`, `exception`), preserves the original timestamps, and is **idempotent** —
  each imported row's key is derived deterministically from its source, so it is safe to re-run and
  a second pass imports nothing new. `--dry-run` reports the counts before writing;
  `--from-table`, `--from-connection`, `--chunk` and `--source` cover a differently-named source
  table, a source database other than the app default, memory-bounded batches over a large
  backlog, and a forced `source` value. Because spatie stored only the parsed payload and never the
  raw received bytes, an imported row carries a **reconstructed, self-consistent** `body_sha256`
  (not the producer's original) and is written in a terminal state — `processed`, or `failed` when
  spatie recorded an exception — so importing months-old history never re-fires a handler's side
  effects. The README's *Coming from spatie* section documents the full flow.

## [1.1.0] - 2026-07-15

### Added

- **MySQL 8.4+ is now a first-class storage engine, alongside PostgreSQL.** The persistent
  layers (Platform, Client, Dashboard and standalone persistence) run on **either**
  PostgreSQL 13+ or MySQL 8.4+, and every guarantee holds identically on both: exact
  percentile numbers, race-free de-duplication, the `body_sha256` byte-fidelity promise, the
  database-enforced `ON DELETE CASCADE` erasure cascade, DST-safe timestamps, and
  case-sensitive identity. What MySQL trades away is storage *optimizations* (O(1)
  partition-drop retention, partial indexes, the optional `tdigest` percentile tier), never
  correctness. Choose the engine your application already runs on — the new **Choosing your
  database** section in the README states the differences, with a tip and a recommendation for
  each. MariaDB is rejected with a clear error (its `JSON` is a text alias and it lacks the
  multi-valued and functional indexes the engine relies on).
- **A dedicated database connection for the package's tables.** Set
  `webhooks.database.connection` (env `WEBHOOKS_DB_CONNECTION`) to keep every webhook table on
  a connection other than the application default — the headline case being a MySQL
  application with a PostgreSQL side-car. The models, migrations and analytics queries all
  resolve the same configured connection, so the package never splits across two databases;
  left unset, everything stays on the application default. `webhooks:preflight` reports the
  resolved connection and, on MySQL, checks it against every requirement.
- **A migration guide for `spatie/laravel-webhook-server` and `spatie/laravel-webhook-client`
  users** — the field mapping onto this package's superset, on MySQL or PostgreSQL.

### Changed

- **Persistence is no longer PostgreSQL-only.** The 1.0.x line documented the storage layer as
  PostgreSQL-only by design; that is now retracted — MySQL 8.4+ is fully supported. Two
  changes are visible to an existing PostgreSQL application on upgrade, both safe: the delivery
  log gains a plain `created_at` index (previously only partial and composite indexes existed;
  the index keeps retention cheap on both engines), and the delivery-log primary key orders by
  a time-ordered UUID, preserving insert locality. Re-publish and review the migrations before
  upgrading a populated database.
- The `Webhooks\Database\PostgresRequirement` guard (reached only by a migration copy published
  from 1.0.0) now names the layer and the ways forward — re-publish for the MySQL schema, point
  at a PostgreSQL connection, or run send-only — instead of pointing only at Neon.

### Fixed

- **Send-only and receive-only apps are now isolated by the configuration gate, not by a data
  convention.** The delivery gate was rebound to the subscription-reading gate unconditionally,
  so a send-only host that set a `subscription_id` delivery-meta key would query the
  `webhook_subscriptions` table — one its configuration never migrated. The rebind now happens
  only while the Platform layer is enabled, so a send-only or receive-only app keeps the open
  gate.

## [1.0.1] - 2026-07-14

### Fixed

- **The package could not be used at all in an application that pins the date class to
  `CarbonImmutable`.** `Date::use(CarbonImmutable::class)` is a common hardening: it makes
  accidental in-place date mutation impossible. Under it, Eloquent hands back a
  `CarbonImmutable`, but `HasZonedTimestamps::asDateTime()` declared the **mutable**
  `Illuminate\Support\Carbon` as its return type — so every timestamp read on every model in
  this package raised
  `TypeError: ... Return value must be of type Illuminate\Support\Carbon, Carbon\CarbonImmutable returned`,
  and `Webhooks::subscribe()` threw on the very first endpoint. The return type is now
  `Carbon\CarbonInterface`, which is the honest contract — the method shifts a timestamp into
  the application's timezone and does not care whether the instance is mutable. Behavior is
  unchanged for an application that does not pin the date class.

  The suite could not see this because the workbench never pinned the date class; it now
  does, in `tests/Feature/ImmutableDatesTest.php`, which fails on the old return type.

## [1.0.0] - 2026-07-13

A ground-up rewrite into an all-in-one, config-gated webhooks toolkit for Laravel —
send, receive, observe and self-serve, with each layer switched on independently. The
delivery and receiving engine is now entirely in-house (no third-party webhook-engine
dependency), Standard Webhooks signatures are the default, and the storage layer is
PostgreSQL-native.

### Added

- **In-house delivery engine (Server layer).** A fluent, immutable `PendingWebhook`
  builder that signs, queues and sends outbound webhooks, with exponential backoff and
  full jitter, Retry-After awareness, per-call timeouts, SSRF-pinned connections, mutual
  TLS, a forward proxy, tags, metadata, and queue/connection selection. Optional
  standalone delivery persistence records every delivery for consumers that send without
  the Platform layer.
- **Standard Webhooks signatures by default** — `webhook-id` / `webhook-timestamp` /
  `webhook-signature` over `{id}.{timestamp}.{rawBody}`, byte-compatible with the
  specification and its official SDKs. Additional dialects: the generic `t=,v1=`
  Stripe-style adapter (the format 0.x sent, so existing consumers keep verifying),
  Stripe, GitHub and plain-HMAC receive adapters; asymmetric Ed25519 (the `v1a` variant)
  with JWKS support for rotating provider keys; zero-downtime secret rotation; and
  optional canonical-JSON signing. Switching on `server.signing.ed25519` signs every
  outbound delivery with the Server's own Ed25519 key, so a receiver holds nothing but a
  public key — and an enabled flag without a key is a hard error, never a silent fall
  back to HMAC. Published interop vectors under `resources/interop` let a third party or
  an other-language port prove byte-for-byte compatibility: the canonical symmetric
  example, a deterministic Ed25519 `v1a` known-answer vector, and negative vectors that
  must fail to verify — each re-checked against the engine by a test, so the published
  contract can never drift.
- **Inbound receiving (Client layer, opt-in).** Verify, de-duplicate, store and queue
  incoming webhooks via the `Route::webhooks()` macro or the controller-less processor:
  `401` on an unverifiable signature, replay protection, two-tier idempotency, raw-body
  capture, per-source rate limiting, header redaction, and event-type-to-handler
  routing. An app verifies its own deliveries with `scheme => 'auto'`.
- **Platform layer.** Endpoint subscriptions and event fan-out
  (`WebhookEvent::dispatch`), an event catalog with optional JSON-Schema payload
  validation, a per-endpoint circuit breaker and rate limit, and a monthly
  range-partitioned delivery log with scheduled partition maintenance and retention.
- **Self-service portal (opt-in).** A tenant-scoped Livewire/WireKit surface where a
  customer manages its own endpoints — list, create/edit, reveal and rotate the signing
  secret, a health matrix, and a payload-transform editor — guarded by a gate and a
  row-level policy so a tenant only ever sees the endpoints it owns.
- **Endpoint health scoring (opt-in).** A 0–100 score per endpoint blended from success
  rate, p95 latency and the consecutive-failure streak, with a refresh command and
  cached columns. When continuous scoring is on, the refresh command is also scheduled
  (cadence configurable via `platform.health.refresh`, default every fifteen minutes) to
  sweep every active endpoint, so an endpoint whose traffic dries up decays to its true
  band instead of freezing on the last score a delivery left it.
- **Per-endpoint payload transforms and versioning (opt-in).** A safe, declarative
  transformer (include / exclude / rename / rewrap plus a stamped version) reshapes the
  body per endpoint before it is signed, so two endpoints on one event can receive
  different, versioned bodies.
- **Observability dashboard (opt-in).** A customer-facing analytics UI over the delivery
  log — KPI cards, hourly activity, latency percentiles, a live delivery queue, top
  events, and a sortable, filterable deliveries table with one-click redelivery — on an
  hourly materialized-view read model with a refresh command, plus an optional
  high-volume percentile path.
- **JSON metrics endpoint (opt-in, `dashboard.expose_json_api`).** Serves the dashboard's
  own read model as JSON at `GET /webhooks/api/metrics?window=24h` — the KPI counts, the
  retry rate, the latency percentiles, the hourly buckets and the busiest event types — so
  a host can drive its own charts, status page or alerting from the same numbers. It runs
  behind the dashboard's middleware and `view-webhook-dashboard` gate, is scoped to the
  acting tenant, validates the window against the configured set (an unsupported one is a
  `422`), and exposes aggregates only — never a delivery row, payload or secret. The route
  is not registered at all while the flag is off.
- **Translatable UI, shipping seven languages** — English, German, Spanish, French, Italian,
  Dutch and Portuguese. Every string the shipped UI puts in
  front of a user is resolved through the `webhooks` translation namespace, across every
  surface: the observability dashboard, the self-service endpoint portal, the publishable
  management stubs (neutral and WireKit) and the Laravel Pulse card. That means headings,
  labels, placeholders, buttons, empty states, toasts, table headers, status and health
  badges, the forms' validation messages, the signing-secret countdown, and the accessible
  names a screen reader announces. Status and health values are translated for display
  only; the persisted value is unchanged. The rendered locale is the host app's, and
  `--tag=webhooks-lang` publishes the files so a host can override any string or add a
  locale. Two tests keep it honest: key parity holds every locale to the same key set, and
  a reference check resolves every key the shipped views and PHP actually ask for — so
  both a missing translation and a misspelled key fail the build instead of quietly
  rendering English, or the raw key, to a reader. Every non-English locale is written in
  the informal register throughout, and each locale's date patterns are authored for its
  own ordering, not just its month names.
- **Operational tooling.** A published egress-IP allowlist command and optional forward
  proxy, an AsyncAPI 3.0 export command, an Ed25519 keypair command, optional full-text
  search over the logs via Laravel Scout, an internal-ops Laravel Pulse card, and a
  dependency-free OpenTelemetry span seam — each off by default.
- **A Tailwind source registration for the shipped screens.** `resources/css/webhooks.css`
  registers the package's views with the host's Tailwind build in one import; the README's
  new "Styling the UI" section documents it, together with WireKit's own (also required)
  source glob and the optional icon set.
- **Dark mode for the package's own layouts,** through `ui.theme` (`auto` / `light` /
  `dark`, `WEBHOOKS_UI_THEME`). It mirrors the reader's system preference by default; a
  pinned theme emits no inline script, which is the escape hatch under a strict CSP.
- **A token-styled, translatable pagination control** (`webhooks::pagination`), rendered by
  every paginating screen in place of the framework default.

### Changed

- The package is now an all-in-one superset spanning sending, receiving, a self-service
  portal and an observability dashboard, replacing the previous send-only product.
- Configuration is reorganized under `core` / `server` / `platform` / `client` /
  `dashboard` (plus `pulse` / `search` / `otel`); the previous flat keys are gone.
- Each layer is gated by its own `enabled` flag: `server.enabled` and `platform.enabled`
  now switch their providers on or off (enabling the Platform layer implies the Server
  engine, since fan-out delivers through it), so a send-only or receive-only app omits
  the machinery and tables it does not use. The two dependencies between the gates —
  Platform implies Server, and the Dashboard reads Platform's delivery log — are stated
  in the README's layer table and inline in the config, where an operator meets them.
- Every `server` setting is now the default for **each** outbound call, not only for
  Platform fan-out: the signing dialect (`core.signing.scheme`), HTTP verb, connect and
  response timeouts, try count, TLS verification, canonicalization, the Retry-After
  policy and the backoff base/cap all seed a `PendingWebhook`, which still overrides any of
  them per delivery.
- `core.egress.enabled` is now a real, fail-closed gate: a configured egress proxy is
  routed through only while the egress layer is switched on. Because a proxy resolves the
  destination host itself, it weakens the SSRF IP pin, so it may not take effect merely
  because a URL was left in the environment.
- The default signature is Standard Webhooks, replacing the earlier single `t=,v1=`
  header. The Stripe-style dialect remains available as a receive adapter.
- The standalone `Webhooks\Signing\SignatureVerifier` helper is gone; verify inbound
  Stripe-style signatures with the `StripeStyleScheme` receive adapter instead.
- The delivery layer no longer depends on any third-party webhook-engine package;
  the `spatie/laravel-webhook-server` dependency that powered 0.1.0 has been removed
  in favor of the in-house engine (Standard Webhooks signing plus the native delivery
  pipeline).
- Migrations were recut for the new storage layer; re-publish and review them before
  upgrading a populated database.
- The dashboard and portal screens follow the design system more closely: the KPI ribbon
  and its loading placeholder share one stats grid, the drawer payload is a copyable code
  block, empty states carry icons, sortable headers are clickable across the whole cell,
  delivery times are localized (relative in the table, absolute on hover), and spacing runs
  on design tokens throughout. The dashboard tab labels are now stored display-ready in the
  translations instead of being cased by CSS.
- Both page shells now expose a `main` landmark and a skip link (WCAG 2.4.1), and every
  action that mutates data is disabled while its request is in flight.
- The publishable stubs are a better reference: deleting an endpoint is confirmed first,
  the zero-row case renders an empty state, and the action column carries an accessible
  name.
- The scaffolding placeholder view `webhooks::example` has been removed.
- The documentation is written for the reader who meets the package for the first time:
  the layer table names the two gate dependencies, the dashboard, the portal and the
  operator console each lead with the packages they need before the first line of
  code, every event the package dispatches is documented in one place with the rule that
  gates it, every publishable tag is listed in one table, the `0.x` upgrade path names the
  adapter that keeps existing consumers verifying and the helper that is gone, and the
  requirements distinguish the layers that need PostgreSQL from a send-only app that
  needs no database at all.

- **The public API says what it is.** Every class that is not part of the advertised
  surface is marked `@internal`, and the README's Versioning section names the surface
  that is not: the manager and facade, the sending builder, the receiving pipeline and
  `ProcessWebhookJob`, the signing contracts and shipped schemes, the SSRF guard, the
  models, enums, exceptions and events, the service providers, the Livewire aliases, the
  published views and migrations, and the config tree. Everything else — the delivery
  pipeline, the response classifier, the config reader, the dashboard's metric objects —
  may change in a minor. A test enforces the boundary, so it cannot erode quietly.
- **No two public classes share a name any more.** The outbound builder is
  `Webhooks\Server\PendingWebhook` (freeing `WebhookCall` to mean the stored inbound
  call, as the `webhook_calls` table always did), the receive-side envelope is
  `Webhooks\Client\InboundMessage` (the signed-bytes unit keeps
  `Core\Signing\WebhookMessage`), and the engine's config reader is
  `Webhooks\Support\Settings`. An app that both sends and receives can now import what
  it needs in one file without aliasing.
- **The transport events are named for what they are.** `Webhooks\Server\Events\*` is
  the per-ATTEMPT family — `WebhookAttemptStarting`, `WebhookAttemptSucceeded`,
  `WebhookAttemptFailed`, `WebhookAttemptRetrying`, `WebhookAttemptDeferred`,
  `WebhookAttemptsExhausted`, plus the once-per-delivery `WebhookDeliveryDispatching` —
  while `Webhooks\Events\*` stays the delivery's final domain outcome. The two families
  no longer share class names, so picking the wrong `WebhookDeliveryFailed` from IDE
  autocompletion (and notifying an endpoint's owner on every retry instead of once) is no
  longer possible. Both families, and the rule that gates them, are documented.
- **The signing namespace reads consistently:** `StripeScheme` and `GitHubScheme`
  (no redundant `Signature` inside `Core\Signing`), and the receive-side event is
  `InvalidWebhookSignature`, matching the other eight events that carry no `Event` suffix.
- **Endpoints have a lifecycle API.** `Webhooks::enable()`, `disable()` and
  `unsubscribe()` join `subscribe()`. `enable()` clears the circuit-breaker streak along
  with the flag — re-activating an endpoint by hand left the streak standing, so the next
  final failure instantly re-disabled the endpoint an owner had just fixed. `is_active` is
  no longer mass-assignable, so the wrong recipe cannot be written by accident, and both
  UI surfaces call the manager instead of hand-rolling the three columns.
- **One convention for every embeddable name.** Livewire aliases and route names are all
  dotted under `webhooks.` — `webhooks.dashboard.page`, `webhooks.self-service.portal`,
  `webhooks.admin.subscriptions`, `webhooks.pulse.deliveries` — replacing the three
  conventions that had grown side by side.
- **The migration publish tags actually work.** `vendor:publish --tag=webhooks-migrations`
  mirrored the per-layer subdirectories into `database/migrations/client/…`, where
  Laravel's migrator (which globs one level) never found them: `php artisan migrate`
  silently skipped the published migration and the first request hit a missing table. Each
  layer now has its own tag — `webhooks-migrations`, `webhooks-client-migrations`,
  `webhooks-server-migrations`, `webhooks-dashboard-migrations` — publishing its files
  flat. Publishing also no longer depends on the Platform layer being enabled.
- **A layer that cannot work refuses to boot.** Switching the dashboard or the portal on
  while `platform.enabled` is false used to end in a raw PostgreSQL `relation
  "webhook_deliveries" does not exist`, from inside a panel query or a materialized-view
  DDL. Both now fail at boot with one sentence naming the two switches involved — the same
  treatment the PostgreSQL driver check already gave.
- The operator console (`webhooks.admin.*`) is documented as what it is: an UNSCOPED
  surface that lists and mutates every tenant's endpoints and deliveries, to be placed
  behind an operator-only gate. The tenant-facing surfaces are the self-service portal and
  the dashboard, both owner-scoped and policy-guarded.

### Removed

- Five configuration keys that nothing read: `core.http.verify`,
  `core.http.response_capture_bytes`, `dashboard.chart.library`, `dashboard.search.driver`
  and `dashboard.scope`, plus `search.driver` (the Scout engine is chosen in
  `config/scout.php`, never here). A published key is public API under Semantic
  Versioning — one that does nothing would have to be kept forever, and it makes every
  key beside it suspect. The dashboard is, and remains, tenant-scoped; its activity chart
  is drawn server-side as SVG and needs no chart library.

### Fixed

- **No transport error can escape the delivery state machine.** Only five cURL error codes
  reach the client as a connection failure; an expired, self-signed or hostname-mismatched
  certificate — and a connection reset or truncated transfer mid-response — arrive as a
  different exception entirely, and used to escape the pipeline, the job and every
  lifecycle event with it: the delivery row stayed `pending` for ever, the circuit breaker
  never counted the failure, and the queue re-released the job with no backoff at all.
  Every way the transport can fail is now a normal retryable outcome that flows through the
  events, the log, the backoff and the breaker. A `failed()` hook and a real backoff
  schedule back it up, so no job death can strand a delivery either.
- **A failed payload offload is no longer silent.** Laravel's filesystem reports a failed
  write by RETURNING FALSE, so a transient disk error used to leave a row pointing at an
  object that does not exist — the received body destroyed, unrecoverable. The write is now
  verified and a failure throws, which lets the producer's (or the queue's) own retry
  deliver the body again.
- **Secret rotation now revokes.** The rotated-away secret was kept for ever — it kept
  signing every delivery and kept verifying — so a rotation revoked nothing. The window is
  now bounded by `platform.secret_rotation_window_hours`, and when it closes the old secret
  is cleared from the row; `webhooks:revoke-rotated-secrets` (scheduled hourly) sweeps the
  endpoints that went quiet before theirs elapsed.
- **A lapsed schedule can no longer stop the partitioning for good.** A delivery that
  landed in the catch-all default partition made PostgreSQL refuse to create the partition
  that should hold it, so `webhooks:partition-maintenance` failed on every later run — and
  never reached the retention prune either. It now drains the default partition first and
  heals itself, reporting the drift it repaired.
- **A NUL byte in a payload no longer destroys the webhook.** PostgreSQL's `jsonb` cannot
  store one at all: inbound it produced a 500 on every retry until the producer gave up,
  outbound it threw mid-fan-out. Payloads are now scrubbed at the edge — once, before they
  are stored and before they are signed — so the stored copy and the delivered bytes stay
  identical.
- **A queued delivery is re-checked against its endpoint before it goes out.** A backlog
  used to keep firing at an endpoint the circuit breaker had just disabled, and — worse —
  at an endpoint its tenant had DELETED. Both are now refused before a byte is sent, and
  recorded on the delivery log with the reason. A replay into a disabled endpoint is
  refused too.
- **A stored inbound call hands back the exact bytes it received.** `body_sha256` promises
  byte fidelity, but the inline path re-encoded the parsed payload — losing the producer's
  whitespace, escaping and float formatting — and a body that did not decode at all (invalid
  UTF-8, a truncated payload, a JSON array) was stored as an empty payload with its bytes
  gone. The received bytes are now kept beside the parsed view, so `hash($call->body())`
  always equals `body_sha256`.
- **A hostile endpoint cannot answer a delivery with a decompression bomb.** The response
  was decoded and fully buffered before the capture cap applied, so a few kilobytes of gzip
  from a tenant-supplied endpoint could inflate to gigabytes inside a worker. Responses are
  no longer decoded, and only the capture prefix is ever kept.
- **A rate-limited event is no longer thrown away.** An over-limit delivery had no row, no
  event and no log line — the operator's first news of it was a customer reporting a webhook
  that never arrived. The limit now SHAPES the endpoint's traffic: the delivery is logged,
  announced (`WebhookDeliveryRateLimited`) and enqueued with a delay.
- **Timestamps are instants, not wall-clock strings.** Every timestamp the package bound
  into SQL was naive, so PostgreSQL resolved it against the database session's time zone.
  Under a non-UTC application timezone every row was written at the wrong instant, every
  metrics window covered the wrong span, and the DST fall-back hour collapsed two distinct
  deliveries onto one `created_at`. Every binding — Eloquent, raw SQL and partition bounds
  alike — now carries its offset.
- **The delivery log's reads and writes prune to one partition.** Locating a row by id
  alone gave the planner nothing to prune with, so every lifecycle event probed the index of
  every partition that had ever existed. The partition key now travels with the delivery.
- The job's timeout derives from the HTTP timeout it wraps, so raising `server.timeout` can
  no longer make the worker kill the job mid-request.
- The signing-secret countdown no longer leaks a timer: its interval is owned by the Alpine
  component and cleared when the reveal card is torn down, so hiding a secret can no longer
  leave a live timer behind.
- The payload-transform editor now names malformed sample JSON instead of silently
  previewing an empty object, and the output preview no longer re-announces the whole
  payload to a screen reader on every keystroke.
- The active dashboard tab now uses design tokens that exist (it silently never received its
  intended weight), and the sortable table headers no longer emit a class that never
  compiled.

### Security

- Tenant isolation now scopes and authorizes by the full `(owner_type, owner_id)` owner
  pair across the self-service portal, the dashboard and the row-level policies, rather
  than by `owner_id` alone. Because an endpoint's owner is a polymorphic relation, two
  tenants that share an owner id under different owner types are distinct tenants; the
  previous id-only checks could let one such tenant view, edit, delete or reveal the
  signing secret of another's endpoints and deliveries. The self-service create path also
  now stores the same owner identity the read scope resolves, so a created endpoint can
  never be owned by a different key than it is filtered by.
- The dashboard metrics, the hourly rollup and the optional search index now scope by the
  same full `(owner_type, owner_id)` owner pair. The KPI, activity, latency, top-events
  and recent-queue panels — and the `webhook_delivery_hourly` materialized view they read,
  which is now grouped and uniquely indexed by the owner pair — previously keyed on
  `owner_id` alone, so a tenant whose id collided with another owner type could see the
  other's delivery rows, event types and aggregate counts. The Scout delivery index now
  carries `owner_type` and `searchForOwner()` filters both columns. The dashboard's default
  tenant resolver also now prefers a current team over the user, matching the self-service
  portal so both resolve the identical tenant.

## [0.1.3] - 2026-07-11

### Fixed

- README PHP-version badge now uses the reliable `packagist/dependency-v` shields endpoint;
  the previous `packagist/php-v` route was rendering "not found".

## [0.1.2] - 2026-07-05

### Added

- Migrating the package against a non-PostgreSQL connection (MySQL or SQLite) now
  fails with one clear, actionable error instead of a cryptic SQL syntax failure —
  it names the offending driver and points to provisioning a Neon (PostgreSQL)
  database on Laravel Cloud. The package remains PostgreSQL-only by design.

### Changed

- Documented the Laravel Cloud database choice in the requirements: use the Neon
  (PostgreSQL) option rather than MySQL.

## [0.1.1] - 2026-07-02

### Changed

- Issue templates (bug report + feature request) now ship to the public repository
  automatically with each release, and a lean `.gitattributes` keeps the Composer
  dist minimal.

## [0.1.0] - 2026-07-02

### Added

- Customer-configurable outgoing webhooks on top of spatie/laravel-webhook-server:
  register endpoints per event type and fan an event out to every matching, active
  subscription with `WebhookEvent::dispatch()` (tenant-scoped or global).
- Postgres delivery log: uuid-keyed, monthly range-partitioned `webhook_deliveries`
  table with a partial index for open rows, plus `webhook_subscriptions` with a jsonb
  event-type list (GIN indexed), an encrypted signing secret, and a nullable owner morph.
- Versioned, Stripe-style HMAC-SHA256 signature (`t=<unix>,v1=<sig>`) signed at send
  time, with zero-downtime secret rotation and a shippable `SignatureVerifier` for consumers.
- SSRF-hardened delivery: every URL is validated at registration and again at send time,
  with the connection pinned to the validated IP to defeat DNS rebinding; private,
  loopback, link-local, unique-local, carrier-grade-NAT, multicast and cloud-metadata
  addresses are refused, and redirects are not followed.
- Stable per-event id for consumer idempotency, preserved across manual redelivery.
- Circuit breaker that auto-disables an endpoint after repeated final failures, and
  `WebhookDeliverySucceeded` / `WebhookDeliveryFailed` / `WebhookEndpointAutoDisabled` events.
- Per-endpoint rate limiting, Horizon tags, a configurable event catalog, and a
  `webhooks:partition-maintenance` command (scheduled daily) for provisioning and retention.
- Optional JSON-Schema payload validation: give an event type a `schema` in the catalog
  and enable `validate_payloads`, and a non-conforming payload is rejected with
  `InvalidPayloadException` before any delivery is created (off by default).
- Optional Livewire management UI shipped as publishable, restyleable stubs
  (`WebhooksUiServiceProvider`, not auto-registered), in two variants: neutral Tailwind
  (`webhooks-ui`) and WireKit-styled (`webhooks-ui-wirekit`).

[Unreleased]: https://github.com/pushery/webhooks-for-laravel/compare/v2.0.1...HEAD
[2.0.1]: https://github.com/pushery/webhooks-for-laravel/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.12.0...v2.0.0
[1.12.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.10.1...v1.11.0
[1.10.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.10.0...v1.10.1
[1.10.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.9.1...v1.10.0
[1.9.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.9.0...v1.9.1
[1.9.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.7.1...v1.8.0
[1.7.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.7.0...v1.7.1
[1.7.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.5.2...v1.6.0
[1.5.2]: https://github.com/pushery/webhooks-for-laravel/compare/v1.5.1...v1.5.2
[1.5.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.12...v1.5.0
[1.4.12]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.11...v1.4.12
[1.4.11]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.10...v1.4.11
[1.4.10]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.9...v1.4.10
[1.4.9]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.8...v1.4.9
[1.4.8]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.7...v1.4.8
[1.4.7]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.6...v1.4.7
[1.4.6]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.5...v1.4.6
[1.4.5]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.4...v1.4.5
[1.4.4]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.3...v1.4.4
[1.4.3]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/pushery/webhooks-for-laravel/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/pushery/webhooks-for-laravel/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.3...v1.0.0
[0.1.3]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/pushery/webhooks-for-laravel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/pushery/webhooks-for-laravel/releases/tag/v0.1.0
