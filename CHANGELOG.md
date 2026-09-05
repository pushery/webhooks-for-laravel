# Changelog

All notable changes to `pushery/webhooks-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-09-05

### Added

- **A host can declare which endpoints a reader may SEE.** The self-service delivery panel
  scopes by the denormalized owner pair, which answers *does this row belong to this owner?* An
  application that shares an endpoint has a second question — *may this person see this row?* —
  and the two part company at exactly that point: a member of an organization a destination is
  shared into is not the owner, so under owner scoping alone they see nothing.

  `Pushery\Webhooks\Platform\Support\ReadableEndpoints::resolveUsing()` takes a closure
  returning the endpoint ids that reader may read beyond the ones they own. A set of ids rather
  than a predicate, on purpose: a closure handed the query could drop the owner scoping, and
  then the panel's central promise would depend on host code. The resolver can only **add**.

  It reaches the delivery list, the endpoint filter and the filter's option list. It does **not**
  reach the replay, which still loads its endpoint through the owner-scoped lookup — so a reader
  who may see a shared endpoint's history cannot send from it. With no resolver registered
  nothing changes anywhere.

- **The delivery list shows how long each delivery took**, beside the response code it took
  that long to get. The package already measured it on every attempt. Bound to the code rather
  than standing alone: a failure that never got an answer also has a duration — the time spent
  failing to get one — and printing that beside an em dash would read as latency.

### Changed

- **BREAKING — `webhooks:import-spatie-calls` is now `webhooks:import-calls`, and it reads the
  shape you declare instead of one it assumed.** Five new options name the source columns
  (`--from-id`, `--from-source`, `--from-payload`, `--from-headers`, `--from-error`), and their
  defaults are exactly the columns the old command hardcoded — so an existing invocation needs
  only the new name. Any prior inbound-webhook table can now be imported, not just one shape.
- **BREAKING — the derived import key changed, so ids from an earlier import no longer
  re-derive.** Running the command over a source table you already imported would import all of
  it a second time; the old rows are indistinguishable from ones this package received itself,
  so nothing can detect it for you. `--dry-run` shows it before any write: over a backlog you
  already imported it must report everything as already present.
- The operator console's permission guidance names the mechanism rather than one package: the
  trap is any permission package that resolves through its own `Gate::before` hook and reads the
  first positional gate argument as a guard name. `admin.abilities` remains the way past it, and
  nothing about its behavior changed.

### Removed

- **BREAKING — the `exception` column is dropped from `webhook_calls`.** Nothing in this package
  ever wrote it: not the receive path, not the backlog import, not a listener. It was declared
  when the table was created, outlived the rewrite it came in with, and stood null on every row
  while reading like a feature — the documentation promised the import filled it, and the import
  never did. A migration drops it from existing installations; if you adopted the column for your
  own bookkeeping, copy the values out before migrating. The status it decided is unchanged and
  still imported.

  The shipped `WebhookCall` factory follows: its `failed()` state now sets the status alone,
  which is the whole of what that state means here — this log records *that* a call failed, not
  what was thrown. If your own tests asserted on the value that state used to write, that
  assertion goes.

### Fixed

- **A replay refused by the engine is answered with a sentence instead of a 500.** The panel
  checks whether the endpoint is active on the row it loaded; the manager re-reads the
  subscription off the delivery and checks again. Between the two the endpoint can be switched
  off — by the circuit breaker on a concurrent failure, or by the tenant in another tab — and
  the refusal then arrived over an ordinary button press. The window is small and not
  hypothetical: the breaker disables an endpoint precisely while its deliveries are failing,
  which is when somebody is looking at that list and pressing Send again.

- The localization guide now lists all six shipped translation files. `formats` — the thousands
  and decimal separators every screen counts with — was missing from its table, so a host whose
  house style disagrees with ICU's had no documented place to change them. That file's own
  comment pointed at the wrong place for date patterns as well: those sit under each surface's
  own `formats` key, not beside the separators.

## [2.6.1] - 2026-09-05

### Fixed

- **A dashboard reader who belongs to no tenant sees an empty page again, not a 500.** `DashboardScope::current()` resolved the acting tenant through a method that promises one and therefore had to throw when the resolver returned nothing — so the standalone dashboard answered `500` for every state where `view-webhook-dashboard` lets somebody in and no tenant comes back: an operator on a fresh installation before the first tenant exists, a support account with no company of its own, and any test asserting the empty state.

  The ability is documented as the way into the dashboard. A state the ability admits and the page cannot survive is a defect rather than a configuration question, and until v2.3.0 the same state simply rendered nothing.

  It is now a scope of its own rather than one of the three that already existed, because none of them answers it. `all_tenants` would show every other tenant's delivery history — a wider permission traded for an empty state. `operator` would show the owner-less rows, which answers a different question than "mine, and there are none". So the new scope resolves to nothing, on the raw tables and on the hourly rollup alike, and its per-row guard admits nothing: an untenanted reader must not end up seeing exactly what an operator sees.

  **The exception message was misleading too, and that is fixed separately.** It said "Register a resolver with `DashboardScope::resolveUsing()`" in both cases — including the one where a resolver *was* registered and simply had nothing to return, which sent the reader to the one place the problem was not. The two states are now told apart, because they are repaired differently: one is a wiring step nobody took, the other is an ordinary runtime state.

## [2.6.0] - 2026-09-05

### Added

- **An operator surface can make the endpoint filter binding instead of merely narrowing.** `EndpointDeliveries` gained `strictEndpoint`, off by default, so nothing changes for anyone who does not set it. With it on, an endpoint id that stops resolving closes the list rather than dropping out of it.

  The default is right where the filter narrows a set the reader may see anyway: losing it widens back to the tenant's own deliveries, which is a plausible reset and reveals nothing that was hidden. It is backwards where the endpoint *is* the definition of what may be seen — there "nothing" is the correct answer to a destination that no longer exists, and a reload after a deletion would otherwise list deliveries belonging to other destinations.

  It is also the failure direction that stays quiet: a filter that falls back to empty looks exactly like a filter somebody cleared. The property is `#[Locked]`, because unlike every other public property on that component this one's dangerous direction is being switched **off** — the others can only ever cost a reader information, and a value from a browser must not be able to widen what the host switched on to keep closed.

  The trade is stated rather than discovered: this mode keeps stale ids on purpose, so it cannot tell a deleted endpoint from one that was never the tenant's, and answers both the same closed way. The default still answers a cross-tenant id with a not-found.

## [2.5.0] - 2026-09-04

### Added

- **A consumer can pick the secondary button surface without publishing the view.** `ui.secondary_surface` (`ghost`, which is what the two operator screens have always drawn) is read where the value used to be written into the markup. A design system usually settles on one secondary style, and a borderless untinted button beside a tinted one reads as a third rank where there are two — so the only way to change it was to publish the view and carry the diff through every update, for a style question.

  Scoped to the operator screens deliberately. The self-service views also use `ghost`, and several of those are icon-only controls inside a table row, where a third rank is exactly right; widening this without measuring which is which would restyle buttons nobody asked about.

- **`webhooks:preflight` can be told which inbound clients this application requires.** `webhooks.client.expected` is a list of client names, empty by default, so nothing changes until you fill it in. Every other fault the preflight reports is found by reading the entries that exist, which leaves exactly one it could never see: an entry that should exist and does not. Only your application knows it expects a client called `github`; a package cannot infer an expectation nobody stated.

### Changed

- **A `dedupe_id` on a body-only dialect is described as what it is: retry dedupe, not replay protection.** The config file and the receiving page both called it "the only replay boundary the source can have", and that sentence had already been used as the reason to close a separate finding as covered. On a dialect that signs the body alone, the header the key is read from is not covered by the signature, so the sender chooses it: it separates a real producer's honest retries — worth having — and holds nothing against somebody replaying a delivery they captured, who can simply write a different id.

  Nothing in configuration can turn it into a replay boundary; only a signed timestamp bounds a replay, and a dialect either has one or it does not. Both places say that now, and a test holds the corrected wording, because the claim mattered more than its phrasing did.

- **The shipped config says where the receiving edge stops, instead of leaving it to be discovered.** Nothing in this package caps the size of an incoming body — `post_max_size` is what bounds it — and a large delivery is stored inline and carried a second time in the queued job. That was true before and written down nowhere. The config file and the receiving page now name it, along with the two levers that answer the two halves: `large_payload` for the storage cost, and a limit in front of the route for a refusal, because answering `413` to a producer is a policy about who may send you what.

### Fixed

- **Two comments in the shipped source spell their words the American way.** `customisations` in `UiVariant` and `optimisation` in `WebhookProcessor` had been sitting there across several releases. Nothing behaves differently — the package's house rule is US spelling across everything it publishes, and code comments ship with a library.

  The check that holds that rule could not see either one, which is why they lasted. An `-ise` verb keeps its `e` in the forms that merely append and throws it away in the two that do not, so a pattern written against the verb reaches `optimised` and `optimises` but neither `optimising` nor `optimisation`. It reads the derived forms now.

- **A cache outage no longer closes the receiver.** The fast-path dedupe read the cache unguarded, so an unreachable store propagated its connection error all the way out and every delivery was answered `500` — while the partial-unique index that actually guarantees uniqueness was working the whole time. The config file describes that cache as an accelerator in front of the index, and in this state it was a hard dependency.

  All three cache touches now fall through instead. The read degrades to "not seen", which costs a database round trip per retry and cannot produce a duplicate; the marker write is skipped for a delivery that already succeeded; and the rollback's withdrawal no longer replaces the dispatch failure it is compensating for with a cache error — a store that cannot forget could not have been marked either.

  Nothing is reported from these paths on purpose: a cache outage means every delivery takes them, so a line each would turn one incident into a second one made of log volume.

- **A receiving route is bound to its client config, instead of merely defaulting to it.** `Route::webhooks()` pinned the name with `defaults()`, which is only what a route PARAMETER falls back to — so a URI written with a literal `{webhookConfigName}` segment let the caller choose the config. A route meant to be pinned to one source processed a delivery under another, and the stored row then disagreed with the route about where it came from.

  The signature still had to match the chosen config, so nothing was forged; what was lost is the binding. And it is not far-fetched to reach: the parameter name is readable in any route listing, and a URI with placeholders is the obvious shape for a multi-tenant mount. The name lives in the route's action now, where nothing in the URI can reach it.

- **The signature covers the timestamp exactly as the producer wrote it.** Verification normalized the header to an integer and signed that, which was wrong in both directions and measured in both. A producer sending `01700000000` and signing `{id}.01700000000.{body}` — which is what the specification says is signed — was refused, because this side computed the plain spelling instead. And a delivery signed canonically stayed valid after its header was rewritten to a different spelling of the same instant, because both normalize to the same integer.

  Neither buys an attacker anything: the id and the body are covered either way, and the tolerance window reads the same instant. The first is an interop refusal, though, and fixing it also makes the header cover itself. Nothing changes for a delivery this package signed — the signer has always written a plain decimal.

- **A verified delivery carrying a number no double can hold is stored, instead of failing for ever.** `json_decode` turns a literal past the double range — `1e400` — into `INF`, and `json_encode` has no literal to write it back with, so it threw. That happened after the signature was checked, in the storing path: the producer got `500`, retried, got `500` again, and the delivery was never stored. It takes the shared secret to reach, so this is not an attack — it is a currency or measurement source with a very large value in the payload.

  The three floats JSON cannot write are now stored under their own names, `INF`, `-INF` and `NAN`, rather than as null: null cannot be told apart from a field that was absent. The exact received bytes survive alongside the cleaned view as they always have, so nothing is destroyed.

- **A secret of nothing but whitespace is read as absent, instead of passing every check and then failing every delivery.** Two tests of the same value disagreed: the config builder asked whether the string was empty, while `SecretSet` — built once per request — rejects anything empty after trimming. So `secret => ' '` was configured as far as the builder was concerned, silent in `webhooks:preflight`, and threw on every incoming delivery. That is a `500`, which tells the producer to try again, so it does, and the installation reads as a transport problem rather than as a typo in a config file.

  `previous_secret => ' '` is the half that broke a working install: it reached the rotation path and threw there, so a correctly signed delivery was answered `500` too. A rotation slot with nothing in it is now simply empty.

  The value itself is not trimmed, only the question of whether it is empty. Silently rewriting a credential would be a worse habit than refusing an unusable one.

- **`webhooks:preflight` names a receiving route bound to a client that is not configured.** A typo in the second argument of `Route::webhooks()` leaves a route that exists, resolves, and refuses every anonymous delivery for ever. The deployment is green and the configuration is not wrong — it simply has no entry of that name — so the first thing that reports the mistake is the producer's own dashboard.

  It is the mirror of `webhooks.client.expected`, and the pair covers both directions a binding can break in. That setting is a host saying "a client of this name must exist"; this check needs no setting at all, because mounting an endpoint is already that statement.

- **The operator delivery log opens on a window instead of on every partition.** Both of its date fields started empty, so the first render of a fresh instance issued a query with no lower bound on `created_at` — and `webhook_deliveries` is range-partitioned by month, so such a query cannot be pruned and reads every partition there is. Nothing about it looks wrong: the page loads, and on a young installation it is fast. The cost arrives with the data, months later, and reads as a database problem rather than as a default nobody set.

  `ui.deliveries.default_window_days` (30) is a **default**, not a ceiling, and that is the difference between this screen and the two tenant-facing lists. Their properties are writable from the browser by whoever is looking, so `EndpointDeliveries` and the dashboard table may only ever narrow their window. This is the operator's own console: the date is preset in the From field where it can be seen, and clearing it reaches the unbounded query — deliberately. `0` restores the previous behavior.

- **A provider whose JWKS cannot be reached is answered as a refusal, not a `500`.** Resolving the key material for a `jwks` source is an outbound HTTP call, and it sat inline as an argument to the verification. A provider that was down, serving a maintenance page or simply unreachable threw past the controller, and every anonymous `POST` came back `500` — the one answer a producer reads as "try again", on the path this package promises is never a `500`.

  It made the outage worse in both directions. An unsuccessful fetch is deliberately not cached, so one bad minute at the provider does not reject every delivery for the rest of the hour — which means each request tried the fetch again, and an anonymous caller could point that amplifier at the provider in your name.

  A key set that could not be resolved is what `undetermined` already meant here: the verification did not complete, and a retry could resolve it. It answers with `undetermined_status`, which falls back to `invalid_status`, so an installation that has not opted into a distinguishable answer sees no change. The failure is still reported, because a provider whose JWKS stopped resolving is a real fault and answering `401` in silence would hide it.

  The receiving page gained a section on what this does **not** cover: nothing the package registers throttles a request before verification, `rate_limit` sits after it by design, and a host that wants a brake in front puts one in its own middleware stack. The page previously called `rate_limit` "the only brake on the receiving side", which read as a claim about the whole path.

- **A stored header blob no longer carries the producer's Basic-auth password in clear text.** With `store_headers` set to `*`, `authorization` was masked and the very same password sat one key further down under `php-auth-pw`, unmasked — which is worse than not masking at all, because the redacted line beside it makes the blob read as safe. `proxy-authorization` was stored whole.

  Those names are not headers a producer sends, which is why nobody had listed them: Symfony decodes an `Authorization: Basic` line and puts the two halves back as `PHP_AUTH_USER` and `PHP_AUTH_PW`. They are masked now, along with `PHP_AUTH_DIGEST` and `Proxy-Authorization`. The default is unaffected — `store_headers` stores nothing unless you ask it to — so this reaches the installations that turned it on and were relying on the redaction.

  The same redactor serves the backfill import, so both paths are covered. The test drives a real request rather than a hand-built header map: the names it is about do not exist until Symfony creates them, so a map-based arm would have been green against the unfixed code.

- **A replayed delivery no longer spends the producer's rate-limit bucket.** The check ran before the dedupe, so a repeat cost a token even though it caused no row, no offload and no job. The bucket is per source and not per sender, so anyone who captured one authentic delivery could replay it until the bucket was empty and leave the real producer answering `429` until the window rolled — and on a dialect that signs no timestamp, a captured delivery never expires, so the replay was not bounded by a tolerance window either.

  The refusal still runs where it did, right after verification and before anything is stored. What moved is the counting: a delivery recognized as one already taken spends nothing. A delivery your own profile filters out still counts, deliberately — it is a distinct one the producer sent, and making it free would open an unlimited channel through the check.

- **Every command the package schedules now runs on one server, not on all of them.** The Laravel scheduler fires on every application server by design, and each of these writes shared state — partitions, pruned rows, cached health scores, the dashboard rollup. Only `webhooks:partition-maintenance` carried `onOneServer()`; the other five did not, so on a cluster each one had as many concurrent writers as you have nodes. The two `model:prune` registrations also gained a bounded overlap guard, so a first prune over a long-retained log is not joined by the next day's.

  **The guide's advice on this was wrong in a way worth naming, because following it would not have helped.** It said `onOneServer()` needs a store from a list — `redis`, `memcached`, `database`, `dynamodb` — as though the others could not take a lock. They can: `file` and `array` implement the same lock contract. They are also exactly the two that fail on a cluster, because a file lock lives on one server's disk and an array lock in one process, so every server takes its own and each one decides it is the chosen server. Nothing throws and nothing is logged. The criterion is that the store is **shared between your servers**, which is a different question from whether it can lock, and the page says that now.

  The guard that holds this derives the command set from the source rather than listing it, so a command added later is covered the day it lands.

- **A delivery to a client whose config entry is missing is refused with `401`, not answered `500`.** The route does not disappear with the entry — it hangs on the `Route::webhooks()` macro, and the macro on `webhooks.client.enabled`, never on whether a client of that name exists. So a lost entry does not take the endpoint down, it turns it into a server error: a bad merge, an unset variable in a fresh environment, a renamed client.

  That matters because `5xx` is the one answer a producer reads as "try again", and not every producer retries at all. GitHub makes a single attempt at a release delivery, so a `500` there does not delay the event, it loses it — silently, because nothing ever arrived on the receiving side to be missed. The refusal is now the same one a rejected signature gets, deliberately: a producer must not be able to tell the two apart, and a host reading its own logs must not find two shapes for one event.

  An entry that was *present* but carried no verification material was already handled this way. This is the other half of the same fault, one step further along, and it is the same exception class for the same reason.

## [2.4.0] - 2026-09-04

### Added

- **The operator console picks its own rendering, so reaching the WireKit one no longer means publishing the views.** `webhooks.ui.variant` — `auto`, `wirekit` or `plain` — chooses between the two renderings the package already ships. `auto` takes the WireKit markup when WireKit is registered in the application.

  **On an application that already has WireKit registered, the operator console will look different after this upgrade** — that is the point of the setting rather than a side effect of it, and `WEBHOOKS_UI_VARIANT=plain` keeps the previous appearance. A console whose views you published is untouched either way.

  It asks whether WireKit is **registered**, not merely installed, and the difference is the honest one: a library sitting in the vendor tree but kept out of discovery renders no `<x-wirekit::…>` tag, so choosing its markup would produce exactly the unstyled screen the setting exists to prevent. There is no minimum-version option, because `composer.json` already refuses a WireKit below the tested floor — a second copy of that number would drift from the constraint and would fail at render time, on a screen, instead of at install time where it can still be fixed.

  **This exists because a publish takes the views out of the update path.** A host that published to get the WireKit rendering owns a copy that has to stay byte-identical to the package, or a fix lands in one and not the other. A consumer kept exactly that pair, deleted it when a release brought accessibility fixes they wanted, and their screen fell back to the neutral rendering — nothing threw, nothing logged, no test went red. A view rendered, and it was the wrong one.

  Both publish tags remain, for changing the markup rather than for choosing a style, and **a published view still wins over the setting** — including one published from the WireKit tag before the setting existed. That precedence is what keeps this from discarding the customizations of the people who did the work under the old instructions.

- **The tenant delivery panel can filter by outcome and by an exact day range, which the operator log could already do.** A customer looking at their own log could narrow it to one endpoint and to a window length, and then had to read. Asking "did any of yesterday's orders fail" meant paging. Both filters are the ones the operator console carries, so a consuming application that was keeping its own owner-facing copy for them can stop.

  The date pair is **added to** the time window rather than used instead of it, and that is the whole of what makes it safe on this surface: every public property here is writable from the browser, `webhook_deliveries` is range-partitioned, and a read with no lower bound visits every partition there is. Both bounds stay on the query, so a `from` older than the host's ceiling is ANDed with it and simply loses — a reader can ask a narrower question, never a wider one. The plausible tidy-up, replacing the window bound with the reader's, hands the browser exactly the unbounded scan the window exists to prevent, so a test sets `from` a year back under a seven-day ceiling and holds it.

  The outcome options are the delivery-status cases, looped, rather than five literal options: the three older consoles list them, and when `refused` was added two of the three were missed. An unrecognized status is ignored rather than passed to the query — filtering on a status nothing can have renders an empty table, which on a delivery log reads as "nothing was ever sent to you".

  The day reader both panels use is now one shared, `@internal` class instead of a copy each. It carries three defenses that each fail differently and silently: the shape is checked before Carbon sees it, because Carbon throws on the half-typed dates a live-bound input sends between keystrokes; the parse is compared back against the input, because Carbon rolls `2026-13-45` forward into a real date rather than refusing it; and the upper bound is the start of the day after the one named, so "up to the 20th" includes the 20th.

- **Every `WEBHOOKS_*` variable the shipped config reads is now on the configuration reference.** Eleven of twenty-eight were documented nowhere, and the omission was not systematic — the page listed `dashboard.operator` with its variable while, two bullets above it, `platform.self_service.enabled` had none. Among the missing were the https-only switch on endpoint URLs, the queue outbound deliveries are pushed onto, and the asymmetric signing key whose absence is what makes an ed25519 install fail to sign. A guard derives the set from the config file, so a variable added later is in the check the day it is added.

- **A guide for the search layer, which had none.** `choosing-your-database.md` said deliveries and calls are searchable "with Scout + Meilisearch (already wired)". Nothing was wired: `laravel/scout` is a Composer *suggestion*, `search.enabled` defaults off, and the model the dashboard reads is the un-indexed one until a host points `dashboard.source_model` at a searchable subclass — a step that existed only in a class docblock and a config comment. A reader who flipped the flag got silence: no index, no error, no log line. [Searching the logs](https://docs.pushery.com/webhooks-for-laravel/guides/searching-the-logs) now walks the three steps in order, and says what is indexed, what is never indexed, and why an index is not a screen.

- **The scheduled-maintenance guide gained the operations half it was missing.** The docs listed all six scheduled commands and their cadences correctly, and said nothing about how a host learns that one of them stopped — `onFailure`, a heartbeat, `onOneServer` appeared nowhere in the portal or the README.

  Every failure there is quiet, and they are not equally quiet: a stopped `webhooks:revoke-rotated-secrets` leaves a rotated-away signing secret valid indefinitely while the endpoint keeps working, which is the one that widens a security window rather than degrading a view. The new section says which is which, and names the three instruments for the three different failures — a failure hook for a command that ran and failed, a success ping for a scheduler that never ran at all (which no failure hook can see), and `onOneServer()` for more than one application server, with the caveat that it silently does nothing without a shared cache store.

- **The dashboard says when its counts are behind, instead of showing them beside live numbers as if they agreed.** The delivery counts are summed from a materialized rollup that only `webhooks:refresh-metrics` advances; the latency percentiles next to them are computed live. With the refresh stopped — a crashed cron, a mutex left by a hard kill, a thrown exception — frozen counts sat next to current percentiles that made them look plausible, and nothing on the screen said which was which.

  The lag is measured against the newest **delivery**, not against the clock. That is what keeps it quiet on a quiet installation: comparing the rollup to `now()` reports every endpoint with no traffic as stale, and a warning that is usually on is a warning nobody reads. No rows the rollup has not seen, no banner.

  It also stays silent while the rollup is merely between two runs: the threshold is twice the configured refresh cadence, and the hour a rollup is behind by design — it buckets hourly — is not counted as lag. Translated in all seven shipped locales.

- **`webhooks:preflight` now checks the MySQL collation the documentation said it checked.** The page on choosing a database calls the case- and accent-sensitive collation the one deviation that costs correctness — under a `_ci` collation `evt_AbC` and `evt_abc` collapse into one dedupe row, so a distinct, signature-verified delivery is answered `200` and silently discarded — and closed the paragraph by naming this command as a guard. The command read no collation anywhere. An operator following that advice after a restore, a server move or a DBA's `ALTER` got *"Database preflight passed"* over a schema that had lost the property.

  It is a **failure**, not a warning, for the reason the owner-key contradiction beside it is one: an installation that drops verified deliveries without logging anything is not sound. The message names the table, the column, the unique index that stops distinguishing, the collation found and the `ALTER` that puts it back.

  The column set is **derived from the live schema**, not listed in the package: it asks MySQL which columns the package's tables carry a UNIQUE index on, because that is where a collation stops being cosmetic and starts deciding whether two rows are the same row. A unique index added later is covered the day it exists. A binary collation passes — it distinguishes strictly more than the shipped one — and a free-text column outside any unique index is left alone, so the check cannot drown its own finding in noise.

- **A delivery the gate refused is now logged as `refused`, not as `exhausted`.** The endpoint was disabled or deleted while the delivery sat in the queue, so it was never sent and answered nothing. Written as `exhausted` it became indistinguishable from a genuine failure afterwards — the row has no response code and no duration either way — and the endpoint health score counted every one of them against the endpoint, which is exactly what the circuit breaker declines to do three lines further down in the same listener, on the grounds that refusing is our own decision and not the endpoint's fault.

  The shape that made it bite: the breaker disables an endpoint, the whole queued backlog is refused at the gate, and an operator re-enables the endpoint. The failure streak resets to zero and the endpoint is live again — but the refusals sit in the health window for up to `platform.health.window_hours` (24 by default), so the self-service matrix and the operator console went on reporting **Failing** while every delivery since had succeeded.

  Refused deliveries are now left out of the health score entirely, and stay counted as undelivered in the operator rollup, where the question is what happened to your deliveries rather than how an endpoint is behaving. `DeliveryStatus::Refused` is terminal, has a badge and a filter option on every screen that filters by status — the dashboard table and both operator-console stubs — and a label in all seven shipped locales. A guard derives that option set from the enum, because the first pass reached one of the three filters and left the translation for the other two sitting unused. Existing `exhausted` rows are not rewritten; the distinction applies from here on.

  **That rollup half was applied to MySQL only, and the engine it missed is the default.** The MySQL rollup is a query and gained `refused` with the rest; the PostgreSQL rollup is a materialized view, and its `failed` filter still read `('failed', 'exhausted')`. So on PostgreSQL a refused delivery counted in `total` and in **none** of delivered, pending or failed — and the dashboard sums those three straight out of the view, so its own numbers stopped adding up with nothing on the screen saying which one was short. Both halves now agree, and a migration rebuilds the view on installations that already ran the original one. The arm that holds it is written as the invariant — every delivery lands in exactly one bucket — rather than as a check for this one status, because the next status added breaks it the same way.

- **`webhooks:preflight` now names any receiving source that nothing bounds a replay of.** Two things can bound one, and a config file shows neither. `tolerance_seconds` sits in every entry and reads like replay protection, but `GitHubScheme` and `PlainHmacScheme` verify a wire format that carries no timestamp — there is nothing for the setting to check, and no adapter could add a window the dialect does not have. The other boundary is `dedupe_id`, whose default reads the `webhook-id` **header**; GitHub sends its delivery id as `X-GitHub-Delivery` and no `webhook-id` at all, so the key stays null and a null collides with nothing.

  A source in that state is a legal, working configuration — which is why this is a warning and not a failure — but an intercepted authentic delivery replays forever: each copy verifies, stores a row and starts the handler job again. The advisory names the source, the dialect, the tolerance that is never consulted and the header the key would have come from, so neither half can be read as the whole problem.

  It covers the other, larger half of the same silence too: **four of the seven built-in dialects send no `webhook-id` header**, and two of them do have a replay window. Stripe signs a timestamp and still carries its delivery id in the body, so a source configured without `dedupe_id` is bounded in time and not idempotent — inside the tolerance window the same authentic delivery is processed as often as it arrives, and the producer's own retry schedule lives inside it. Both facts arrive in one advisory rather than two, so a reader cannot fix one and take the source for covered.

  Both sets are written by hand and held by a test that derives the same answers from behavior: every built-in scheme signs a message, and the probe reads whether the id header came back and whether verifying with a tolerance of `-1` — which makes even a fresh signature expired — still returns valid. A host's own scheme is left alone rather than guessed at. The receiving docs gained **Replay window** and **Sends `webhook-id`** columns, the pipeline's replay bullet no longer promises the window unconditionally, and the GitHub example in `config/webhooks.php` now carries the `dedupe_id` line it was missing.

### Changed

- **Any view override of yours wins over `ui.variant`, not only a published one.** The resolution asked whether the view sat under `resources/views/vendor/webhooks` — the path `vendor:publish` writes to, and the only route it recognized. A host who takes these views over from their own service provider, by prepending a namespace hint, was told their override did not count, and the package rendered its own markup straight over it. It asks whether what resolves is *not* the package's own view now, so publishing is the common route rather than the only one.

- **The WireKit floor moves to 2.38, and the icon advisory stops claiming a broken page.** Until 2.38, an icon whose alias resolved onto a set nobody registered threw instead of degrading — `blade-ui-kit/blade-icons` installed without `blade-ui-kit/blade-heroicons` was enough, the shipped screens ask for `heroicon-*` names, and icons render transitively through buttons and dropdowns, so the dashboard and the portal answered 500 on every screen. WireKit's graceful path covered the unknown *alias* and not the resolved-alias-missing-*set* case.

  2.38 degrades that case to the same inert placeholder, so the two halves of the pair now cost the same thing: with either one missing the screens render without iconography. `webhooks:preflight` says which half is missing in one message instead of two, the `suggest` entries no longer call one half worse than installing neither, and the styling guide's warning is about missing icons rather than a failing page.

  Raising `conflict` is what makes the softened wording true rather than optimistic — an advisory that overstates one state is how a reader learns to skip the next one. A test holds the message against the old claim directly, because a string that has merely gone out of date still renders.

- **The `core.egress` list is described as what it is: a list this installation publishes, not one it enforces.** Two one-line summaries called it an "allowlist" with no room for the qualifier the longer pages carry — and one of them sat in the same reference-table row as the SSRF policy, which does enforce, so a reader scanning that row could reasonably conclude outbound deliveries are restricted to those addresses. They are not: the only code that reads the list prints it, for a consumer to allowlist on their own firewall. A guard pins that reader set, so the day something starts enforcing with the list, the word becomes true again.

- **The test suite refuses an unstubbed outbound request instead of making it.** The package fakes the HTTP layer in 82 places and armed `Http::preventStrayRequests()` in none, so a call no fake pattern covered went out over the wire for real — which for a package whose whole job is delivering to arbitrary, tenant-supplied URLs is the direction nobody notices. A pattern that stopped matching showed up as a slow or flaky test, and the same run behaved differently on a machine with a network than on one without.

  Six suites opt out in their own files, each naming the socket it needs; `grep -rn allowStrayRequests tests/` is the whole set. The browser lane needs no opt-out — its requests come from Chromium, not from that facade.

- **`webhooks:prune-orphaned-payloads` streams its reference set, and says what the sweep costs.** The set of still-referenced object keys was built with `pluck()->all()` and then copied into a lookup — a full second array of every path, alive at the same time, doubling the peak on a log with millions of offloaded rows for no gain. It is read in chunks now. The set itself stays resident, because it has to: an object is an orphan only when **no** row points at it, so the answer needs the whole set.

  What chunking cannot fix is now written in the command's docblock rather than discovered during a run: the database side is a full scan of both offload logs (`payload_disk` carries no index, and adding one would cost the delivery log's hot insert path permanently for a sweep that is manual and rare), and the disk listing enumerates every object under the prefix — which on object storage is the full-bucket `LIST` a lifecycle policy exists to avoid, and the reason this command is the second choice there.

- **The dashboard's time-window switch is WireKit's `segmented-control` again, not a hand-rolled button group.** The group's stated reason — *"the component forwards only a bare `wire:model` to its hidden input"* — was fixed upstream in WireKit v2.12.0 and is unreachable below the 2.37 floor this package now enforces. The comment stayed, and the dashboard views are publishable, so it reached consumers as a claim about a defect no supported version has.

  The control is bound with `wire:model.live` and the allowlist moved with it. `$window` carries `#[Url]`, so it is client input; `mount()` screens it once and the button group screened every click through `selectWindow()`. A binding writes the property directly on every later round trip, so `updatedWindow()` now applies the same configured-set check — a rejected value falls back to the first configured window, not to a literal `24h` that a host may not offer.

  The optimistic variant is deliberately not used: that layer lives in a separate WireKit bundle these screens do not load, and a real browser reports `wirekitOptimistic is not defined` with the prop set. Using it would make a second asset a requirement for every consumer to move a three-option control one round trip sooner. Proven in the browser lane, which also carries the arms that switch the window for real.

### Fixed

- **The package installs on any Laravel 13, on Guzzle 7 or 8, and on `guzzlehttp/psr7` 2 or 3 again, rather than only on the newest releases of each.** The version requirements had drifted upward — `laravel/framework ^13.12`, `guzzlehttp/guzzle ^7.15.2|^8.0.1`, `guzzlehttp/psr7 ^2.13|^3.0`, `opis/json-schema ^2.6` — so an application pinned anywhere below those could not install this package at all, and was given no reason it could act on. They are now `^13.0`, `^7.0|^8.0`, `^2.0|^3.0` and `^2.0`.

  **Nothing about what you install changes.** A normal `composer update` resolved these to Laravel 13.12, Guzzle 7.15.2 and `guzzlehttp/psr7` 2.13 before, and resolves to exactly the same versions now — Composer already declines releases with a published advisory, and Laravel requires Guzzle itself. What changes is that the requirement no longer *says* the older releases are unsupported when they are supported: an application that has its own reason to sit on Laravel 13.4 is no longer turned away by this package.

  **One caveat worth knowing if you deliberately install an old `opis/json-schema`.** Validation is correct on every 2.x release — but below 2.5.0 that library emits PHP deprecation notices from the code path this package uses, and below 2.3.0 it does so on PHP 8.4 as well as 8.5. Nothing breaks, and a normal install is unaffected because Composer picks the newest. If your application turns deprecations into errors and you have pinned opis low, `^2.5` is the version to move to.

- **Twenty-one `{@see}` references in the shipped source pointed nowhere.** The docblocks in `src/` are written as a reading path: they give a reason and send the reader on to the neighboring class. At these twenty-one the path ended. Fifteen named a class with no `use` import, which PHP resolves against the file's own namespace — so `{@see NonRetryable}` inside the SSRF guard looked for a class in `Core\Ssrf` while it lives in `Core\Http\Exceptions`. Six were a bare method or function name, which reads as a method of the current class; one of them meant the PHP function `trim()`.

  Nothing behaved differently: an unresolvable reference is a dead link in an IDE and in generated documentation, and that is the whole of it. It is fixed because `src/` is what a consumer opens in `vendor/`, and a reading path that ends without saying so costs the reader the time it was written to save.

  A guard now resolves every reference against the autoloader, so the next one goes red rather than accumulating. It joins wrapped inline tags before reading them — a `{@see` at the end of a line with its reference on the next one is a single tag, and reading line by line reported two references that were not broken while hiding a third that was.

- **The drain carried a multi-column foreign key onto its target once per column, and cleared orphans one column at a time.** The catalog returns one row per column a key spans, all sharing a name and a definition; consuming that list flat added the same key repeatedly and, worse, checked each column on its own. A row whose first column resolves while its *pair* does not would survive every single-column check and then fail the `ATTACH` — the permanent wedge the drain exists to prevent, reached by the path that repairs it. Rows are grouped per key now and orphans are matched on the whole tuple.

  The shipped table carries one single-column key, so nothing behaved differently today. It is fixed anyway because the reader's own docblock promises that a key added later is carried the day it exists, and the arm that proves it builds a composite key rather than asserting the promise.

- **Three release guards were each blind in a way that read as a pass.** The no-op-import scan matched `use Foo;` and neither `use function foo;` nor `use const FOO;`, though PHP emits the identical "has no effect" warning for all three — so two thirds of the defect it exists to name were invisible to it. The public-API arm reduced both sides to a bare class name, so a page could promise `Dashboard\PendingWebhook` — a real basename at a namespace that does not exist — and be reported covered; it compares the qualified path now, which immediately turned up a namespace prefix the old comparison had been passing by coincidence. And the arm that excludes one page from the provider sweep did so by an exact path string, so renaming that page would have let it back in to satisfy every provider by itself, silently; the exclusion is asserted now and says what to do when it stops matching.

- **The JWKS cache window was documented nowhere a reader would look, and its default decides whether a rotating producer verifies at all.** `signatures-and-interop.md` said the key set is "fetched through the SSRF guard and cached" and stopped there. It is cached for `jwks.cache_ttl` seconds, one hour by default — so a producer that rotates faster than the window signs with a key this side has not fetched yet, and an unresolvable key is refused as unsigned: no error, no log, just a sender retrying until its budget is gone. That is the same silent refusal the neighboring warning describes for unpinned `kid`, arrived at from the other direction. The page now names the key, the default, and the two ways out.

  The offload keys went the same way. `large_payload.disk` appeared on no page at all, and the inbound `rate_limit` named its two keys only inside a longer code span — which tells a reader who already knows them, and nobody else. Both are on the receiving page now, in the dotted spelling the rest of the portal uses.

- **Five knobs the shipped config file documents and the portal never named.** The Pulse card's service provider is one of four a host registers by hand, and its section said "its provider is not auto-registered" without saying which — a sentence that tells a reader they have a problem and not how to fix it. The other three each have a layer page showing the line; only the 1.x upgrade guide named this one, and a first-time installer never opens that page.

  The receiving side had the same shape four times over. Its `rate_limit` is the only brake there and the only one that refuses rather than defers — an over-limit request is answered `429` and is neither stored nor dispatched — and its bullet named no key at all, while every bullet around it named its own. `profile`, `model` and the `dedupe` driver, the three seams a host swaps to filter noise, store into their own model, or run idempotency without a cache, were likewise absent.

  All five are documented now, and two derived guards hold them: every hand-registered provider has to be named on a page other than the upgrade guide, and every key a source entry reads has to be named somewhere in the portal. Both fail on a knob added tomorrow.

- **The PostgreSQL floor the documentation promises is now enforced, the way the MySQL one already was.** `installation.md`, the README and the guard's own docblock all say *PostgreSQL 13+*, and both migration guards returned the moment they saw the `pgsql` driver — no version read at all. The MySQL half meanwhile checks its floor, the SQL mode, MariaDB and `FOUND_ROWS`, each with a message naming what it found and what to do.

  So the two engines were treated unequally in the direction that matters: a host on MySQL 8.0 was told exactly what was wrong, and a host on PostgreSQL 12 got a raw SQL error from the middle of a migration — about a syntax it has never heard of, on a requirement nobody had told it about. Both guards read one floor now, and a test holds that number against the two pages that promise it.

- **Partition maintenance can no longer wedge itself permanently when an endpoint is deleted mid-drain.** The drain builds its target as a freestanding table with `LIKE … INCLUDING CONSTRAINTS`, and PostgreSQL copies CHECK and NOT NULL there while leaving **foreign keys** behind — so for the length of the drain the moved rows sit outside a key that says `ON DELETE CASCADE`. A tenant deleting an endpoint in that window is an ordinary thing to do, and the cascade cannot reach rows that are no longer in the partitioned table.

  What followed was not an orphan but something worse: the `ATTACH` then failed on the foreign key, `webhooks:partition-maintenance` threw, and it threw again on every later run, because the target and its unresolvable row persist. Partitioning **and** pruning stop — the exact permanent, self-perpetuating outage the drain was written to end, let back in through a door one statement wide.

  The parent's foreign keys are carried onto the target now, read out of the catalog rather than repeated from the migration, so a second key added later is carried the day it exists. With the key present the cascade reaches the rows while they are still there, and PostgreSQL adopts the constraint as the inherited one at attach time, exactly as it does for a partition created the ordinary way.

- **Narrowing the time window is a filter too, and the empty state now says so.** The three-way empty state shipped one filter short: a reader who pulls the window in to seven days over an endpoint whose deliveries are twenty days old still read the unfiltered sentence — which hedges about the *retention* window, and so attributed their own narrowing to the package having deleted their data. A window set to the value that is already the ceiling is still not a filter, because that reader changed nothing.

- **A tenant could be told "nothing has been sent to your endpoints yet" while looking at a filter that matched nothing.** The delivery panel's empty state chose between two sentences on the endpoint filter alone, which was complete until the panel gained outcome and day-range filters. After them, a customer who narrowed to *failed* and had none was given the unfiltered claim — in the exact moment they are checking a failure somebody told them about. The endpoint wording is just as untrue there, so there is now a third sentence about the filters, and which one applies is decided where the filters are rather than in the view.

- **Replaying a delivery no longer 404s when its outcome changed since the page was drawn.** The replay action loads its row through the owner-scoped query, and the new filters had been added to that query rather than beside it — so a reader who filtered to *failed*, saw a row and clicked *send again* got a not-found on their own delivery whenever a worker had moved it to *exhausted* in between, which is exactly the kind of row somebody replays. What may narrow a lookup is ownership; what the reader chose narrows a list.

- **`WEBHOOKS_UI_VARIANT=false` no longer takes both operator screens down.** `env()` reads that as the boolean `false`, and the typed config getter throws on it — "must be a string, boolean given" — so a host switching the setting off the way one switches a flag off got an exception instead of the neutral rendering. A missing key returns the default and a present non-string throws, which is the asymmetry a default cannot cover; the value is read null-safe and anything unreadable is `auto`, as the setting always claimed.

- **The subscription handed back by `enable()`/`disable()` carries the instant the row got, on MySQL as well.** The lifecycle write renders its timestamps into stored form for the query builder, and the instance was being filled from that same rendered form — which sends it through the model's timestamp conversion a second time. On PostgreSQL that is idempotent; on MySQL the stored form is UTC-naive, so the second pass resolved it against the application timezone and shifted it again, leaving the returned object an offset away from the row it had just written and pinned as clean. The row was always correct.

- **Paging through a list no longer shows some rows twice and others never.** Four of the five paginated lists in the package sorted on a column that ties, and a paginated read is several queries: where the order is not total the database may return tied rows differently per query, so page 1 and page 2 disagree about which row is the 25th. `created_at` has second resolution and a fan-out writes a burst inside one second, so the ties are the normal case rather than an edge one, and the operator delivery log is where a burst is read.

  The dashboard delivery table was the worst of the four because its sort column is chosen by the reader: sorting by status ties every row of a status level with every other. Every list now ends its ordering on the primary key, which is unique and therefore makes the order total — the fifth list, the self-service delivery panel, already did, which is what made the other four an inconsistency rather than an open question. Nothing about the defect is visible while it happens: every individual page is correct.

- **Switching an endpoint on or off is no longer a silent no-op when the circuit breaker moved it first.** The breaker disables an endpoint through the query builder, deliberately: gating the UPDATE on `is_active = true` is what makes exactly one of several concurrent workers fire the auto-disabled event. A query-builder write does not touch an instance already in memory, so a subscription loaded before the trip — by an operator screen, by a queued job — keeps reading `is_active = true, disabled_at = null` while the row says the opposite.

  `enable()` assigned exactly those values, found nothing dirty, and issued no UPDATE at all, then returned the subscription, which reads as a confirmation. The endpoint stayed dark and the failure streak that tripped the breaker stayed standing. `disable()` failed the same way in the mirror case, and that is the quieter of the two: a failed enable is noticed the moment somebody waits for a webhook, a failed disable is noticed only by whoever goes on receiving the traffic that was meant to stop. Both now write through the builder, unconditional on what the instance happens to hold, and sync the instance to what was written.

- **A targeted delivery refused because the endpoint is switched off now says so, instead of sending the operator to fix event types that were already right.** `dispatchTo()` decides eligibility by re-running the fan-out's own scopes against the database — its documentation says why, and says a second opinion drifts — and the message underneath then took that second opinion off the model. The two disagree precisely when the breaker has just tripped, so the refusal was explained as *"it does not subscribe to that event type"* for an endpoint subscribed to it. The reason is now read from the row the eligibility was judged by.

- **`webhooks:preflight` now refuses an installation whose delivery job can outlive its queue reservation, because the result is the same webhook sent twice.** A queue connection makes a reserved job available again after `retry_after` seconds, on the assumption that a worker holding it longer has died; the worker itself gives up at the job's timeout. When the timeout reaches `retry_after`, a second worker picks the delivery up while the first is still inside its HTTP call.

  The job derives its timeout from the HTTP budget and bounds it from *below*, so a worker cannot kill a request mid-flight — nothing bounded it from above, and the shipped config invites the raise, since a slow consumer is the documented reason to increase the timeout. Against the framework's default `retry_after` of 90, the boundary is reached by raising that one setting once. Neither side looks wrong on its own and the two numbers belong to two different owners, so nothing but a check reading both can see it. The check applies the job's own arithmetic rather than a copy, so a headroom changed in one place cannot leave it silent.

- **`webhooks:prune-orphaned-payloads` swept nothing on a host that offloads inbound payloads but not outbound ones.** It scans both logs — its own description says "no delivery-log or call-log row still references" — but built its disk list from the Server layer alone. So such a host was told "Offload is not enabled on any layer", the command returned **success**, and their orphaned call payloads accumulated on the disk forever with a scheduled job reporting nothing wrong. The success is what made it silent; a failure would have been noticed on the first run.

  The list is read per receiving source now, because each carries its own offload block and two producers can point at two different disks — taking the first would have been the same defect one level down. Both layers pointing at one disk sweep it once, since that scan is documented as unindexed and doing it twice doubles the most expensive thing the command does.

- **`Retry-After` accepted English relative phrases the class had promised to ignore.** The parser rejected anything without a letter — to stop `-5` and `1e3` being coerced into a timestamp, correctly — and then handed everything *with* a letter to a function whose relative-format vocabulary is enormous. Measured on the shipped code: `+1 hour` became 3600, `tomorrow` 43996, `next week` 259200, and `midnight` became **zero**, which turns a request for quiet into an immediate retry. None of those is an HTTP-date, and the class states that anything unparseable is null.

  The header is now matched against the three date spellings RFC 9110 permits, which closes both directions at once. All three are accepted, including the two obsolete forms a recipient must still take, and a value carrying a zone other than `GMT` or a day that does not exist is refused rather than rolled over into a wait.

- **Every hour bar holding exactly one delivery announced it in the plural, in four of the seven locales.** The screen-reader summary was one sentence with four numbers in it, so the adjectives were frozen in whichever form the translator wrote — `1 livrées`, `1 entregados`, `1 consegnate`, `1 entregues`. That is the common bucket rather than an edge case: a thirty-day window renders up to 720 bars and most are sparse, and these strings exist for exactly one reader, so the ungrammatical half is the whole of what that reader hears.

  A placeholder cannot fix it — agreement is decided by the number, the pluralization helper takes one count per string, and there are four. The sentence is assembled from four fragments now, each carrying its own forms including a zero. The pieces are a list, and a list's order is not grammar, so nothing a translator needs is taken away. French gets the singular for zero and the others the plural, which is what each language does.

- **The French and Spanish delivery-status badges agree with the noun they label.** Four of the five were masculine and the fifth feminine, in the same five-value column, on the most-read screen in the package — and the noun they describe is a delivery, feminine in both languages. Italian and Portuguese were already right. No guard can check this, because agreement is a fact about a word that never appears beside them, so the referent noun is named in each lang file where the rule has to be applied next.

- **The Pulse card's header was half-English on every non-English installation.** It took its period from Pulse's own helper, which returns four hardcoded English strings, and dropped that into a translated determiner — so the shipped card read `letzte 6 hours`, `derniers hour`, `últimos hour`. The determiner was wrong at the same time: all seven locales chose a plural form and one of the four periods is singular, so translating the noun alone would still not have agreed. That is a shape a placeholder cannot fix, because agreement is decided by the noun and the noun arrives at run time. The key now holds four whole phrases per locale, which is what a translator can write, and it is keyed on the raw period token so a Pulse release that adds one turns a guard red rather than rendering a translation key at an operator.

- **The delivery table's hover timestamp is rendered by the same translatable pattern as everything else on its row.** The accessible names beside it read `dashboard.formats.absolute`; the tooltip read a literal, so it dropped the timezone that key's own lang file argues at length is not cosmetic — and a host publishing the lang file to change how these render never reached the tooltip at all. On a dashboard with a display timezone set, a sighted operator hovering that column saw a time with nothing saying which clock it was, while a screen-reader user on the same row was told.

- **A whole number as a health weight threw instead of weighting.** The three weights were read through a getter that refuses an integer, so the most natural way to say "score on success rate alone" — `['success' => 1, 'latency' => 0, 'consecutive' => 0]` — raised an exception on the health-scoring path, taking a scheduled command and a status board down over a setting that reads as legal in every other config block. Nothing in the config file suggests a whole number is forbidden; it ships `0.7` and `0.15` because those happen to have decimals. Ints, floats and numeric strings are all accepted now, and anything that is none of those falls back to the shipped default rather than throwing.


- **`webhook_calls.payload_type` was populated only for deliveries above the offload threshold, when the producer sends its type in a header.** The column is `payload->>'type'` and the documentation calls it a stored column mirroring the payload's own type field. The stub kept in place of an offloaded body wrote the *resolved* event type instead — which for such a producer is a value the body never carried — so a query filtering on that column returned the large deliveries and nothing else. Not an error, not an empty result, and the bias moves with `threshold`, which is a knob an operator turns without expecting it to change what a query means.

  The stub now carries the payload's own type, so both halves agree for a body-typed producer and are both empty for a header-typed one. Dropping it entirely would have inverted the asymmetry rather than removed it: the offloaded rows would then be the empty ones for every ordinary producer.

- **A verified inbound delivery could be acknowledged and then handled by nobody, when two copies of it arrived at once.** The fast-path marker means "stored and queued", which is why it is armed after the dispatch rather than after the store. One branch could only vouch for the first half: a request that loses the race sees a row somebody else committed and arms the marker on their behalf — deliberately, so a producer's retry storm costs a cache hit instead of another parse, offload and insert — without being able to see whether that somebody went on to queue anything.

  So the compensating delete that sits three lines below it had a hole. Measured end to end: the winner stores its row, the loser arms the marker and answers `200`, the winner's dispatch throws and it deletes its row — leaving the id "unseen" per its own comment — and the producer's retry is then short-circuited to a bare success with nothing stored and nothing dispatched. The rollback now withdraws the marker as well as the row, because the two are halves of one claim and half of it surviving is exactly what swallows the retry.

  What remains is stated rather than implied: if the loser's cache write lands *after* the winner's clear, the marker survives. That needs a sub-millisecond interleaving on top of a failed dispatch, and the ordinary race is closed.

- **A receiving route in `routes/web.php` answers every delivery `419`, and nothing said so.** Laravel's `web` group carries forgery protection; a producer has no session and no token, so the request is rejected before the controller runs — before the signature is verified, before anything is logged, before any event fires. The status is what makes it permanent rather than noisy: most producers treat `4xx` as final and stop retrying, so the deliveries are gone rather than delayed. From inside the application everything looks correct — the route exists, the config entry is right, and only the producer's dashboard says otherwise.

  The macro was shown in the quickstart and on the receiving page without naming a route *file*, and `routes/web.php` is where a Laravel developer puts a route by habit. Both pages name it now, and `webhooks:preflight` **fails** on such a route and names it — a failure rather than an advisory, because its neighbors describe legal working configurations and this one describes a route that carries no traffic at all.

  The check reports rather than repairs, and that was a decision: the macro could strip the middleware itself, since a webhook receiver has no use for forgery protection, but a package that silently deletes a middleware the host asked for leaves the next reader unable to tell which of their middleware still applies.

- **Three self-service settings shipped switched on and were documented nowhere.** A tenant meets each of them whether or not the installation chose it: the health board's recompute limit (`2` a minute — the portal's most expensive tenant action, two queries per endpoint synchronously in the web request), how long a revealed signing secret stays on screen (`60` seconds, enforced server-side rather than only by the countdown), and whether a tenant may delete their own endpoint (`true`). The portal page now carries all three with the reasoning behind the numbers.

  The recompute limit was also the one safety brake whose fallback nothing held against the shipped default. Its neighbors repeat their default in the code that reads them — an absent key reads as null, and null switches a brake off, so a host on a stale config cache would otherwise run unbraked — and a test holds each pair together. The introduction to that test said "two of the safety brakes" while its set held six, and the seventh was outside both. A number word that undercounts the set it introduces is how it stayed outside: a reader checking whether their brake is covered counts to two and stops. The floor is derived from the source now, so the next one cannot slip in the same way.

- **The 1.x upgrade guide named three of the four providers a host registers by hand, and the missing one is a fatal on boot.** Laravel resolves `bootstrap/providers.php` on every request, so a class name left on the old root does not degrade a feature — the application stops starting. The guide says as much, and then walked past `WebhookPulseServiceProvider`.

  The guard meant to catch exactly this gathered its expected set from the pages that tell a reader to register a provider, so it could only ever find one somebody had already written about. The Pulse card has no page — it appears on no portal surface at all — and was therefore invisible to the check whose whole job is to notice a provider nobody documented. It now derives the set from composer.json's discovery list against the providers that exist: whatever is not discovered is registered by hand, page or no page.

- **The retention prune could delete a table it had nothing to do with, and report the deletion of one it had not touched.** Two halves in two methods, neither wrong-looking on its own: the partition list was gathered by joining on the parent's *name*, which matches a table of that name in every schema of the database, while the drop issued a bare identifier, which resolves through the search path to the *current* schema. So the list came from one lineage and the drop landed on another.

  Measured against PostgreSQL with a second parent in another schema and an unrelated table of the same name in the current one: the unrelated table was deleted, the partition the prune meant to drop was left in place, and the run reported success. Reachable by any host running a tenant-per-schema layout, a staging schema in the same database, or two installs side by side. Both halves now resolve through the parent's own identity, so the two questions cannot be about different relations.

- **An interrupted partition drain disabled that month's maintenance permanently, while every subsequent run reported success.** The drain creates its target as a freestanding table and attaches it last — that ordering is the point of the method, because creating it as a partition is the step the stranded rows would block. Anything that interrupts it in between leaves a table with exactly the partition's name that is nobody's partition, and the guard in front of it asked whether a table of that name existed. It did, so the run returned early, the month's rows kept landing in the catch-all default, and the next run did the same.

  The guard now asks whether the relation is an attached partition of this package's own parent, and a leftover of that kind is resumed rather than worked around — the drain moves whatever is still in the default and performs the attach that did not happen. Its constraint step is re-enterable now too, or a drain that died one line later would have failed on every retry for ever.

- **A `Retry-After` longer than the cap fired an immediate burst at the endpoint that asked for quiet, on the `sync` connection.** Waiting longer than the queue can hold a job for is answered by deferring: the delivery is re-dispatched with a delay of the cap and the attempt is not charged. `SyncQueue::later()` ignores its delay argument outright and calls `push()`, so on `sync` each deferral ran the delivery again immediately. Measured with the shipped defaults against a receiver answering `429` with `Retry-After: 3600`: **seven real requests in milliseconds** — the attempt plus all six deferrals — at the endpoint that had just asked for an hour. Afterwards: one.

  The release path was already guarded; a deferral is not a release, which is why the guard did not cover it — `defer()` dispatches a fresh job rather than releasing this one, so the question is what the *target connection* does with a delay rather than what this job's own `release()` does. The delivery now reaches its terminal state with the same reason a retryable failure on `sync` already reported, instead of going quiet.

- **A receiver that dropped one connection mid-response lost that delivery for good.** Guzzle 8 files a mid-response reset and a short body under the same exception class as a genuine framing contradiction — `isResponseTransferError()` is true for its whole connection-error and network-error tables plus two more errnos, once response headers have arrived. The transport answered the class rather than the event, so all of them became a non-retryable final failure: one attempt, retry budget untouched, and the endpoint's failure streak fed an event no successful delivery could interleave, which is what feeds the circuit breaker toward auto-disabling a healthy endpoint.

  Three shipped comments said the opposite — that a reset and a partial transfer are retryable — so the code and its own documentation had disagreed since guzzle 8 was adopted. The permanent case is now identified positively rather than by its class: guzzle raises the framing rejection while the headers are parsed and carries the cause that refused them, while an errno-driven break carries none, and the two also differ in whether any body was admitted. Everything else falls through untouched and is retried, because a framing contradiction retried costs a few requests to a receiver that is broken anyway and a reset treated as final loses a webhook.

  Both events are now driven over a real socket, one arm each way. The unit fixture beside them was built the way a reader assumes guzzle builds it — no cause — which made it the transient shape wearing the permanent message; it is constructed as guzzle constructs it now, and its sibling pins the other direction.

- **`webhooks:prune-orphaned-payloads` failed on every run on a host without `ext-intl` — an extension this package does not require.** The command reported the space it had reclaimed through `Number::fileSize()`, which reaches `Number::format()`, which opens with `ensureIntlExtensionIsInstalled()` and throws. The call sits *after* the deletion loop, so the objects were gone and the operator saw a fatal error instead of the result; a byte count of zero threw too, because the unit ladder never runs there and the format call is not inside it. It is a scheduled command, so it failed unattended.

  Nothing caught it because the extension is present on a developer machine and in CI. A guard now derives the intl-dependent method set from the installed framework — a method that calls `ensureIntlExtensionIsInstalled()`, or that delegates to `format()`, which is what catches `fileSize` — and holds it against everything that ships, together with the absence of the requirement itself. Adding `ext-intl` to `require` would turn that guard red on purpose: a new hard requirement on every consuming application is a decision to take in the open.

- **Every number on the dashboard, the health matrix and the Pulse card is now written in the reader's notation.** Twenty-seven renderings went through `number_format()`, which ignores the locale entirely and always writes the English pair. The two characters are exactly *inverted* between English and the six other shipped locales, so a German, Spanish, Italian, Dutch or Portuguese operator did not merely see something ugly — they read a p95 of `1,234.5 ms` as one and a fraction, three orders of magnitude below what it was.

  The separators are translatable, in `lang/<locale>/formats.php`, beside the date patterns and for the same reason those are: they belong to the language rather than to a screen, and a host that disagrees can publish the file. French keeps ICU's narrow no-break space, pinned in the tests as a codepoint because it is invisible in a diff and an ordinary space would let a figure wrap across a line break. A language the package does not ship falls through to English by the translator's own fallback, measured rather than assumed.

- **The endpoint form handed its Alpine scope to a WireKit card, where a scope can be dropped without a trace.** An `x-data` written on a component tag is not an attribute on an element: Blade passes it to the component, which merges it onto the component's own root — and the card sets an `x-data` there itself when its slot carries visible text with no `card.body`. HTML keeps the first of two identical attributes, so one of the two scopes stops existing, with no error and no log line. The form takes the `card.body` branch today, which is what makes the failure a later one: it arrives from a change somewhere else. The scope and the focus target now sit on the form's own element, the same shape the secret panel already uses, and a guard reads the compiled output — where Blade records what it decided belongs to a component — rather than the tag, so the next one is red instead of remembered.

- **An endpoint URL written as an IPv6 literal could not be delivered to at all.** The vetted addresses are pinned through `CURLOPT_RESOLVE`, whose entry is `host:port:address` — and curl's host half does not accept an IPv6 literal in any spelling. Measured against curl 8.7.1: the bare form and the bracketed form both end in `curl: (49) Couldn't parse CURLOPT_RESOLVE entry`, which is a hard failure rather than a warning, so the transfer never started and every delivery to such an endpoint failed before it was attempted. A literal host now gets no pin entry, and nothing is given up by that: pinning closes the gap between the name the guard vetted and the name curl resolves at connect time, and a literal has no such gap — there is no lookup, and the address in the URL is the one that was classified.

- **A receiver whose status line is outside the HTTP range is retried, and the suite now says so.** A `600` answer never becomes a response on either supported guzzle major: psr7 refuses the status itself (`Status code must be an integer value between 1xx and 5xx`), so the transfer fails before anything can classify it. The delivery is retried rather than failed final — a status line nobody can parse says nothing about whether the endpoint will answer properly next time — and the delivery log records no response code, which is the honest value when no response arrived. The async path, where guzzle 8 turns a mid-body timeout into a successful response with a truncated body, is out of reach because this package sends nothing asynchronously — and a guard now keeps it that way rather than leaving it to memory.

- **Two replay buttons in one table no longer answer to the same name.** A screen reader's element list has no reading order, so a row action is announced without the row it belongs to — twenty buttons reading "Replay" are twenty identical entries, and picking the wrong one sends a real request to a customer's endpoint. The names carried the event type, which stops separating rows the moment one event fans out to a tenant's several endpoints: same type, same second, one table. They now carry the **endpoint** and the delivery's own instant, in the reader's locale and the dashboard's display zone. The endpoint is what separates a fan-out; a repeat to one endpoint is separated by the instant, which needs a pattern carrying seconds — the visible one does not, and a replay writes a new row with the same event type and the same endpoint, so the two halves needed two patterns. The guard seeds both cases: a fan-out differing in nothing else, and a repeat to one endpoint seconds apart. The two dashboard panels load that endpoint with the page rather than per row.

- **The SSRF guard pinned one host name and asked for another.** A trailing root dot — `example.com.` — is the same name to every resolver and a different string to an exact comparison, so it was cut from the host to stop an operator's blocklist entry being walked past by one character. The URL handed to the transport was still the caller's, dot and all, while the `CURLOPT_RESOLVE` entry named the cut form. The pin then describes a destination the request never asks for, so the address is resolved again at connect time and the DNS-rebind window the pin exists to close is open for exactly the hosts that took the cut. The URL is now rebuilt to carry the canonical host, and only where the two disagree: credentials, an IPv6 literal's brackets and a missing path survive untouched, and a default port is not invented into a URL that did not carry one.

- **The search guide told a reader to constrain every query by the owner column, and half of the search layer has none.** The outbound delivery index carries the tenant as the whole `owner_type` + `owner_id` morph pair. An inbound call belongs to a producer, not to a tenant: that index is scoped by `source` and has no owner column at all, so the advice pointed at a field that is not in the index it was aimed at. The page now says what each of the two logs indexes and which helper scopes it, and a guard derives both field sets from the traits that build them.

- **The release gate told a developer the mutation lane runs nightly. Its registered schedule is weekly.** The `pre-push` hook says a missing score is fine "because that gate is nightly/on request", and two `justfile` comments said the same. The difference is not cosmetic — it is how long an answer takes: someone who reads "nightly" and waits for tonight is waiting up to seven days. Corrected, with a pointer to read the server rather than the comment. A guard refuses the nightly wording in the hook and the justfile, and its control is the weekly name in the lane itself — nothing in the repository can derive which schedule is live, because the name is registered on the server and the lane filters on both spellings so a rename cannot disarm it.

- **`just --list` showed a sentence fragment for seven of seventeen recipes, and the gate pointed at a recipe that did not exist.** `just` takes the *last* comment line above a recipe as its description, and seven carried multi-line explanations ending mid-sentence — so the listing read "separate `just mutate` run." and "a third copy is what drifts." That listing is the default recipe and therefore the first thing anyone in the repository sees. Each now has a whole sentence, appended rather than substituted so the reasoning above it survives. And `just constraints`, which the gate's own comment tells a reader to run, exists.

- **A dotted field name in a transform rule matched nothing, silently.** `include` and `exclude` compare against the payload's top-level keys, so `customer.email` is an exact no-op — no message, no log line, and no visible difference in the preview beside it. On the one screen where a tenant configures which customer data leaves the building, that silence means the reader believes the email is being dropped while it is being delivered. The editor now refuses a dotted name on either list and on both sides of a rename, the hints say "top-level names only" in all seven languages, and the docs say it where the rules are described.

  Refused rather than supported, deliberately: making a dotted path work would give `include` and `exclude` a semantics `rename` and `rewrap` do not have, and a rule set where two of four rules understand nesting is harder to reason about than one where none of them do. What was missing is that the model says so.

- **A chain of renames ate itself, and a rename onto an existing field destroyed it silently.** `applyRename()` wrote into the result as it went, so a rule could read what an earlier rule had just written: `['a' => 'b', 'b' => 'c']` moved `a` into `b`, found its own output there and moved it on to `c` — one key survived out of two. And renaming onto a key the payload already carried dropped that key's value outright. Neither said anything, and the transformed body is the body that gets signed and stored, so nothing downstream shows what was lost.

  Every move is resolved against the payload as given, which makes the map order-free and makes a swap work at all. A collision is **refused** rather than resolved — the field stays where it is, which a reader can see and correct. Key order is unchanged, deliberately: a body whose keys move is a body whose bytes move, and its signature with them.

- **Every live filter changed the table underneath and announced nothing.** Four screens bind a filter with `wire:model.live` — the operator table's status and event type, the portal log's window and endpoint, and both console stubs. The result count is in the document, in the pagination summary, but as a plain paragraph outside any live region: a reader had to travel back down and read to find out whether the change produced three rows, two hundred or none. The empty state *replaces* the table, so a virtual cursor standing in it lost its position with nothing said (WCAG 4.1.3).

  All four now carry a permanently present hidden region that names the count, keyed on it so Livewire's morph sees a changed node. Two phrasings, because a simple paginator counted nothing and can only speak about the page it is on — "12 results" there would be a number about the wrong thing.

- **Opening the endpoint form moved nothing and said nothing — and it opens *above* the button that asked for it.** The page puts the form before the list, so the new card is inserted ahead of the trigger: a keyboard reader tabs forward out of the list rather than into what they just opened, a screen-reader reader stays positioned after a trigger that now sits after the whole form, and nobody is told the form appeared at all. Focus now moves into the first field and a permanent hidden region announces which form opened; closing it returns focus to the trigger when the trigger is still there.

  Deliberately not the drawer's focus trap: this panel is not modal, the list behind it stays live and reachable, and trapping Tab in something a reader is meant to leave would be worse than leaving it alone.

- **The latency sparkline's numbers existed only in a `title` attribute.** The chart was a `role="img"` with one label naming the chart, and each bar's milliseconds hung on a `title` — which a screen reader does not read off a `div`, and which a touch device has no hover to reach. So of the two charts on the overview one is fully readable and the other is not, and the single number an operator opens that panel for — when the latency ran away — was available to a mouse and to nothing else. It is a list now, with every bar naming its hour and value, exactly as the activity chart above it already did. The `title` stays as a pointer convenience rather than as the only channel.

- **The whole secret-panel card was one live region, so a screen reader read the signing secret out loud on any change inside it.** `role="status"` carries an implicit `aria-atomic="true"`: every change re-announces the *entire* region, and this region held the heading, the endpoint URL, the notice, the plaintext key character by character, the previous key and every button. Pressing **Copy** is such a change — the button swaps its icon and its label — and so is the ten-second expiry warning. On a speaker or in an open-plan office that reads a production secret aloud with nobody asking. It also nested live regions two deep, which ARIA practice advises against on its own.

  The card body is a plain region now. The announcements stayed where they belong: one small hidden region says a secret appeared and that it went away again, another says the window is closing — all three short, atomic, and none of them containing the key.

- **The dashboard's heading outline went from level 1 straight to level 3, on every panel.** A heading list is how a screen-reader user reads a screen for the first time, and there it announced five panels as subsections of a section that does not exist — no level 2 appears anywhere on that surface. The KPI ribbon had no heading at all, so five tiles hung between two headings with nothing naming them. The portal was already right, which is what makes this a drift rather than an oversight. The five panels are level 2 now and the ribbon carries a visually hidden one; `size` is untouched, so nothing looks different.

  The guard checks contiguity **per surface** rather than per file, because that is where the property lives: a level 3 on the endpoint form is correct precisely because the endpoint list beside it is the level 2. The set of pages allowed to carry a level 1 is derived from `#[Layout]`, not listed.

- **In the design-system-free subscription form the validation errors were visible and absent from the accessibility tree.** The messages were free-standing paragraphs with no `id`, and the inputs carried neither `aria-describedby` nor `aria-invalid` — so a screen reader announced a rejected field as an ordinary valid text box, and a refused save said nothing at all. WCAG 3.3.1 and 4.1.2, both Level A, on what is the **default** view of the shipped component rather than only a publish target. Each field now points at its own message exactly as WireKit's input does, and a permanent `role="alert"` region above the form says a save was refused — permanent because a live region has to be in the DOM before its text appears, and because a message beside a field is heard when the field is reached, not when the form comes back.

- **The two design-system-free stubs scrolled the whole page sideways instead of scrolling their table.** They render a raw `<table>` with no overflow ancestor, so a six-column delivery log on a narrow viewport pushes the overbreadth all the way to the document — the header and the page chrome slide away with the table, because it is the document that moves. Both now carry the same scroll region WireKit's own table wraps itself in: `overflow-x-auto`, `tabindex="0"`, `role="region"` and the table's own name. The `tabindex` is not an extra — a scrollable region has to be keyboard-reachable, and an unfocusable overflow div would trade one defect for another. The cells also gained horizontal padding; they had `py-2` only, so the columns ran together.

- **The delivery-log stubs gave every row's buttons the same accessible name.** Twenty rows, twenty entries reading "Redeliver" in a screen reader's element list or rotor — where the reading order that supplies the event does not exist, so picking the wrong one sends a real HTTP request to a customer's endpoint. The package states this rule in its own views and honoured it everywhere else; the two that did not are the *default* render of the shipped components, not merely publish targets.

  Naming them turned up two more the finding did not list: the enable/disable toggle in both subscription-manager stubs, twenty buttons all reading "Disable". A guard now holds that a button whose click carries a row's own id is named per row. It deliberately skips a control inside an alert-dialog — while the dialog is closed its content is inert, so it is not in an element list, and only one is ever open.

- **Five views drew the delivery-status badge from five copies of one ladder, and the copies had drifted into three different ladders.** The publishable console stub mapped `exhausted` to `warning` — the same amber it uses for `pending` — while the dashboard and the portal mapped it to `danger`, so the worst outcome a delivery can have read one step too harmless on exactly the view a host copies and restyles. The dashboard's own delivery table had no `refused` arm at all, so a delivery that was never sent was amber there and grey beside it. The portal's copy even carried a comment stating the rule the stub was breaking — which is the shape of the problem: a rule written in one of five copies governs one of five copies.

  `DeliveryStatus::intent()` is now the single ladder and all five views call it. A guard holds that no view carries one of its own, and a second pins every case's answer.

- **The Pulse card's failure figures were styled with classes Pulse does not ship, so they never rendered.** Pulse's dashboard serves its own compiled stylesheet and nothing of the host's Tailwind build, and `text-red-500` / `dark:text-red-400` are not in it — the failure count and the failure-rate headline have been the same color as everything beside them all along. They now use `text-red-400` / `dark:text-red-300`, which Pulse does ship, plus `font-bold`: at 14px on that cell no red Pulse ships clears 4.5:1, so the weight is what carries the distinction — and it carries it for a reader who cannot separate the hues at all. The percentage in parentheses also gained the dark variant its five siblings in the file already had; without it it sat at 3.38:1 on the dark cell.

  A guard now holds every class in that view against Pulse's stylesheet. It is the one shipped view whose CSS does not come from the host's build, so a class outside that file is inert rather than pending — and it looks perfectly fine in the source.

- **The pagination's inert states were dimmed with an opacity, which cut their contrast to 2.15:1.** `opacity` applies to the whole element, so the text and the element's own background composite against the page together — the muted pair that reads 6.26:1 on its own became 2.15:1 in light mode and 2.59:1 in dark. "Previous" on the first page, "Next" on the last, and the ellipsis between page numbers were the ones affected. It was also the fourth thing saying the same thing: the border, the `cursor-not-allowed` and the absent hover already read as unavailable, and only the opacity cost anything. Removed, and guarded — a disabled appearance is a color decision, and an opacity makes the result depend on whatever is behind the element rather than on the design system.

- **Six action buttons had an accessible name that did not contain the word printed on them** (WCAG 2.5.3, Level A). An `aria-label` replaces the content, so a button reading "Test" whose label said "Send a test event to https://…" is named something the reader cannot see — and voice control matches against the name. "Click Test", "Click Disabled", "Click Send again" reached nothing, on exactly the controls that do something: disable an endpoint, send a test event, replay a delivery, rotate a signing secret. Measured over all seven locales, 29 of the 42 button-locale combinations failed.

  Fixed by **interpolating** the visible string rather than describing it: each label now leads with a placeholder the view fills from the same translation key it renders, so containment holds in every language by construction and cannot drift when one of them is retranslated. A guard reads the pairs out of the views and re-checks all seven locales.

- **`$runsMigrations` was named on three pages with no class, and the way to change it appeared nowhere.** There are four of them — one static property per layer provider — and the documented path for taking the migrations over is the `ignoreMigrations()` beside each declaration, which the portal never mentioned. A host that wanted exactly the behavior the switch exists for had to grep `vendor/` to find out what it was even looking at. All three pages now name the method and the four providers, and `reference/public-api.md` names it where it lists the providers as public API.

- **"Five layers on a shared core" sat above a five-row table whose first row *is* the core.** Five layers plus a core needs six rows. The Boost skill carried the same sentence and contradicted itself inside it — "each with one switch", over a Core row reading "Always on". It is the first paragraph anyone reads and the only place the architecture is counted, so a reader checking the list against the table goes looking for a layer that does not exist. Both now say four layers on a core, and a guard compares the number in the prose against the rows beneath it rather than pinning the word.

- **The US-spelling guard did not read the documentation it is meant to protect.** It scanned the shipped tree and skipped `.docs-portal/` entirely — the pages that publish to docs.pushery.com, whose portal copy is overwritten by every sync, so a British spelling found on the live site is only fixable here. The CI lane that would catch it checks against a script in a repository not checked out beside this one, which left this guard as the only place it could go red early, and it was not looking.

  The word list had the matching gap: it carried `labelled`, `modelled`, `cancelled` and matched the family with a `[a-z]*` tail, which never reaches `labelling`. It reads as covering the family and does not. Both halves fixed — the five `-ll-` present participles are listed in their own right, and the portal is in the scan. That widening found sixteen more hits across the source, the tests and the unreleased changelog.

- **The configuration reference argued against "a 240-line copy" of a file that is well over a thousand lines.** The number had drifted by a factor of four and a half with nothing in the tree holding it — and it weakened the very argument it was making, since a thousand lines of defaults you never chose is the better case for not copying the file. It now says that instead of a figure, because a hard count in prose tracking a growing file is the defect, not the stale number.

- **Three copy-paste handler examples threw an unqualified `RuntimeException`.** Every one of them is aimed at a class in `App\Jobs` — the config line beside it says so — and PHP resolves an unqualified class name in the current namespace, never falling back to the global one for classes. Pasted verbatim, the first unreadable payload produced `Error: Class "App\Jobs\RuntimeException" not found` instead of the exception the branch meant to throw. It weighs most in the Boost skill, where the block is what an agent writes into somebody else's application. A leading backslash survives a paste at any namespace depth, so that is what they carry.

- **The 1.x upgrade guide's re-publish step read as an exhaustive list and was missing three tags** — `webhooks-views` and both operator-console tags. The paragraph immediately after it shows the one edit a published console view needs (`wire:click="delete(…)"` becoming `destroy(…)`), and that line lives in exactly the stubs those tags publish. A reader worked the block command by command and left the file the next paragraph was about untouched. Under a strict CSP without `unsafe-eval` the un-renamed button then does nothing at all — `delete` is a keyword in Livewire's CSP-safe parser — with no error and nothing to search for.

  The guard derives the tag set from the providers, so a seventh view tag is in the check the moment it exists.

- **The installation page put the operator console behind WireKit. Its shipped views do not use a single WireKit component.** Both the requirements list and the "two more packages" section swept the console in with the dashboard and the portal, while the console's own page — two clicks away — shows `composer require livewire/livewire` alone and says the neutral variant needs no design system. A reader either installed a package they do not need, narrowing their Composer resolution with this package's `conflict` constraint on it, or skipped the console believing it unusable without one. The page now separates them and names the WireKit variant as the later choice it is.

  The guard derives the requirement from the views rather than from the sentence that was wrong: a surface needs the design system exactly when the views its components render contain WireKit components.

- **The egress section documented `core.egress.proxy` without naming the gate that decides whether it is read at all.** `core.egress.enabled` defaults to `false`, and `Settings::egressProxy()` checks it before it ever looks at the proxy value — so a host that sets `WEBHOOKS_EGRESS_PROXY` and deploys keeps sending every delivery direct, with nothing logged and no configuration output changed. The page now leads with the gate and shows both keys set together.

  `webhooks:egress-ips` also says so now, because that command is where the mistake becomes expensive: its output is what an operator hands a consumer to put on a firewall. The warning fires only when a proxy is actually configured and the gate is off — a gate that is off with no proxy is the shipped default, not a mistake — and it goes to **stderr**, so `--format=json > ips.json` keeps producing a parseable file.

- **`otel.enabled` was documented like every other layer gate, and it is the one that does nothing on its own.** `ServerServiceProvider` binds `SpanEmitter` to a `NullSpanEmitter` whose `emit()` is empty, so a host that flips the flag and waits for spans in its collector waits permanently — no error, no log line, and no page saying a container binding is still missing. The requirement existed only in a config comment. The reference now states it on the row and carries a "keys worth reading twice" entry that names `SpanEmitter` and `DeliverySpanAttributes` and shows the binding.

- **The portal page said the dashboard uses the same tenant resolver. It does not — it has its own.** `DashboardScope` holds a separate closure and delegates to nothing; what the two share is the *default* rule, which the dashboard re-implements rather than borrows. So they agree right up to the moment a host overrides one — and the sentence sat at the end of the section that tells you to. The failure is quiet: the surface you missed keeps scoping to the `User`, whose owner pair is on none of your delivery rows, and every panel renders its empty state with nothing red anywhere.

  The dashboard page had the mirror image of the same gap — it showed `DashboardScope::resolveUsing()` alone. Both pages now show both calls side by side and say what overriding one alone looks like. A guard holds it: a page that names one resolver names the other.

- **The CSP guidance covered scripts and said nothing about styles.** The two dashboard plots draw their bars with `style` attributes, and under `style-src 'self'` without `'unsafe-inline'` a browser drops those — the bars collapse and an operator reads an empty plot as "nothing happened in this window", with nothing in any server log to say otherwise. A nonce does not help, and that is not a package limitation: a nonce applies to `<style>` elements, never to style attributes.

  Every constant color has moved into a class, so what remains in an attribute is only geometry that comes from the data and cannot be a utility class. The guide now names the constraint and the two ways past it, and a guard holds the count and the shape of what is left against what the page says.

- **The WireKit stubs hid their pagination control on an empty page.** Both neutral stubs render `links()` outside the empty/rows branch; both WireKit stubs rendered it inside the `@else`, so the control vanished exactly when the current page had no rows. For a `simplePaginate` list that is not cosmetic — `DeliveryLog::render()` explains at length why it does *not* correct a reader onto the last existing page, so a page past the end really does come up empty, and with no pager on screen the only way back was to edit the URL. All four stubs now agree, and the package's pagination view still renders nothing while there is a single page.

- **A dashboard window written straight onto a panel bypassed the page's screen.** The page validated `$window` in `mount()` and in `selectWindow()`; `mount()` runs once and `selectWindow()` is not the only write path, so a `wire:model.live` round trip reached `WindowResolver` — which throws on a token it does not know — with nothing in between. The page now screens a bound write the same way it screens a called one.

  The four panels re-opened the same hole one component deeper: each declared `$window` as an ordinary public property, so a request addressed at the child wrote it past whatever the page had just decided. All four are `#[Locked]` now, for the reason `TopEvents::$limit` beside them already was — a public Livewire property that nothing binds is still client input.

- **The secret panel's reveal window was decided from a value the browser wrote.** The class said the expiry is "enforced server-side by the `visibleSecret` guard, so a stale panel can never keep leaking the secret". The guard is `isExpired()`, `isExpired()` reads `$expiresAt`, and `$expiresAt` was an ordinary public Livewire property — so the client picked the number the guard compared against, and a window enforced that way is not enforced. It is `#[Locked]` now, along with the endpoint and the two secrets: not for confidentiality, since a revealed secret is already in that browser, but because `reveal()` and `rotate()` resolve the endpoint through the owner-scoped query and authorize the row policy, and a client-writable id would let the next request act on one that went through neither. `$hidden` stays writable — it is the one value here the browser legitimately owns, and it decides nothing but an announcement.

- **The portal's delivery filter loaded every endpoint the tenant owns, with every column, on every render.** No limit and no `select()` — and the panel re-renders on a filter change, a page change and any sibling event. The operator console solves the identical problem carefully and explains why in its own words; this is the tenant-facing copy of the same control and it had neither half. It now caps the options at 200 and reads only the two columns the markup renders, and **says so when it truncates**: a list that looks complete is how a reader concludes an endpoint has no deliveries when it was simply never offered.

- **"Recompute all" in the tenant portal had no brake and no ceiling.** It is the portal's most expensive action by a wide margin — an aggregate read and an update per endpoint, synchronously, in the web request — and it was the only tenant action with nothing in front of it, while registration, replay and the test ping have each had a rate brake all along. The button is only disabled by `wire:loading`, so a second press starts the whole pass again, and `max_endpoints_per_tenant` ships as `null`, so the multiplier had no ceiling either.

  It now shares the shape of its three siblings: `recomputes_per_minute`, defaulting to a deliberately low `2` because the scheduled refresh already does the same work in the background, with its own cache key so no action can exhaust another's allowance, and a non-positive value switching the brake off rather than refusing every recompute.

  The board is bounded to 200 rows, the same cap the operator console's endpoint filter uses, and one query shape now serves both the board and the recompute — so a pass can never touch a row the reader is not looking at, and can never be longer than the board. **When it truncates the board says so:** a list that looks complete is how a reader concludes an endpoint is gone.

- **The payload-transform editor was the one write in the package with no validation.** `payloadVersion` is a public Livewire property, so a value from the browser reached a `varchar(20)` column with nothing in between — past twenty characters that is a driver error (Postgres 22001, MySQL 1406) where a field message belongs. It is now bounded by width. Deliberately not by membership: `PayloadVersionRegistry` documents an unknown version as supported, "a version may exist purely to stamp its id with no field changes", so constraining the field to the configured set would have narrowed documented behavior to fix a column width.

  The three rule lists are public arrays, and Livewire enforces the array type and nothing about its elements. An element that was not a string reached `trim()` under `strict_types` and raised — on the **read** path, because the preview rebuilds the rules on every render, so the editor stayed down until the state was reset rather than failing one save. Validation protects the write; the builders now check their elements, which is what protects the render.

  Their annotations said `array<int, string>` and `array<int, array{from: string, to: string}>`. That was a wish, not a description, and static analysis believed it — calling the element checks redundant. They now say what a public property can hold.

- **Deleting an endpoint in the tenant portal dropped keyboard focus to the top of the document.** A dialog hands focus back to the button that opened it — but `destroy()` removes the row and calls `resetPage()`, so that button is gone and focus falls to `<body>`. The person who just confirmed something irreversible ends up at the top of the page. The endpoint table now carries `id` and `tabindex="-1"` and the delete dialog returns focus to it, which is what the operator stub beside it has always done.

  A guard holds both directions and derives which is which from what the dialog confirms: a dialog whose confirm calls `destroy()` needs a target, and one whose row survives — the rotate dialog — must not declare one, because a target there would replace correct behavior with a jump to the table. A third arm resolves every `focus-return-to` to an element that is really there and really focusable; without `tabindex="-1"` the target refuses focus and the dialog behaves as if nothing had been declared, with the fix apparently in place.

- **The styling guide's Content-Security-Policy section described a state the code had left four days earlier.** It listed three inline scripts, marked the drawer's focus trap and the secret panel's countdown as impossible to switch off, and told a strict-CSP host that pinning the theme "no longer removes every inline script". Both Alpine components had already moved into one file the package serves from its own origin, loaded with `@assets <script src>`. The shipped config said so in the same repository — "only 'auto' emits an inline script at all" — and the two contradicted each other directly.

  A reader following the page built a per-request nonce resolver for scripts that no longer exist, or loosened `script-src` for them, and was never told the one remaining case is switchable. The page now says what is true: one inline script, the theme mirror, and either pinning the theme or giving that one script a nonce is enough on its own.

  The claim is countable, so a guard counts it: inline `<script>` blocks in the shipped views against what the page says about them. Its scanner blanks Blade comments first — the comment explaining why an inline script was wrong there is itself read as one.

- **The replay button in the tenant delivery log rendered as a filled primary button.** It wrote `variant="ghost"`, and `x-wirekit::button` declares `surface`, `intent` and `size` — no `variant` and no alias for one. The prop dropped into the attribute bag, was written onto the `<button>` element as an invalid HTML attribute, and the component kept its default `filled`. Every sibling replay button — dashboard table, detail drawer, recent queue, endpoint list — is a ghost button. WireKit warns about exactly this and names this exact mistake in its own comment, but the warning returns early outside `app.debug`, so in production there was no signal at all. The same button was also the only replay control without `wire:target`, so it greyed out on any commit of its component, a filter change included.

  **The 2.2.0 entry below says this place was "untouched and correct". That was wrong**, and it is left standing because released history is not rewritten. The sweep it describes was right to be scoped — on most WireKit components `variant` IS the canonical prop — but this one site was a defect, not an exception.

  Two guards now hold it, and both derive rather than list. The undeclared-prop arm calls WireKit's own `StrictnessGate::unknownPropNames()`, the predicate the library split out of its warning path for exactly this use, so what counts as legitimate passthrough is the library's answer and not a list here that would rot. The second arm holds that a WireKit button which disables itself scopes that disable to its own action.

- **The operator console's own pagination control never reached the screen.** `DeliveryLog` and `SubscriptionManager` page with `simplePaginate()` and overrode `paginationView()` — but Livewire resolves the two paginator types through two separate methods, and a simple paginator reads only `paginationSimpleView()`. Both screens rendered Livewire's built-in `simple-tailwind` view instead: a hardcoded English `Pagination Navigation` landmark in a package that ships seven languages, and a raw `bg-white` / `text-gray-500` palette that exists in a host's CSS only if its Tailwind build happens to scan Livewire's vendor views.

  Nothing was red, because both controls page correctly. The package's own view proves it was never the one rendering: it reads `$paginator->total()` and `$elements`, neither of which a simple paginator has, so it would have been a fatal.

  It now carries both types — one control rather than a second view that would drift from this one — and names no total where none was counted, in all seven languages. The test derives the set from which paginator each component builds rather than listing them, so a component that pages is in the set the moment it does.

- **One unreadable row no longer stops the whole secret-revocation sweep, every hour, for ever.** `previous_secret` is an `encrypted` cast, so it is decrypted on **read**, and `revokeExpiredSecret()` reads it on its first line. A row whose ciphertext cannot be read — a partial import, a truncated value — threw there and ended the sweep; `eachById()` orders by id, so the next hourly run stopped at the same row. Not a slow sweep, a stopped one.

  What that left behind is the exact state rotation exists to end: on every endpoint ordered after the broken row, a rotated-away signing secret stayed valid indefinitely. And it was quiet — scheduler output goes nowhere by default, and a stalled sweep looks like a sweep with nothing to do.

  Each row is isolated now. A failure is reported, the endpoint's **id** is named (never the value — whatever failed to decrypt is still a secret), the sweep continues, and the command exits non-zero, which is the only channel that reaches a host's `onFailure()` hook. `webhooks:refresh-endpoint-health` had the same unguarded shape over a cursor and got the same treatment.

- **A hard-killed scheduled run no longer blocks its command for a day.** Every `withoutOverlapping()` in the package used Laravel's default expiry of 1440 minutes. `releaseOnTerminationSignals` covers `SIGTERM` and `SIGINT`, so an ordinary deploy releases the mutex — a `SIGKILL`, an OOM kill or a hard container stop does not. After one of those, the five-minute metrics refresh and the fifteen-minute health sweep were **skipped for up to 24 hours**, and since `withoutOverlapping` is implemented as `skip()`, a blocked run is not a failure: the dashboard's numbers and the endpoint health scores simply stopped moving, with nothing red to explain it.

  Each guard now names an expiry matched to its own cadence — past any real run of that command, under the gap to the next one — so a stale lock costs a skipped run or two instead of a day. The numbers are pinned by a test in both directions, because an expiry that is too SHORT is worse than the default: it releases the mutex while the command is still working, which is the state the guard exists to prevent.

- **A shipped comment no longer speaks in the first person, and a guard keeps it that way.** A comment in `EndpointSecretPanel` recorded, in the first person, what its author had expected a change to do — a work note that happened to be published. A comment in a library describes what the code does; the moment it says what one developer expected, it stops being documentation. The sentence is now a statement of fact, and an author-voice pattern sits in all three belts (the leak-guard suite, the release content scan, and the lockstep tokens that hold the two together).

  The pattern is anchored on a verb rather than the bare pronoun: `I/O` and a lone `I` in a formula are ordinary in this package's prose, and a check that flagged them would be switched off within a week.

- **The Server layer merges the shipped configuration, so mounting it alone actually works.** The trait that performs the merge listed six layers in prose and named the Server among them — and it was wrong twice over: the Server did not merge, and the umbrella provider, which does, was missing from the roster. The count was right, which is what made it read as maintained.

  Nothing broke today: every `webhooks.server.*` read carries an inline default matching the shipped file, and a discovered install registers the Core provider alongside the Server one, so the merge arrived from there. What it cost was the claim — a host mounting the Server layer alone under `dont-discover` had none of the shipped configuration, and the first server key read without an inline default would have come back `null` with nothing turning red.

  The roster is gone rather than corrected. A list in prose is a list nothing checks; the rule is stated instead, and a test holds every provider to it by reading the tree — a provider with a `register()` merges. `WebhooksUiServiceProvider` has none, so it is excluded by the condition rather than by name, and a `register()` added there later walks straight into the check.

- **`vendor:publish` re-dates the package's migrations again.** Since Laravel 11 a published migration is renamed to the current timestamp — but only for paths registered through `publishesMigrations()`, which is the sole writer of the list the publish command consults. All four migration tags used the plain `publishes()`, so `database.migrations.update_date_on_publish` had nothing to act on for this package.

  A host running `vendor:publish --tag=webhooks-migrations` got files named `0001_01_01_*` in their own `database/migrations` — the exact class Laravel uses for its base migrations, sorting before every application migration in a project upgraded from Laravel 10 and reading as though the framework had put them there. No database error today: none of the shipped migrations declares a foreign key.

  The four documented tag names and the per-layer split are unchanged — `publishesMigrations()` calls `publishes()` with the same tags and only adds the re-dating.

- **The endpoint forms refuse a foreign URL scheme in validation, instead of spending a registration token on it.** Laravel's bare `url` rule answers through `Str::isUrl()`, which checks a built-in list of over 200 schemes — `ftp:`, `data:` and `chrome:` all pass it. So a foreign scheme was refused only by the SSRF guard, which sits **after** the registration rate limiter has spent a token, the lock has been taken and the endpoint cap queried. A typo cost the tenant part of their own registration budget and came back as the generic *"cannot be used as an endpoint"* rather than the field message that already existed for it — while the code's own docblock nearby states that a rejected form costs nothing against the limit.

  Both forms now use `url:http,https`. The SSRF guard remains the authority; the rule only takes from it the cases that never needed to reach it. The operator console had no translated line for that rule, so it would have fallen back to the framework's English default on a form whose every other message is translated — added in all seven locales.

- **`vendor/bin/testbench` boots with the package's own providers, so `composer install` stops exiting 1.** They were declared only in `composer.json` under `extra.laravel.providers`, and the testbench app picks a **root** package up solely through its discovery cache — which contains the root package only when the process that wrote it was the testbench CLI. Larastan builds the same app through its own resolver and refreshes that cache without it, so `composer analyse` left a manifest with no root package behind. Without the providers the `webhooks` router macro does not exist, and the demo host calls `Route::webhooks()` at boot, so the message was `Attribute [webhooks] does not exist.` — two hops from the cause.

  It did not heal on its own: the refresh runs only when the vendor symlink is recreated, and composer's `post-autoload-dump` runs `package:purge-skeleton` **before** `package:discover`, so the step that would rebuild the cache is the one that died. Listing the providers in `testbench.yaml` makes the CLI independent of whichever process wrote that cache last — measured with the poisoned cache still in place.

  Nothing shipped is affected (`testbench.yaml` and `workbench/` are STRIP) and no CI lane was: a fresh container has no vendor symlink, so the refresh happens inside the testbench CLI there. What broke was local tooling — `just demo`, `just demo-worker`, and every re-run of `composer install`.

- **The default-partition drain no longer locks the whole delivery log while it moves rows.** It wrapped `DETACH`, `CREATE`, `INSERT … SELECT`, `DELETE` and re-`ATTACH` in one transaction — and PostgreSQL's non-concurrent `DETACH PARTITION` takes **ACCESS EXCLUSIVE on the parent** and holds it until commit. So every insert into `webhook_deliveries`, which is the entire outbound delivery path, blocked for the whole move. Started by a daily unattended cron, over a row count that is largest exactly when the drain is needed: it exists to heal a paused worker, a forgotten cron, a deploy freeze — days or weeks of stranded rows. The visible symptom was a growing queue backlog and hanging workers with no stated cause; the log said only *"Drained N delivery row(s)"*.

  The `DETACH` is gone. It was there because a partitioned table refuses to create a partition whose range the default still holds rows for — so the target is now built as a **freestanding** table, the rows are moved into it while it is nobody's partition, and only then is it attached. Moving rows out of the default takes `ROW EXCLUSIVE` on the default alone, so inserts into every other partition run untouched, and the parent is locked for the `ATTACH` and nothing longer. A temporary `CHECK` matching the partition bounds lets PostgreSQL skip scanning the target during that `ATTACH`, and is dropped afterwards because the bound then says the same thing.

  The move is chunked, so a month of stranded rows is not one enormous transaction either. Each chunk is atomic on its own; a chunk that fails leaves its rows in the default, which is the state the next run already starts from.

- **Three operator lookups carry the partition key again.** `webhook_deliveries` is `PARTITION BY RANGE (created_at)` with `PRIMARY KEY (id, created_at)`, so an id alone names no partition and PostgreSQL probes the primary index of every one of them. The package states that as an invariant in several places — and the delivery detail drawer, the dashboard redeliver and the operator-console redeliver all looked a row up with a bare `find()`/`findOrFail()`. The guard could not see it: it listens to queries only while a `WebhookEvent::dispatch()` runs, which is the engine path, and the Livewire surfaces never appear in it.

  A `withinRetention()` scope now bounds them. It hides no reachable row — the bound sits a month **below** the retention floor, because partitions are dropped whole and only once entirely past it, so one just beyond the floor can still hold rows between two maintenance runs.

  **On a stock install this changes nothing measurable**, and the test says so rather than implying otherwise: the provisioned window is narrower than the retention bound, so nothing is excluded. The win appears once partitions older than retention are still around — which is what happens when maintenance lags, and that is precisely when the log has the most partitions to probe.

- **The default-partition drain resolves a table and its columns the same way.** `tableExists()` asked `to_regclass()`, which searches the whole `search_path`; the column lookup filtered `information_schema` on `current_schema()`, which is only the **first** schema in it. Where those differ, the existence check found the table and the column list came back empty — and an empty list went straight into `INSERT INTO webhook_deliveries () SELECT  FROM …`, a syntax error whose message describes an empty column list and says nothing about the schema resolution behind it. From that point the self-healing path is stuck: the month's partition cannot be created, and retention pruning stops with it.

  Columns are now read from `pg_attribute` off the same OID `to_regclass()` returns, so the two questions are physically the same object however the `search_path` is ordered. An empty result is no longer interpolated: it raises an error naming the table and the live `search_path`, because a resolution that failed is not a drain with no columns.

- **`owner_id` is the same column type in every table the owner morph pair spans, on MySQL too.** `OwnerKeyType` calls itself the one decision that has to hold identically across those tables and says they *"can never disagree"* — and on MySQL they did. Two rendering paths reach the same decision: `blueprintColumn()` goes through `unsignedBigInteger()`, which Laravel emits as `bigint unsigned`, while `rawType()` — used for the delivery log, whose partitioned, collated DDL a Blueprint cannot express — returned a bare `bigint`. So `webhook_subscriptions` and the rollup accepted owner keys up to 2^64−1 and `webhook_deliveries` stopped at 2^63−1: an endpoint that registers and a fan-out that fails with a numeric-out-of-range under strict mode.

  Unreachable for a Laravel auto-increment key and for any PHP integer; reachable for an externally issued 64-bit key arriving as a numeric string, which the subscribe guard accepts without a range check. PostgreSQL has no unsigned integers, so both paths always rendered the same thing there — which is why the split lived on the engine the suite does not default to. An arm now compares the rendered column type across all three tables, which is what the docblock had been promising unchecked.

  **An existing MySQL install keeps its signed column**; no corrective migration ships, because rewriting a delivery log to widen a range no PHP integer can reach is a cost without a result.

- **`webhook_delivery_hourly.owner_type` declares its collation instead of inheriting one.** It was the only identity column in the MySQL schema without an explicit `utf8mb4_0900_as_cs`, and a Blueprint column with none takes the table's — which for a Blueprint-created table comes from the **connection config**, where Laravel ships `utf8mb4_unicode_ci`. So the leading column of `webhook_delivery_hourly_uidx` compared case- and accent-insensitively on any host that had not changed that, while the rows it aggregates compared strictly.

  The suite could not see it: the MySQL lane pins the sensitive collation on the connection, so the column inherited the right value here and the wrong one everywhere else. `webhooks:preflight` reads it from the live schema now, which does not depend on how the migration happened to run.

  A corrective migration ships with it, because a host that already migrated never re-runs the create migration — and without it that host would newly **fail** the preflight over a column they had no way to correct. It is MySQL-only, runs only when the collation is actually wrong (an `ALTER ... MODIFY` rewrites the table, and a rollup can be large), and has no `down`: restoring whatever database default happened to be there is a guess, and guessing it wrong is worse than leaving a column stricter than it was.

- **A fresh install provisions the partition runway its own setting names.** The create migration carried a literal `4` under a comment promising *"the previous month through three months ahead"* — and `ensureWindow($from, $months)` creates exactly `$months` consecutive months from `$from`, so starting at the previous month a `4` reaches `+2`. One month less runway than the comment and `partition_months_ahead` both stated. The maintenance command beside it did the arithmetic correctly, so a fresh install and steady state disagreed about the same configured value until the first daily run closed the gap.

  The count is now derived from the setting rather than written next to it, so the two cannot drift again, and an arm pins the initial window the way one already pinned the rolling one — measured against what the migration left in the database, not against a recomputation of the same call.

- **`webhooks:partition-maintenance` no longer runs twice at once.** It was the only scheduled command in the package without an overlap guard, and the only one that issues DDL — `DETACH PARTITION`, `CREATE TABLE ... PARTITION OF`, `ATTACH PARTITION`, `DROP TABLE`. The three commands registered beside it all had one.

  The registration now carries `onOneServer()` and `withoutOverlapping(360)`. They answer different questions: the Laravel scheduler fires on every app server, so without the first the command runs once per node in parallel by design, and PostgreSQL's `CREATE TABLE IF NOT EXISTS ... PARTITION OF` is not race-free; the second covers a first pass over a backlog outliving its own day, where tomorrow's run would start on top of today's. On MySQL, where the prune is a chunked-delete loop instead, two overlapping runs simply double the delete load on the table everything else is writing to.

  Neither reaches a DB-per-tenant host that turns the package schedule off and drives the command from its own tenant loop — there is no scheduler lock to take, and that host owns the serialization it opted into.

- **The icon packages are declared as the pair they are, and `webhooks:preflight` says so when only one is installed.** The `suggest` entry for `blade-ui-kit/blade-icons` promised a graceful fallback — *"Without it WireKit draws an inert placeholder in their place"* — and listed `blade-ui-kit/blade-heroicons` beside it as an independent option. The promise holds only when **neither** is installed. With the renderer alone, it goes looking for `heroicon-*` names that are registered nowhere; there is no fallback configured by default, so the lookup throws and the dashboard and the self-service portal answer **500** on every screen that draws an icon — an empty state, a primary action, transitively a button.

  The state is easy to reach by accident, because a host that already uses `blade-icons` for its own icons reads the second entry as optional and skips it. Both `suggest` texts now describe the pairing, the styling guide carries the warning, and the preflight names the half-installed state — with a different message for the harmless direction, since `blade-heroicons` alone only costs the iconography.

  The underlying gap is upstream's: WireKit degrades an unknown icon *alias* to the inert placeholder but not a missing *set*, because an alias like `inbox` resolves through a static preset table that never checks whether the SVG behind it exists. Filed there; this package names the state at install time rather than waiting for it.

- **The WireKit floor moves to 2.37, where a destructive confirmation stops depending on the host's Tailwind build.** Until 2.37 an overlay took its geometry — `fixed inset-0` and its z-index — only from Tailwind utilities, which exist only if the host's build scanned WireKit's views. Without that, the shipped confirmations (*delete endpoint* in the self-service portal, *rotate secret* in the management stub) rendered correctly styled, visible and enabled while sitting in normal document flow: on a page taller than the viewport the confirm button was off-screen and the click never landed. No error, no log line, nothing to tell an operator why. Measured: `wk-overlay-fixed` appears in WireKit's shipped stylesheet at v2.37.0 and not at v2.36.0.

  `conflict` is now `<2.37` and the dev constraint `^2.37`. Both source registrations remain required for everything else on the screen; what changes is that forgetting one no longer takes a delete button with it. `WirekitFloorContractTest` already held the number in four places and moved all of them, including the shipped Blade comment that still named the old floor — which is what it was built to catch.

- **`retryable_4xx` no longer reads a list of digit strings as an empty list.** The values were filtered with `is_int`, and the usual way this list grows a string is a host building it from an environment variable — `explode(',', env('...'))` yields `['408', '425', '429']`. Every entry was dropped, and what came out was an **empty** list, which the classifier reads as the deliberate statement *no 4xx is retryable* rather than as a fallback. So the host who had just written down the three most retryable codes got the opposite of all three: a `429` became a final failure, the `Retry-After` path was never entered, and each of those failures counted against the circuit breaker — while the config file went on showing `[408, 425, 429]`.

  Digit strings are now read as the codes they are, with an exact round-trip rather than `is_numeric` (which would take `4.5e2` as `450`, a guess dressed as a reading). A value nothing can make sense of is still dropped, and a **filled** list that survives as nothing falls back to the default — an explicitly empty list is a real choice and stays empty, which is the distinction the old reader could not make. `webhooks:preflight` now names every dropped entry, every fallback, and any code outside the `4xx` range, which the list is never consulted for.

- **A `retry_after_cap` of `0` no longer turns `Retry-After` into a retry storm.** The option means *ignore what the endpoint asked for and retry on our own schedule* — that is what its documentation and its own test comment say. The code read it as *never wait longer than zero seconds*: a `429` with `Retry-After: 60` was answered with an immediate retry, against precisely the endpoint that had asked for a minute of quiet. Worse, every hint exceeds a cap of zero, so the deferral branch fired on all of them and re-dispatched the job with a delay of the cap — zero again — for up to `retry_after_max_deferrals` rounds on top of the ordinary retry budget. At the defaults that is nine immediate requests where the feature exists to produce none.

  A zero cap now switches the hint off: the delay is drawn from the jittered schedule exactly as if no header had arrived, and nothing is deferred. Zero is deliberately **not** floored to one second the way the base and the jitter cap are — it is a legal choice here, and what it configures away is the hint, not the wait.

  Separately, `retry_after_cap` is now clamped where it is read from **config**, not only in the fluent builder. The builder clamped a negative value and the config path passed it through to `$job->delay()`, where a negative delay is not a shorter wait but an invalid argument — and config is the path a host reaches by typing a number into a file.

- **A throwing listener on the refusal event can no longer change the answer to a forged delivery.** Three docblocks and the receiving docs promise that an invalid, expired or unverifiable request is refused with the configured status and never a 5xx. Two of the three inbound announcements were guarded against a listener that throws; the refusal — the one whose own docblock invites an abuse listener, and the one with a recorded incident of exactly this shape — was not, and the dispatch sat immediately before the `abort()` that produces the refusal. A listener that threw took the answer with it.

  The same hole sat in the controller, where `report()` stood unguarded in front of the same `abort()` for the config that carries no verification material at all. `report()` is allowed to throw in three places of Laravel's own making, and nothing is at stake there but the answer — no row is written and no job is queued — which is exactly why its absence was easy to miss.

  Both now use the pattern the two guarded siblings already carried, inner `report()` guard included, and a listener failure arrives as `InboundListenerFailed` naming the `invalid-signature` event instead of reaching the producer. The status a caller sees when the guard is absent is whatever the listener threw — `403` for an authorization failure, `500` for a queue or database one — so the tests pin the configured refusal status rather than the absence of one code.

- **A trailing root dot no longer walks past `blocked_hosts`.** `blocked.example.` and `blocked.example` are the same name to every resolver and two different strings to an exact comparison, so a target the operator had explicitly refused was accepted with one extra character — the registration went through, the endpoint was stored and delivered to, and nothing anywhere said the blocklist had been bypassed.

  The host is now canonicalized where it is lowercased, not at the comparison: the dot would otherwise stay on the host and travel into the cURL resolve entry, leaving the blocklist and the pin talking about different names. The same cut applies to the configured entries, so the name may be written either way — without that half, fixing one bypass would have broken a config that spells the FQDN properly. A host consisting only of dots trims to nothing and is now refused as malformed rather than reaching a resolver.

  `blocked_hosts` remains a supplement rather than the main defense — IP classification still runs after it — but it is the only way an operator can refuse a target that resolves publicly, which is exactly what the dot defeated.

- **A subscription's signing secrets are hidden from every serialized form of the model.** They are cast as `encrypted`, which protects the row at rest and does nothing for the way out: Eloquent decrypts a cast attribute on the way into `toArray()`, so `toArray()`, `toJson()` and `(string) $model` — the last being what `Log::info($subscription)` writes — all carried the plaintext `whsec_…`.

  The package never serialized a subscription itself, so this needed host code; but the guides hand the model over and tell you to read `$subscription->secret`, and `WebhookEndpointRegistered` ships the model to any listener writing an audit trail, which is exactly the code that would call `toArray()` on it. Reading the secret by name is unchanged — `$hidden` governs serialization, not property access, and both shipped secret panels read it that way.

- **On the `sync` queue connection a retryable delivery failure reached no terminal state at all.** `CallWebhookJob` continues a retryable failure with `release()` and deliberately fires no terminal event, because the worker is going to run the job again. On `sync` there is no worker: `SyncJob::release()` delegates to a parent whose entire body is `$this->released = true;`, and `SyncQueue::executeJob()` never reads that flag. So the delivery emitted `WebhookAttemptRetrying` and then nothing — `WebhookAttemptsExhausted` never fired, `failed()` was never called, and the row kept a non-final state indefinitely. The job's own docblock promises that cannot happen ("no path may end without a terminal event") and names exactly this as one of the two failures observability itself cannot see.

  Reachable by any host on `QUEUE_CONNECTION=sync`, and by `PendingWebhook::dispatchSync()`, which the sending guide documents as an ordinary terminal method.

  Such a delivery now reaches a terminal state and says why. `Pushery\Webhooks\Server\Exceptions\QueueCannotRetry` names the queue as the reason and **leads with the original transport failure**, so the stored error still says "certificate expired" or whatever actually went wrong — the delivery failed for a reason, and the queue is only why that reason became final. An operator who saw the classification alone would debug the wrong one of the two. The circuit breaker now counts the failure, `WebhookDeliveryFailed` fires, and endpoint health is recalculated, none of which happened before.

  Version 2.3.0 shipped only the diagnosis for this — `webhooks:preflight` warns when the server layer resolves to `sync`, and the docs say what it costs — because the behavior change needed the test suite to be able to observe a retry somewhere other than through the sync connection. It can: the real-worker suite drives the database queue with an actual worker loop, and it already measured both of the things that were in the way.

## [2.3.0] - 2026-08-27

### Security

- **The search index no longer carries the delivery body unless a host asks for it — `search.index_payload`, off by default.** The payload is governed on every screen by the `view-webhook-payload` ability: without it a reader sees `[string]` and `[int]` rather than the customer's email, IBAN and address. The Scout path never consulted that ability. It wrote `payload_excerpt` — the first 500 characters of the raw payload — into Meilisearch or Algolia, where it was full-text searchable through `searchForOwner()` and came back as an attribute in every hit. A host running the shipped default (the ability undefined, so redaction on) saw the redacted shape on the dashboard and shipped the unredacted body to the index at the same moment.

  **The switch is a switch rather than a redaction pass, and that is the substance.** An index is not a screen: it is one shared artifact, queried by everyone who can query it and retained by a service whose backups and access control this application does not own. A per-request permission cannot govern it, so the package stops pretending one can and asks the question once, in config, where a host can answer it knowing what it means. `security.md` now says what leaves the application boundary when search is on.

  **If you have search on and want the body indexed, set `search.index_payload` to `true`.** Without it the index still carries everything a search needs — event type, url, status, the owner pair, the timestamp — and queries against those are unchanged. Queries whose term only ever matched inside a body will stop matching. An offloaded payload was never read back for indexing and still is not.

  The docblock on both searchable traits claimed the opposite ("indexing only queryable, non-sensitive fields") and has been corrected.

- **A secret that base64-decodes to nothing is refused instead of signing and verifying with an empty key.** Standard Webhooks derives its HMAC key by stripping an optional `whsec_` and base64-decoding the rest, so a value carrying no base64 characters derives **zero bytes** — and HMAC-SHA256 under an empty key is a pure function of `{id}.{timestamp}.{body}`, which is exactly what the request already publishes. A receiver configured that way accepted every forged delivery with a 200 and started the handler job; a sender configured that way put a signature on the wire that anyone who saw the request could reproduce. Nothing failed, nothing was logged, and the headers claimed the delivery was signed.

  The realistic way to reach it is the bare prefix. `WEBHOOKS_..._SECRET=whsec_${SECRET}` with the variable unset expands to exactly `whsec_`, and both guards that existed checked the *string* — `WebhookConfig` required a non-empty one, `SecretSet` a non-empty token — while neither looked at the *key* derived from it.

  The two sides answer differently, and deliberately. **Signing raises**, because the sending side is trusted and may fail loudly rather than emit a forgeable signature. **Verification skips** the unusable secret and falls through to `invalid`, because an unverifiable request must be refused rather than turned into a 500 — the same rule the Ed25519 dialect already stated for a malformed public key. A rotation carrying one usable secret and one broken one still verifies through the usable one.

  `webhooks:preflight` names the fault too, so it is findable at deploy time rather than at the first forgery. The check is scoped to the dialects that actually derive a key: a Stripe- or GitHub-style config uses the raw bytes of its secret, where `whsec_` is an ordinary six-byte key and nothing is wrong.

- **The guzzle floor moves past two advisories that affect the versions below it** — `guzzlehttp/guzzle: ^7.15.2|^8.0.1`, raised from `^7.15.1|^8.0`. 7.15.1 and 8.0.0 are affected by CVE-2026-69246 (high, *Noncanonical host can bypass host-based checks*) and CVE-2026-69245 (medium, *Noncanonical cookie domain keeps subdomain scope*). Composer refuses to install either under its own `block-insecure` default, so the old floor was simultaneously uninstallable and advertised as supported.

  **The high one lands on this package's sharpest surface.** The SSRF guard *is* a host-based check — an allowlist, a classifier and an IP pin — so a host that bypasses host-based checks is the one class of upstream defect this package can least afford to admit in a constraint.

  Nothing changes for an installation that resolved normally: the top of the range was already 8.1.0, and `composer audit` was green, because it reads what is INSTALLED rather than what the floor permits.

### Changed

- **The self-service delivery panel uses WireKit's own select again, and the warning that told you to keep two screens apart is gone.** From 2026-08-15 those two filters were native `<select>` elements: a WireKit select bound with `wire:model.live` made an `alert-dialog` on the same page impossible to confirm — the dialog opened, the click on "delete endpoint" never landed, and nothing was logged. On the WireKit variant that made the delivery log and the subscription manager a pair you could not put on one page, which the operator-console guide said in a danger admonition.

  The upstream defect is fixed, and the condition for believing that was set by the workaround itself rather than by the upstream ticket. It had to pass for THIS composition — two separate Livewire components, a dialog per row, lazy panels — because a fixture on one page had already been green once while this arrangement was still broken. That is not hypothetical: the workaround was re-measured against v2.35.0 two days before it retired and was still failing then.

  What goes with it: the browser arm that pinned the defect (it now goes red by proving the defect is gone, which is the whole design), the two static guards that forbade the composition, and the admonition. The end-to-end arm that drives a real Chromium through the delete dialog stays — it is the one that measures the behavior rather than the arrangement.

- **The psr7 floor moves to where a resolution can actually land** — `guzzlehttp/psr7: ^2.13|^3.0`, raised from `^2.7|^3.0`. Every guzzle version this package admits requires psr7 `^2.13` or `^3.0` of its own accord, so no resolution could ever have put 2.7 in the tree: the number was a claim nobody could check, and it read as though it had been tested.

  Both new floors are measured rather than inferred — the covering HTTP suites pass on guzzle 7.15.2 with psr7 2.13.0, and on guzzle 8.0.2 with psr7 3.0.0. The 3.0 half is deliberately not raised to 3.1: 3.0.0 is genuinely reachable through guzzle 8.0.1/8.0.2 and works, and narrowing a working combination for tidiness is not a fix.
### Fixed

- **The activity chart and the latency sparkline went blank on the 7-day and 30-day windows.** Both render one bar per hourly bucket as a flex child with `flex: 1 1 0%` in a row with a fixed gap — and a flex gap never shrinks. Once the gaps alone are wider than the plot, the free space is negative, and because the flex basis is 0 each bar's scaled shrink factor is 0 too: nothing shrinks, and every bar sits at 0px. The card around them is `overflow-hidden`, so not even a scrollbar appeared to say why. The measured threshold is about 90 buckets at 1280px and about 42 on a 390px phone, against up to 168 buckets on 7d and 720 on 30d.

  **It failed exactly when there was something to see.** A quiet install produces few bucket rows and rendered correctly; a busy one produces a row per hour and rendered nothing. Screen-reader users kept the information the whole time — the per-bar `aria-label` was never affected — and sighted users lost it entirely.

  Each bar now has a floor of 3px and the plot scrolls horizontally in its own region, which carries `tabindex="0"` and an accessible name so it can be reached by keyboard (WCAG 2.1.1) rather than only by a pointer.

- **`webhooks:preflight` warns when the server layer resolves to the `sync` queue connection, and the docs say what that costs.** Sync has no worker: `SyncJob::release()` delegates to a parent whose entire body is `$this->released = true;`, and `SyncQueue::executeJob()` never reads that flag. So a delivery that fails in a retryable way gets one attempt rather than its configured `tries`, no further lifecycle event follows, and a row can keep a non-final state indefinitely — which is the one failure the delivery log itself cannot show you. Nothing said so anywhere: not `src/`, not the docs, not preflight.

  It is a warning rather than a failure, because sync is a reasonable choice while developing and a preflight that failed over it would train a host to ignore its verdict. The sending guide now carries the caveat where `dispatchSync()` is introduced, and the reliability page has a section of its own.

  **The behavior itself is unchanged in this release, deliberately.** Making such a delivery terminate at the attempt would be the honest state on `sync` — and it would also change what three integration arms observe about classification and `Retry-After` parsing, which they measure through the sync connection because that is how a test drives a queued job. That is worth doing carefully rather than beside a release.
- **The bundled Boost skill no longer tells an adopting application that every service provider registers itself.** It said "the service providers are registered automatically through package discovery", which is true of four of the eight. The other four are opt-in and have to be named in `bootstrap/providers.php` — the self-service portal, the dashboard, the operator console and the Pulse card. Anyone following the skill switched a layer on, mounted the Livewire tag the skill gives it, and got `Unable to find component: [webhooks.…]` — a message that sends the reader to Livewire rather than to the one missing line. The portal docs said this loudly in three places; the skill, which is the document Boost surfaces inside consuming applications, said nothing.

  The skill now lists all four with the layer each belongs to, marks them in its layer table, and puts the provider line *before* the config line in its self-service example. A new guard holds it — and derives its list by subtracting the auto-discovered providers from every `*ServiceProvider` under `src/`, so a fifth opt-in provider added later turns it red instead of being quietly left out. That derivation immediately found one the report itself had missed: the Pulse provider.

- **A DNS hiccup no longer ends a delivery after one attempt, and no longer walks a healthy endpoint into the circuit breaker.** The SSRF guard refused a host that resolved to no address with `BlockedDestination`, which is `NonRetryable` — a final failure, on the reasoning that a blocked destination "can only be attacker influence or misconfiguration". That reasoning does not cover this case. PHP's resolver answers `false` for a name that does not exist, for SERVFAIL, for a resolver timeout and for a few seconds of lost network alike, and only the first of the four is permanent.

  So one attempt was spent, the delivery was given up with its retry budget untouched, and the customer never got the webhook — while the log said `exhausted` with a message that reads like a misconfigured endpoint. Worse, every one of those counted as a consecutive final failure, so `platform.circuit_breaker.threshold` (10 by default) of them switched off an endpoint that was never broken, with no self-healing and no half-open probe.

  Such a host now raises `Pushery\Webhooks\Core\Http\Exceptions\HostUnresolvable`, which is deliberately **not** `NonRetryable`, and the delivery gets its normal backoff. A genuinely dead host costs a handful of cheap lookups and then ends `exhausted` anyway — the asymmetry between that and losing a real delivery is not close. Every other SSRF refusal is unchanged and still final.

  `BlockedDestination::unresolvable()` is kept rather than removed: a custom guard whose resolver reads a real NXDOMAIN may still refuse finally, and should. The shipped guard cannot make that distinction, so it no longer claims to.

- **The two dashboard panels whose row budget came from the browser now clamp it, and the recent-queue read is bounded by its window.** `RecentQueue` and `TopEvents` held their budget in an unguarded `public int $limit` and handed it straight to `limit()`. Laravel drops a negative value there in silence — `if ($value >= 0)` — so the statement went out with no `LIMIT` clause at all. A signed-in reader setting `limit: -1` in a `/livewire/update` request got every delivery row the tenant owns in one response, and because both panels carry `wire:poll` the query then repeated itself every interval with no further help from the browser. Three sibling components already clamped exactly this and say why in their own comments; these two were the outliers.

  Both properties are now `#[Locked]` — neither is bound by `wire:model`, both are mount parameters — and the budget is clamped on the *parameter* in `WebhookMetrics`, so a future call site inherits the rule instead of having to remember it.

  **`recentQueue()` also gained the time bound every other metric query already had, and that one you may notice.** It was reading the owner's whole history to find its newest rows, which on the range-partitioned delivery table is a scan the planner cannot prune. It is now bounded by the window the caller already asked for, so a panel showing "recent" deliveries shows deliveries from the window rather than the newest rows of all time. A tenant with nothing in the last 24 hours now sees the panel's empty state instead of months-old rows.

- **On MySQL, every write to the delivery log reached the row again when the application does not run on UTC.** It did not before, and nothing said so. `WebhookDelivery` adds `AND created_at = ?` to its own update so a write on the range-partitioned PostgreSQL table reaches one partition instead of all of them. The value it bound was re-derived from the raw column through `fromDateTime()` — the *write* path, whose job is to read a value under the *caller's* timezone rule, applied to bytes that came from the *column*. On MySQL that resolved the naive stored string against `app.timezone`, so under Europe/Berlin the UPDATE asked for an instant two hours away from the row and matched nothing at all.

  **Nothing failed.** `save()` returned `true`, the in-memory model looked written, and there was no error and no log line. Every row in `webhook_deliveries` therefore stayed at `status = pending`, `attempt = 0`, with no `delivered_at`, `response_code`, `duration_ms` or `error` — for ever. The dashboard read that as 100% pending, endpoint health reported `Unknown` for every endpoint (it counts only `succeeded|failed|exhausted`), the circuit breaker could never trip, and the hourly rollup aggregated those wrong states.

  The raw column value is now bound verbatim, which is an identity comparison and needs no timezone rule on either engine. PostgreSQL was never affected: its stored literal carries its offset, and `timestamptz` compares by instant.

  It survived this long because the two spellings agree under UTC, and UTC is what a test environment runs on. A host that does not is the only one that ever saw it.

- **The neutral operator console shows the one-time signing secret in a readonly input rather than a bare `<code>`.** The plaintext exists in exactly one response — `dehydrate()` clears it on every serialization and this console has no reveal window to ask again with — so a reader who loses one character while dragging across a long token that wraps over several lines has no second chance. The only recovery is a rotation, and a rotation sends every consumer of that endpoint into a migration window nobody needed.

  The input fixes the selection rather than the copying: focus it and Ctrl/Cmd-A selects the field instead of the page, the value comes back as one string with no wrap artefacts, and it is reachable by keyboard. The heading is its `<label>`, so the field has an accessible name without a new translation key. The WireKit variant keeps its copy button; the neutral one is what `--tag=webhooks-ui` publishes, so this is the screen a host on any other design system actually gets.

  **It deliberately carries no `onfocus="this.select()"`**, which is the obvious addition and the one this package must not make: an inline handler is script under `script-src 'self'` without a nonce, so the browser refuses it, nothing throws, and the affordance is silently dead — the fourth instance of a failure this surface has already had three times. Auto-select stays reachable through the asset the package already serves, as its own change. A test holds both stubs against each other and refuses an inline handler on either.

## [2.2.0] - 2026-08-26

### Changed
- The secret panel's countdown owns the element its Alpine scope sits on, instead of sharing the WireKit card's root. Nothing renders differently: the card only sets an `x-data` of its own for a debug-time composition warning this panel does not trigger. But HTML keeps the first of two identical attributes, so sharing the element made the countdown's survival depend on a branch inside another component — and if it were ever taken, the countdown would stop with no error anywhere.
- The self-service delivery panel still uses a native `<select>` for its two filters, and the comments saying why are now correct. They carry the date and the library version behind the measurement (the previous wording said "the current library release", which stops meaning anything the day after it is written), they name the condition under which the workaround retires, they say that there are TWO such controls on this screen rather than one, and they mark the window filter's reasoning as inherited from the endpoint filter rather than separately measured. Nothing about the rendered screen changes.
- **The package installs under Guzzle 8 as well as Guzzle 7** — `guzzlehttp/guzzle: ^7.15.1|^8.0` and `guzzlehttp/psr7: ^2.7|^3.0`. Until now a host that had moved to Guzzle 8 could not install this package at all, and because `laravel/framework` already allows both, this package was the thing holding such an application back on Guzzle 7.

  **No released version was affected by the casing defect this uncovered, and the entry is under Changed for that reason.** Guzzle 8 requires `guzzlehttp/psr7: ^3.1`, which the previous `^2.7` made unresolvable, so no consumer could reach it. What it means is this: the package canonicalizes every HTTP verb to lowercase on the way in, because that is the form it stores and displays, and psr7 2 quietly uppercased it again inside `Request::__construct`. psr7 3 stores the method verbatim, and RFC 9110 methods are case-sensitive — lowercase is not a spelling of the method, it is a different method — so under psr7 3 a delivery would have gone out as `post /hook HTTP/1.1`. The verb is now uppercased at the wire boundary, the single place both the delivery pipeline and the JWKS fetch pass through, which is also the only place that survives a job already sitting in the queue with a lowercase verb in its payload. Your configured `webhooks.verb` keeps working in either case.

  Both majors are exercised rather than assumed: the suite is green under each. Note the asymmetry, because it decides how fast a break would be noticed — the gate resolves the highest allowed version, so Guzzle 8 is proven per integration, while Guzzle 7 is proven by the weekly `prefer-lowest` compatibility lane, which reports rather than gates.

- **A delivery to an endpoint whose response contradicts itself about the body length now fails, and fails FINAL.** A response carrying both `Content-Length` and `Transfer-Encoding` is forbidden by RFC 9112 §6.1 — the two disagree about where the body ends. Guzzle 8 validates that framing before the response is ever visible and refuses the transfer; guzzle 7 has no such check and hands back an ordinary 200. Widening this package to accept both majors would have made the same wire event a delivered webhook on one and a failed delivery on the other, so the package makes the refusal itself and both majors now behave identically.

  **Final rather than retryable, which is the part worth reading.** The next attempt reaches the same endpoint and gets the same bytes, so a retryable classification would spend the delivery's whole budget on identical refusals and feed the circuit breaker every one of them — eventually switching off an endpoint whose actual defect nobody was told about. The failure now says the true thing once, and `MalformedResponseFraming` names it.

  If you host an endpoint behind a proxy or middlebox that adds a length header to a chunked response, this is the change you will notice: those deliveries used to arrive and now fail immediately. The fix is at the endpoint — a response can name its length or chunk it, not both.

### Fixed
- The neutral console stubs gained the four safety affordances only the WireKit variant had: an empty state on both tables (an empty log and a filter that matched nothing were the same picture), a disabled row action while its request is in flight (a second redeliver queued a second delivery, a second ping spent the allowance the component then throttles), an announced actions column instead of an empty `<th>`, and an accessible name on each table. The neutral variant is what `--tag=webhooks-ui` publishes, so a host on any other design system had the lesser screen.
- The neutral stub's delete and rotate confirmations name the action, not only its consequence. `wire:confirm` carried the description alone, so an operator confirming an irreversible delete read the consequence without the sentence that frames it — while the title was already translated in all seven languages and reached only the styled variant.
- The two WireKit-styled forms use `<x-wirekit::form>` rather than a native `<form>`. The library reads a form-level `announceErrors` from the container through `@aware`, so with a native element every field fell through to the global setting and a host could not give one form its own error-announcement policy. Nothing failed; the capability was simply unreachable.
- The WireKit delivery log shows the component's two refusals — a redeliver against a switched-off endpoint, and a test ping past its allowance. It rendered neither: the operator clicked, the button flickered through its loading state, and nothing else happened. No row, no message, no error, nothing in the log — the same symptom as a swallowed click, which is the expensive thing to be wrong about on this screen.
- The operator-console guide warns, at the point where you choose a view variant, that the two components must not share a page on the WireKit variant: a live-bound WireKit select on the page makes an alert-dialog's confirming action silently do nothing, so "delete endpoint" and "rotate secret" open a dialog that cannot be confirmed. The warning already existed in the stubs' Blade comments, which are compiled away and never reach the person deciding which variant to publish.
- The WireKit delivery-log stub offers the same five filters as the neutral one. The endpoint and date-window filters reached the component and the neutral stub but not the styled screen — which is the one a host on that design system publishes, so the filters that shipped were the ones fewer people see. (The component itself always names the NEUTRAL view; the WireKit stub is reached by publishing it.) A parity arm now holds the two stubs against each other rather than checking either alone.
- Operator console: an endpoint that is switched on but failing no longer renders as healthy. Both shipped stubs now show the health band beside the on/off state — `Failing` and `Degraded` alongside `Active` and `Disabled` — using the same bands, intents and wording as the self-service health matrix.

- **The shipped views write WireKit's canonical `intent` prop rather than its back-compat
  `variant` alias**, on the 26 places across ten files where the component in question declares
  both and resolves `$intent ?? $variant`. Nothing rendered differently before or after — the
  point is that an alias the library itself calls back-compat is a promise with an end date, and
  when it goes it goes in every consuming application at once, inside views a consumer does not
  own and cannot fix without publishing them.

  The sweep is deliberately **not** global: on most WireKit components `variant` IS the canonical
  prop, so a blanket rename would delete the property those components actually read. The
  `variant="outline"` on every empty state and the `variant="ghost"` on the buttons are untouched
  and correct.

  A guard holds it, and it **derives** the alias set from WireKit rather than repeating it — a
  hand-written list would rot in the expensive direction, missing a component that joins the set
  later. It also carries the trap that let this slip past the reporting consumer's own guard: a
  tag pattern of `[^>]*` is ended early by the `>` inside a bound attribute such as
  `:time="$formats->dateTime($x)"`, which is exactly where two of these occurrences were hiding.

- **An inbound endpoint whose config cannot verify anything now refuses the request instead of
  answering `500`.** A client config with no `secret`, no `jwks` and no `verifier` threw while
  it was being built — before the receiving pipeline existed — so the endpoint answered `500`
  with an `InvalidArgumentException`. That is the one answer a producer reads as *try again*,
  for a request that can never be made valid: GitHub does not re-send a release delivery at
  all, so the event is simply lost, and Slack disables a subscription whose endpoint keeps
  failing.

  It is now refused with the config's `invalid_status` (`401` by default) — the same answer, in
  the same shape, a rejected signature gets, because it is the same fact about the request. The
  fault is logged as a **configuration** error naming the config, not filed among application
  errors where the reader would go looking in code, and the log line carries nothing from the
  body, which on this path is unauthenticated input.

  Nothing is stored and nothing is dispatched, exactly as before. Hosts driving the pipeline
  controller-less catch the new
  `Pushery\Webhooks\Client\Exceptions\WebhookConfigCannotVerify`, which still **is** an
  `InvalidArgumentException` — so an existing `catch` keeps working — and carries `configName`
  and the `status` to answer with.

- **A transport failure's stored error text no longer carries the credentials from the endpoint URL, on either psr7 major.** A webhook endpoint URL is a place hosts really do put a secret — `https://TOKEN@receiver.test/hook`, or a `?token=` query — and guzzle puts the failed URL into the exception message, which is written verbatim into `webhook_deliveries.error`. That table has no `url` column, so the message was the only place such a secret could come to rest there.

  Guzzle already redacts. It redacts DIFFERENTLY depending on which psr7 major resolved, and both are inside this package's `^2.7|^3.0`: psr7 2 returns early unless the userinfo contains a colon, so a username-only token passed through untouched and the query string was kept, while psr7 3 masks every non-empty userinfo and empties the query. Whether a secret was stored in clear text was therefore decided by a dependency resolution, which is not a property anyone can reason about. The package now redacts the message itself, to one shape on both majors — `ErrorMessageRedactor`, the sibling of `HeaderRedactor`, using the same `[redacted]` marker so one grep finds every masked value.

  The query string is dropped rather than masked, which is what psr7 3 chose upstream. It costs a little diagnostic detail and is the right trade: a query is exactly where a token hides, and the host already knows its own endpoint URL.

  **This does not make a URL-embedded credential disappear from the database, and it is not meant to.** `webhook_subscriptions.url` and `webhook_server_deliveries.url` hold the endpoint URL as given, on both majors, because the package has to deliver to it. What changes is that the credential no longer also spreads into an error column a host may surface to operators.

- **A read timeout that strikes after the response headers keeps its diagnosis, on both guzzle majors.** An endpoint that answers with a status line, promises a body and then stops is the one wire event the two majors classify differently: guzzle 7 has errno 28 in its connection-error list and raises a response-less exception, discarding the partial response, while guzzle 8 raises one that CARRIES it. Laravel then finds a response with a 4xx/5xx status and throws that instead — so the delivery row read `HTTP request returned status code 503` for a request that never completed, beside an `http_status` of NULL. The row contradicted itself, and the timeout was gone: `Response::toException()` builds a fresh exception with no `previous`, so nothing downstream could recover the errno.

  Both majors now persist the same `cURL error 28: Operation timed out …` text. The normalization sits at the wire boundary, where the information still exists — the same place, and for the same reason, as the verb uppercasing and the error redaction.

  It normalizes the timeout and nothing else. `ResponseTimeoutException` is a subclass of the exception guzzle 8 raises for a malformed response framing, and those want opposite treatment: a framing rejection concerns a response that arrived complete, a timeout one that did not. A check written one level up would turn an aborted transfer into a delivery carrying a truncated body, so a test guards that boundary directly.
- **An endpoint whose stored event types are not a clean list of strings can be opened, listed and saved again.** `webhook_subscriptions.event_types` is a JSON cast, so its shape is whatever was written into it — and every reader treated it as a list of names. A row holding a JSON object, a number or a nested array therefore broke four screens, each differently: a list `implode()`d it and printed the literal `Array`, and a form bound its checkboxes against keys that are not indices and then refused to save. An operator who only wanted to correct the NAME was told about an event type they never chose — with no control to remove it by, because the form draws one checkbox per catalog entry and a stray value is in no catalog. The screen was dead for that row.

  `WebhookSubscription::eventTypeNames()` is now the single reader, and both consoles and both list stubs go through it. Reported against the operator console; the tenant-facing form and list had the same exposure and less recourse, so they are fixed in the same change.

  A value that cannot be a name at all — a nested array, an object — is dropped rather than stringified. `strval()` on one does not fail: it warns and yields the literal `Array`, which would then be saved back as though it were an event type. Nothing is rewritten by loading a row; it only changes if the operator saves it.

### Added
- A guard that measures the select/dialog pairing instead of describing it: no shipped view may carry a live-bound WireKit select together with a WireKit alert-dialog, and no page this package composes itself may carry both across the panels it mounts. The existing check asked whether a stub's source contained a warning — it never looked for a dialog and was satisfied by typing a comment. The two now sit side by side and say which is which.
- Operator console: a disabled endpoint now says whether a person switched it off or the circuit breaker did, with the failure streak that tripped it. Both write the same two columns, and the two call for opposite actions — flip the switch back, or fix the destination. `WebhookSubscription::wasAutoDisabled()` is the public reading of it.

- **Both delivery lists are bounded in time by default, and the portal panel can replay a
  delivery.** `webhook_deliveries` is range-partitioned by month — the decision that makes
  retention a `DROP PARTITION` rather than a `DELETE` — and a read with no lower bound on
  `created_at` cannot be pruned, so it visited every partition there is. Nothing went red about
  that: the page loaded, it just loaded with the whole history in the plan, and the cost arrives
  with the data rather than with the change, at whichever consumer has been running longest.

  `platform.deliveries.window_days` and `dashboard.deliveries.window_days` (both 30) are
  **ceilings**, not merely defaults: the panels' new `windowDays` property may narrow the window
  and can never widen past the configured value, because a public Livewire property is writable
  from the browser and widening is the expensive direction. Set either to `0` to switch the bound
  off. On the dashboard table the property is also `#[Url]`, so the window appears in the address
  bar and a bookmarked view keeps it — the portal panel's is not.

  The portal panel also gained a **send again** action, authorized by a new `redeliver` ability on
  `WebhookSubscriptionPolicy` — its own ability rather than `update`, since causing an outbound
  request to leave the installation is a different act from editing a row. It is braked per tenant
  by `platform.self_service.replays_per_minute` (10): the destination is a URL the customer
  registered, so an unbraked replay button is an amplifier one customer can point wherever they
  like. A delivery belonging to another tenant fails not-found rather than forbidden, before the
  policy and before the brake.

  And `platform.deliveries.show_errors` (off) can put the stored error text on the portal list,
  for an installation where the reader is the party that runs the receiving server. Off, the
  column is not even selected — the panel's promise is about what it reads, not about what the
  markup happens to print.

- **The operator delivery log filters by endpoint and by date window, and returns to page one
  when any filter changes.** Status and event type answered "what happened at this endpoint, in
  this week" by paging, and on an installation with more than a handful of endpoints the row
  being looked for is pages away. Reported from a consumer that had to keep its own copy of the
  screen rather than adopt this one.

  Both bounds are `Y-m-d` and inclusive: `until="2026-06-20"` includes everything that happened
  on the 20th. A value that is not a date is ignored rather than raising — the bounds are bound
  with `wire:model.live`, so they carry half-typed dates on the way to whole ones.

  They compare `created_at` directly rather than through `whereDate()`, which is the difference
  between a query PostgreSQL can prune to one partition and one that reads them all, and they
  bind through the package's own timestamp scopes rather than a bare `where()` — a naive literal
  is resolved against the **database session** zone, and was measured hiding a delivery stored at
  23:59 UTC from a window that named that very day.

  The endpoint list the filter offers is capped, because this console is unscoped and that list
  is every subscription in the installation; when it truncates, the screen says so.

- **`webhooks:preflight` now also checks that every inbound client config can verify a
  delivery**, and `WebhookConfig::configurationFaults()` exposes the same check for a host's own
  health check — one message per faulty entry, an empty array when there are none.

  This is the half that makes the fault findable at all. Nothing about a config with no
  verification material is visible from the application: every page renders, every check is
  green, and the answer arrives once — as a refusal, from a producer that may never send that
  event again. The check runs only while `client.enabled` is on, because with the layer off no
  route is registered and failing over configuration nothing reads would teach a host to ignore
  the command's verdict.

  It does not restate the rules; it builds each entry through the same path a request takes, so
  it inherits every check the builder has — a misspelled `dedupe` driver, a `verifier` that is
  not an `InboundVerifier` — including ones written after it. Two faults it adds on top are
  about the block rather than an entry: an entry with no `name`, which no route can ever
  resolve, and a name defined twice, where only the first is ever used — the reason a secret
  rotation can change nothing at all.
- **A non-string event type was refused in English, whatever the reader's language, and the
  refusal named a raw field path.** Both registration forms — the tenant-facing self-service one
  and the operator console — rendered `The eventTypes.0 field must be a string.` The `string`
  rule was the one rule of that `validate()` call with no message of its own, in a block whose
  own comment promises that no refusal falls back to the framework's untranslated default.

  It now carries a translated sentence in all seven shipped languages, in both catalogs. The
  three attribute labels beside it could not have softened it: Laravel does not resolve an
  indexed key such as `eventTypes.0` onto its parent's label, so the path appeared as written.

  Nothing caught it because every arm asserted **that** an error existed on `eventTypes.0`
  rather than **which sentence** appeared. The new arms read the sentence, in a non-English
  locale.

## [2.1.0] - 2026-08-23

### Added

- **`admin.abilities` — a per-action ability map for the operator console, and the way past a
  defect that denied every operator.** A host whose capabilities come from
  spatie/laravel-permission could not use `admin.ability`: that package registers a
  `Gate::before` hook which reads the first positional gate argument as a *guard* name and
  shifts it off the list. The action name travels in exactly that position, so `'create'`
  became the guard, the permission lookup asked for a guard nobody defined, and the check
  fell through to an ability that does not exist — a deny.

  Every action then refused every operator, **including the one the permission was granted
  to**, and it refused silently: nothing threw, nothing was logged, the form just did nothing
  when submitted. A surface that denies everything looks exactly like a surface that is well
  guarded, which is why only a positive arm can tell them apart.

  An ability taken from the new map is authorized **alone**, with no argument, so a
  permission name works as itself:

  ```php
  'admin' => ['abilities' => ['*' => 'manage webhooks']],
  ```

  `'*'` is the catch-all and an exact action wins over it, so the console can sit at one
  capability with only `delete` lifted to a stricter one. Both keys may be set: the map
  answers where it names an action, `ability` answers everywhere else with its argument and
  its behavior **unchanged**. An entry that is not a non-empty string is ignored rather than
  denying — a half-written map must not become a console that refuses everyone.

- **`webhooks:preflight` now holds the configuration against the schema, not just the driver.**
  `platform.owner_key_type` has to be a declaration — it is read before the tables exist,
  because it is what renders them — and nothing afterwards ever checked that it still
  matched. Three run-time paths believe it over the database: `subscribe()` refuses every
  owner it declares unfit (loud), the delivery model casts `owner_id` by it (silent — a UUID
  declared `bigint` reads back as a small integer, and every UUIDv7 owner collapses onto the
  same one), and the redelivery policy then compares that value against the tenant and
  refuses every tenant its own deliveries (which reads like an authorization decision).

  The contradiction is an **expected** state, not an abuse: a host that partitions
  differently or needs its own indexes forks the two create-table migrations, and from then
  on the column comes from the fork and the setting comes from the config. Preflight now
  fails when they disagree and names both. A migration run that leaves them contradicting
  also writes a warning to the log — the check hangs off the migrator's event rather than off
  this package's migration files, which a forked installation does not have.

- **`dashboard.timezone` — the zone the dashboard renders timestamps in.** Every value already
  carried the application zone and said so, because the absolute format ends in `z`. What was
  missing is a **seam**: `app.timezone` is one process-wide setting, so in a multi-tenant
  back-office it is `UTC` for storage while the operator reading the delivery log sits
  somewhere else, and there is no single value the application could set that is right for
  every reader. Labeling the offset only told them to do the arithmetic themselves, on the one
  surface where they compare against their own records.

  Unset, **nothing changes**. Set a zone identifier for one operator or one tenant, or a class
  implementing `DashboardTimezoneResolver` when the answer depends on who is reading — resolved
  per render, the same shape as the payload seam. It reaches the delivery table, the detail
  drawer and the hourly axis: two columns of one screen on two different clocks would be worse
  than one clock that is not yours, because nothing would say it is happening.

  A zone the runtime does not know falls back to the application zone rather than throwing —
  a typo in a display setting must not take a dashboard down, and the `z` in the format means
  the fallback names itself. A class that does not implement the resolver contract does throw.

### Changed

- **The hourly-activity axis ends in a minutes placeholder rather than a literal `00`.** The
  buckets are whole hours, so the two render identically — until the display zone has a
  sub-hour offset (India, Nepal, parts of Australia), where the value really is `:30` or `:45`
  and a hardcoded `00` printed a time that never existed.

- **The package's Alpine components now ship as a file served from your own origin, not as an
  inline `<script>`.** The self-service secret panel's countdown and the dashboard drawer's
  keyboard model were registered by an inline script carrying an *optional* CSP nonce. Under a
  strict, **nonce-less** policy — `script-src 'self'`, which an application is entitled to
  choose and which this package must not ask it to loosen — the browser refuses to run it.
  Nothing throws, nothing reaches a server log, and a CSP audit reads the markup as perfectly
  valid: the countdown never starts and the revealed secret stays on screen past its window;
  the drawer loses the focus trap on the one panel a keyboard or screen-reader user cannot do
  without.

  That was the **third** distinct cause of the same dead surface, so the fix is the one with
  no policy dependency left rather than a third correction inside the inline path. The file is
  **served**, not published: a publishable asset works only for a host that remembers to run
  `vendor:publish` — and to run it again after every upgrade — and forgetting is the same
  silent dead panel.

  **Nothing to do.** The route is registered by the dashboard and portal providers, carries no
  middleware (it is a static file with no user data), and is cached immutably under a URL keyed
  to the file's content hash. If you cache routes, rebuild the cache after upgrading. If you
  published either view, re-publish it — your copy still carries the inline script.

### Fixed

- **The dashboard's panels resolve in one request instead of six racing ones.** Livewire
  isolates lazy loads by default: every `#[Lazy]` panel fired its own request and every
  response morphed the shared page. Six of those race on first paint — and switching the time
  window sends four of them at components that are being torn down and replaced, because the
  window is part of each panel's key. The result was an intermittent
  `Public method [__lazyLoad] not found`: a request landing on a snapshot a sibling's response
  had already re-rendered.

  The panels now bundle, so the whole set resolves against one consistent set of snapshots —
  which also costs the server five fewer Livewire boots per page load. **The window stays in
  the keys**: taking it out would stop the remount, and trade a loud rare error for a quiet
  permanent one, since a broadcast cannot reach a still-lazy panel and it would resolve on the
  window frozen into its placeholder — header reading `7d`, panel counting `24h`, nothing
  reporting it.

- **Live-bound filters lost focus on every keystroke.** A `wire:model.live` control without a
  `name` gives Livewire's morph nothing to recognise the old element by, so it replaces it —
  and the field loses focus. On the debounced event-type filter that means typing one
  character and finding the cursor gone. Every live-bound control in the shipped views now
  carries one, and a guard holds the class rather than the two instances that were reported:
  the same defect was in five other views.

- **The operator console's delete dialog dropped focus to `<body>`.** A dialog normally hands
  focus back to its trigger — but after a delete the trigger is gone with the row, so a
  keyboard or screen-reader user confirmed an irreversible action, heard no announcement that
  it happened, and was returned to the top of the document. It now returns focus to the
  endpoints table, which survives and is where the removed row was. The rotate dialog beside
  it deliberately has no such target: its row survives, so focus returns to the trigger by
  itself, and declaring one would replace that with a jump to the table.

- **The one-time secret in the WireKit console has a copy control.** `newSecret` is cleared on
  every dehydrate, on purpose — the plaintext exists in exactly one response, and this console
  has no reveal window to ask again with. A reader who could not get it out of that one
  rendering had to **rotate**, putting every consumer of the endpoint into a migration window
  nobody needed. Selecting a 50-character token rendered `break-all` across several lines, with
  a mouse, without losing a character, is where that went wrong.

- **The delivery drawer's overlay shows a pointer.** It closes the drawer on click, and the
  pointer is the only feedback an overlay can give — it has no border, no label and no focus
  ring. Tailwind v4's preflight gives buttons `cursor: default`, so the one visible way out of
  that panel read as dead space.

- **The operator console's pager rendered but never turned a page.** `SubscriptionManager`
  has handed the view a paginator since 2.0.1, and the console stub renders its control — but
  the component did not use Livewire's `WithPagination`, so the control took clicks and the
  list did not move. A paginator resolves its page from the request, and a Livewire update
  request carries no `page` parameter: every answer was page one.

  That is the one list where it matters most. The console is unscoped by design, so its
  length is the length of the installation — the exact reason it was paginated in the first
  place. It now pages, and it uses the package's own pagination control, so a published view
  restyles it in place like every other paged screen here.

- **An over-long dedupe key could lose the delivery it identified.** `webhook_id` is the twin
  of the `event_type` defect 2.0.1 closed: the same `varchar(255)` column, the same three
  grammars a host can point at it, and the same failure — the row is written after the
  signature verifies and before the 2xx goes back, so an over-long value fails the insert,
  the request answers 500, and the producer retries into the same failure until its budget
  runs out.

  **It is bounded by hashing, not by truncating, and that difference is the whole point.**
  This column sits in the partial unique index that deduplicates deliveries. Cutting it at
  255 would collapse two different producer ids sharing a prefix into one key, and the second
  delivery would be dropped as a duplicate — a loud 500 traded for a silent lost webhook. A
  key longer than the column is stored as `sha256:<hex>` instead: the same id still hashes to
  the same key, so a retry still deduplicates, and two different ids never collide. The
  prefix is there so an operator comparing this against a producer's log can tell a hash from
  a mismatch. **Nothing changes for a key that fits, which is every key a real producer sends.**

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

  **If you published the console view, add `{{ $subscriptions->links() }}`** below the
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
  before this release keeps working. **But that published copy was already broken under a
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
  only by hitting it. Nothing about the behavior changes.

- **The published `composer.json` no longer carries a `config` block.** It held `sort-packages`
  and an `allow-plugins` entry for a `require-dev` package: composer reads `config` only from the
  root package, so both were inert text in an installed dependency. No consumer behavior
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

  **This changes behavior on upgrade.** A host that defines nothing gets the safe default
  rather than the previous one. To keep the old behavior, say so explicitly — one line,
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

- **The MySQL minimum (8.4, the LTS) is now enforced.** The README, config and error message have
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
  `ulid` before migrating and the denormalized `owner_id` column is rendered to match across all
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
  cache fast path.** `dedupe` was read without validation, so an unrecognized value quietly fell
  through to the database-only path (a performance regression under a retry storm, with no error).
  It is now validated against `redis+db` / `db` and throws on anything else, like every sibling
  config key.

## [1.3.0] - 2026-07-15

### Security

- **Behavior change (action required): the dashboard and self-service authorization gates are now
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
  after signature verification, so the body is authentic. Unset keeps the previous header behavior.
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

[Unreleased]: https://github.com/pushery/webhooks-for-laravel/compare/v3.0.0...HEAD
[3.0.0]: https://github.com/pushery/webhooks-for-laravel/compare/v2.6.1...v3.0.0
[2.6.1]: https://github.com/pushery/webhooks-for-laravel/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/pushery/webhooks-for-laravel/compare/v2.5.0...v2.6.0
[2.5.0]: https://github.com/pushery/webhooks-for-laravel/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/pushery/webhooks-for-laravel/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/pushery/webhooks-for-laravel/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/pushery/webhooks-for-laravel/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/pushery/webhooks-for-laravel/compare/v2.0.1...v2.1.0
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
