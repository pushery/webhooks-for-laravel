# Changelog

All notable changes to `pushery/webhooks-for-laravel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

From 3.16.0 on, a release carries only the sections that have an entry, each heading with its sign, in this order: Breaking, Added, Changed, Performance, Fixed, Security, Deprecated, Removed and Documentation. The releases before it keep the headings they were published with.

## [3.16.1] - 2026-10-06

### 🐛 Fixed

- **`webhooks:prune-orphaned-payloads` walks the disk listing as it arrives.** It read the whole listing into memory and sorted it before deleting anything, then asked the disk again for every orphan's size, on S3 as a request of its own. On a disk with very many offloaded payloads, the installation the command is for, it could run out of memory before the first delete. The command reads that listing through Flysystem, so `composer.json` now declares `league/flysystem` (`^3.0`), which every Laravel 13 release already installs. The command reads that listing through Flysystem, so `composer.json` now declares `league/flysystem` (`^3.0`), which every Laravel 13 release already installs.

### 📚 Documentation

- **Comments in the shipped source say what the code does now.** Comments that retold how a line used to look, or which report changed it, state the rule they protect instead. No behavior changes.

## [3.16.0] - 2026-10-05

### ✨ Added

- **Six more limits read from the environment.** `WEBHOOKS_PLATFORM_RETENTION_MONTHS`, `WEBHOOKS_PLATFORM_SECRET_ROTATION_WINDOW_HOURS`, `WEBHOOKS_SERVER_PERSISTENCE_PRUNE_AFTER_DAYS`, `WEBHOOKS_CLIENT_DELETE_AFTER_DAYS`, `WEBHOOKS_PLATFORM_DELIVERIES_WINDOW_DAYS` and `WEBHOOKS_DASHBOARD_DELIVERIES_WINDOW_DAYS` change a retention, the rotation window of a signing secret or a delivery window without publishing the config, which would freeze every other default in it. A typo keeps the shipped value; `0` revokes a rotated secret at once and switches a delivery window off, as the config already documented.
- **`composer.json` links the security policy** under `support.security`, so the package page names where to report a vulnerability.

### 🐛 Fixed

- **On MySQL, an endpoint's event types are bounded by the bytes its index takes.** MySQL's multi-valued index over `event_types` refuses a row whose values run past about 5,344 bytes together, and both the self-service portal and the operator console ended such a save in a server error, also for a legitimate selection from a large catalog. On MySQL both forms now answer a selection of more than 5,000 bytes with a translated field error; an endpoint that already holds more keeps its types through an edit. PostgreSQL has no such bound, and there the forms apply none.
- **A received call records its handler's outcome.** Every call taken in live stayed `received`, although `WebhookCallStatus` and the reference said the handler moves it on, so a query for unprocessed or failed calls answered wrongly and the partial index over received calls grew with the whole log. A call is now `processed` once its handler returns and `failed` once the queue gives up on it after the last attempt; a status the handler writes itself is kept. A handler with a constructor or a `failed()` of its own calls the parent's.
- **`warnWhenEndpointsAreDisabled(false)` silences the breaker-only warning as well.** After `warnOnlyAboutEndpointsTheBreakerDisabled()` the switch was ignored and the warning still came. The later of the two calls now decides, and the count stays in the result's meta either way.
- **`DeliveryEngineCheck` says one minute, one hour and one delivery in the singular.** Its messages read "more than 1 minutes" or "of the 1 deliveries in the last 1 hours" whenever a limit, the window or the count of deliveries was one.
- **The AsyncAPI document in YAML stays in block style at any depth.** A catalog schema sits four levels down in the document, and one nested three objects deep reached the tenth level, where the YAML switched to inline flow style for the rest of it. The JSON document was not affected.
- **The comment at the top of `resources/css/webhooks.css` explains the WireKit glob in words that hold for any package installed beside this one.**

## [3.15.0] - 2026-10-05

### Added

- **`webhooks:import-calls` reads the timestamp columns you name.** `--from-created-at` and `--from-updated-at` default to `created_at` and `updated_at`. A source without them is reported, where it used to date every imported row at the import without a word.
- **`composer.json` names the publisher.** Packagist and `composer show` list Pushery, with its homepage, as the package's author.
- **`composer.json` names the documentation.** `support.docs` points at https://docs.pushery.com/webhooks-for-laravel/, so Packagist and `composer show --all` link the pages. No documentation directory ships with the package, so until now the README was the only way to find them.
- **`DeliveryEngineCheck::warnWhenPartitionsDrift()`** warns while delivery rows sit in the default partition or the next month has no partition yet, the two states a stopped maintenance schedule leaves on PostgreSQL, and adds `default_partition_rows` and `next_month_partitioned` to the result.
- **`WebhookEndpointDisabled` and `WebhookEndpointEnabled`** fire when `Webhooks::disable()` or `Webhooks::enable()` switches an endpoint, from the portal, the operator console or your own code, and carry the endpoint and the person who did it. A host that keeps its own record of an endpoint can follow a tenant switching it off by hand, which nothing announced before. A call that changes nothing fires nothing, and the circuit breaker keeps its own `WebhookEndpointAutoDisabled`.
- **`RegistrationLimit::resolveUsing()` lets the portal ask your own allowance before a tenant registers an endpoint.** The portal could only count endpoints against `max_endpoints_per_tenant`, so an allowance that counts more than endpoints could not be expressed, and refusing afterwards from the registration event deleted an endpoint the tenant had just been told exists. Your resolver is asked inside the same per-tenant lock as the cap, returns `true`, `false` or the sentence the tenant reads, and can only refuse: a configured cap still applies beside it.
- **A catalog entry can name the ability its topic takes.** With `'ability' => 'your-ability'` on an entry of `platform.catalog`, the self-service form and the operator console offer the type only to somebody who holds the ability, refuse a save that adds it without it, and leave it on an endpoint that already carries it. A prefix wildcard covering the type takes the ability too, and `Webhooks::subscribe()` asks it while somebody is signed in.
- **`Webhooks::forgetEvents()` deletes every stored copy of an event before retention does.** It takes the `event_id` every delivery of a dispatch carries and deletes the delivery-log rows of those events, redeliveries included, takes them out of an external search index, deletes their offloaded payload objects that no other row still points at, and keeps a delivery of them that is still queued from being sent. It returns how many rows and objects it deleted and how many shared objects it left in place, so a deletion request can be answered as far as it went.
- **`server.request_options` adds request options of your own to every delivery.** A middleware of yours on Laravel's HTTP client can read its own key off each delivery, such as the purpose an egress policy asks every outbound call for. A key Guzzle reads itself is refused, because the transport owns redirects, TLS verification, the response sink, the timeouts, the proxy and the IP pin.
- **Nine limits a host hardens with are read from the environment.** The two delivery timeouts, the `Retry-After` deferral cap, the platform rate limit and its switch, the test-ping brake, the portal's secret-reveal time and endpoint cap, and the health recompute interval could only be changed by publishing the config, which keeps every later default of the file from reaching you. Each now reads a `WEBHOOKS_*` variable, listed in the configuration reference. A value counts only as a whole number at or above the limit's floor: a typo, a fraction or an empty value keeps the shipped default, so a mistake in `.env` cannot loosen a limit. `Support\EnvLimit` does the reading, the way `Support\EnvFlag` reads a protection switch, and a published copy of the config calls it.
- **`DeliveryEngineCheck::remedyForFailingEndpoints()`** appends a sentence of yours to the failing-endpoint warning, the way `remedyForDisabledEndpoints()` does for a switched-off endpoint, so the warning can say what to do before the circuit breaker switches the endpoint off.
- **`WebhookDelivery::whereSubmittedKey()` looks a delivery up by an id you were sent.** It matches nothing when the id cannot be a delivery's, so both engines answer not found, where `find()` answers such an id with an error (`22P02`) on PostgreSQL's `uuid` column and with nothing on MySQL. The querying guide shows it.

### Fixed

- **The scheduled prune of received calls and server deliveries queries the model you configure.** `WebhookCall` and `WebhookServerDelivery` built their prunable query from the package class, so a subclass you set in `webhooks.models` did not bring its connection, table or global scopes to `model:prune`, the one path that still went around it.
- **Seven shipped reasons rest on what is measured.** The daily overlap locks guard against a second start within six hours, never against the next day's run; a malformed response costs the delivery its remaining tries (three by default) and the circuit breaker counts it once; the Ed25519 length check, not sodium, turns a wrong-length signature into a non-match; Laravel marshals transport failures differently before and from 13.26; not every engine difference lives in the dialect renderers; fr groups thousands with U+202F; and the byte-size rounding differs from the framework's at an exact half.
- **Five shipped comments no longer cite, count or point at something that is not there.** The operator console's URL rule no longer claims a registration brake, lock and cap it does not pass through, two providers point at the documentation pages on authorization instead of README sections that do not exist, and the dashboard scope docblocks list all four scopes.
- **Eight shipped comments describe today's code, not a state it left behind.** The backoff contract says the Retry-After hint is passed, migrations all call `DatabaseRequirement`, the partition drain builds a freestanding table instead of detaching the default, the dashboard window control is bound with `wire:model.live`, the endpoint list does not poll, the null-offset guard names PHP 8.5, and the orphaned-payload prune is not scheduled by the package. The operator console stub no longer warns about a WireKit dialog defect fixed in v2.37.0.
- **Shipped comments state what the code guarantees, not how that was found.** Comments in 41 files under `src/`, `config/`, `resources/views/` and `lang/` reported a measurement or told what an earlier version did; they now state the behavior and the reason for it. The portal's delivery filters no longer carry a dated account of their WireKit select, and the docblock of `PostgresRequirement::MIN_VERSION` keeps the promise and why it is PostgreSQL 13, without the history of how the floor came to be enforced.
- **A Retry-After deferral on SQS waits at most 15 minutes instead of failing.** With `retry_after_cap` above 900, the deferral handed SQS a `DelaySeconds` that AWS refuses, so the attempt threw after `WebhookAttemptDeferred` had already announced the wait. On a standard SQS queue the deferral now waits 900 seconds and defers again while the endpoint still asks for longer; other queues still wait the whole cap.
- **The portal's delivery list explains the duration beside the response code once.** The same paragraph stood in two consecutive comments of `endpoint-deliveries.blade.php`; the first one again only says why a missing answer is a dash and not a zero.
- **A delivery that a queue hands to two workers keeps the outcome that was recorded first.** When an attempt outlives the queue's visibility timeout, a second worker runs the same job. Both the delivery log and the standalone server log read the stored status and wrote the new one afterwards, so a failure written in between could overwrite a success, count against the endpoint's circuit breaker and fire `WebhookDeliveryFailed` for a webhook that had arrived. The check is now part of the write itself, and a write it turns away sets nothing off. `DeliveryStatus::isTerminal()` names the finished states, and both delivery models gain `saveUnlessTerminal()`, which fires the model events `save()` fires.
- **A rotated signing secret stays on screen for a whole reveal window.** After a rotation the secret panel's countdown went on counting the window of the first reveal, so a secret rotated halfway through that window disappeared halfway through its own. The countdown is now keyed by its window, and a rotation starts it again from the full `secret_reveal_ttl`.
- **The operator console's endpoint list and delivery log page independently.** Both kept their page in `?page=`, and the console puts them on one page, so paging the log paged the endpoint list too: a log on page 2 reloaded an empty endpoint list, and the back button moved both. The endpoint list now keeps its page in `?subscriptions=`; the log keeps `?page=`. A link that pointed at a page of the endpoint list with `?page=` now opens it on its first page.
- **Each event-type choice is keyed by its type.** The portal form and both variants of the operator console rendered their event-type checkboxes without a `wire:key`, so Livewire matched them to the list by position, and that list changes length with the endpoint that is opened: its types the catalog no longer declares are appended. Every choice now carries `event-type-<type>` as its key.
- **The neutral operator console no longer scrolls sideways on a phone.** The endpoint list and the delivery log each scroll in a region of their own, but the visually hidden header of their actions column is absolutely positioned and escaped that region, so a table wider than the screen widened the whole page: 499px on a 375px screen with one endpoint and one delivery. Both regions are now positioned. If you published the neutral stubs, add `relative` to the `role="region"` wrapper around each table.
- **The page-gate advice names Livewire's persistent middleware as it is.** The config, the operator console's guide and a docblock said Livewire re-applies `auth` and `can:` on its own endpoint by default, as if that were the whole list. It holds both beside a few authentication middlewares of other packages, and no gate middleware you write. The advice is unchanged: gate the page with `can:`, or register your own middleware with `Livewire::addPersistentMiddleware()`.
- **A URL whose path is longer than 2048 characters dispatches on MySQL with the Server layer's persistence on.** The delivery log's `url` column is 2048 characters wide on MySQL, and its row is written while `dispatch()` runs, so such a URL threw `1406 Data too long` in your request and the delivery was never queued, while PostgreSQL stored it. The log now keeps at most 2048 characters of the URL on every engine, ending in `…` when it was shortened, and the delivery still goes to the whole URL.
- **The MySQL rollup refresh no longer holds up the delivery log's writers.** `webhooks:refresh-metrics` rebuilt the hourly rollup with an `INSERT … SELECT` under MySQL's default REPEATABLE READ, which takes a shared lock on every delivery it reads, so recording an attempt or a new delivery waited until the refresh committed. The refresh now runs at READ COMMITTED, which reads without locks, and the dashboard still reads the previous rollup until the commit. It keeps the default inside a transaction you already opened and under statement-based binary logging, where MySQL refuses the switch.
- **The dashboard's rollup table installs on a MySQL server that requires primary keys.** On MySQL the hourly rollup was a table with a unique index and no primary key, so with `sql_require_primary_key` on, the default of some managed MySQL offerings and a requirement of Group Replication, `migrate` stopped there with error 3750. The owner pair and the bucket are now its primary key, and a new migration gives an existing rollup table the key in place of the unique index on the same columns. If you manage the migrations yourself on MySQL, copy `0001_01_01_000104_give_the_mysql_rollup_a_primary_key.php` from the package's `database/migrations/dashboard` into your own; publishing `webhooks-dashboard-migrations` again would write every dashboard migration a second time.
- **The MySQL delivery log takes its character set from the connection, as every other table does.** It is the one table the package creates with raw DDL, and that DDL named no character set, so on a database whose default is latin1 or utf8mb3 four of its columns took that default: a payload `type` outside it was refused with error 1366 and the delivery with it, and an error text or payload path outside it could not be recorded. The table now names the connection's character set and collation, and a new migration moves those columns of an existing delivery log onto them. On such a database it rebuilds the table, which takes as long as copying the delivery log once; anywhere else it changes nothing. If you manage the migrations yourself on MySQL, copy `0001_01_01_000004_put_the_mysql_delivery_log_on_the_connection_charset.php` from the package's `database/migrations` into your own; publishing `webhooks-migrations` again would write every platform migration a second time.
- **An offloaded payload reads the same `payload_type` as the same payload stored inline.** Both offload stubs, the delivery log's and the inbound call log's, kept the payload's `type` only as a string, the inbound one only as a non-empty string. For a number, a boolean, an object or an array, on MySQL also for a JSON `null` and in the inbound log also for an empty string, the generated column read the value inline and `NULL` once the payload was offloaded, so a query on it returned a subset that depended on the offload threshold. The stubs now keep the `type` member as the payload carries it. The guide to querying the tables names the one value the two engines read differently: MySQL reads a JSON `null` type as the string `null`.
- **`webhooks:preflight` names an identity column on a binary collation that pads with spaces.** It took any `_bin` collation for at least as strict as the shipped `utf8mb4_0900_as_cs`, but the older binary collations such as `utf8mb4_bin` are PAD SPACE: an id and the same id with a trailing space compare equal there, so the dedupe index refuses the second as a duplicate of the first. The preflight now reads the padding from the server and fails for such a column, with the `ALTER` that restores the shipped collation. `utf8mb4_0900_bin` does not pad and still passes, and the two migrations that pin the rollup's owner columns now treat both the same way.
- **A server that only speaks PostgreSQL's protocol is refused by name before the first migration.** The PostgreSQL check read the driver and the version alone, and a compatible engine reports a version the floor accepts: CockroachDB reports 18.0.0, YugabyteDB 2.25 reports 15.2-YB-2.25.0.0-b0. `migrate` then failed inside a migration with a raw error. The check now also reads the server's `version()` banner and refuses one that is not PostgreSQL's, at migrate time and in `webhooks:preflight`, naming what the server reported.
- **Partition maintenance no longer makes the delivery log wait behind a long transaction.** Creating, attaching or dropping a partition waited for any open read of the log, and while it waited every later read and write of the log waited behind it, for as long as that read lasted. Each schema statement of `webhooks:partition-maintenance` now waits at most two seconds for its lock and otherwise gives up with PostgreSQL's lock timeout; the next run does what it left.
- **A delivery written while partition maintenance drains its month is no longer refused, and the drain no longer fails on it.** When the default partition held rows of the current month, `webhooks:partition-maintenance` attached the month's partition while deliveries kept arriving. A delivery routed to the default during the attach was refused once it committed ("new row for relation webhook_deliveries_default violates partition constraint"), so `Webhooks::dispatch()` failed in the request, and a delivery that reached the default after the drain's last move made the attach fail instead. Measured at about 50 deliveries a second: 23 of 849 deliveries were refused, and 16 of 300 runs failed. The last move and the attach now run in one transaction that first stops the log's writes, which then wait and land in the new partition: 42 ms after a drain of 200,000 rows, 235 ms after one of 1,000,000. Creating a month the default holds nothing for counts again under the create's own lock, so a delivery that arrives meanwhile is drained rather than failing the create.
- **A replay or a detail view for an id that is not a delivery's is answered as not found on PostgreSQL too.** The delivery log's and the self-service panel's `redeliver`, the dashboard's replay and its detail drawer passed the id the browser sent to the database unchecked. PostgreSQL answered anything other than a UUID with an error (`22P02`), so a tampered id was a server error there, and in the drawer it failed the whole render, while MySQL answered not found. All four now look the delivery up through `whereSubmittedKey()`.
- **`webhooks:refresh-metrics` fills a rollup that holds no snapshot instead of failing on every run.** It refreshed concurrently when no transaction was open and blockingly inside one, on the assumption that PostgreSQL refuses `CONCURRENTLY` in a transaction block, which it does not: that is `CREATE INDEX CONCURRENTLY`. What PostgreSQL refuses is a concurrent refresh of a view without a snapshot, the state a schema-only restore leaves behind, and there every scheduled run failed with `0A000`, and the dashboard's reads with it. The command now asks the view: concurrent when it holds a snapshot, inside a transaction too, and blocking once when it does not.
- **An inbound delivery whose dedupe header is not valid UTF-8 is stored instead of failing every retry.** The key went into `webhook_id` as it came, and PostgreSQL (`22021`) and MySQL (`1366`) refused the bytes after the signature had verified, so the request answered 500 each time; measured with a GitHub source keyed on `X-GitHub-Delivery`, whose signature covers the body only. Such a key is now hashed like an over-long one (`sha256:…`), so a retry still dedupes. An event type from a header was already stored, with the stray byte replaced.
- **Three shipped comments say what MySQL does with `INSERT IGNORE` and with an offset-bearing timestamp.** `INSERT IGNORE` does not downgrade a deadlock, which MySQL 8.4 still raises as 1213, and it stores a value too long for its column cut short rather than skipping the row. An offset-bearing literal is not one that matches nothing on MySQL: 8.0.19 and later convert it into the session time zone, so a comparison slides by that offset.
- **The Pulse card counts each delivery once when the host also lists its recorder in `pulse.recorders`.** The provider wires the recorder to the delivery events itself, and Pulse wires every recorder listed in its config as well, so a host that followed Pulse's convention had every delivery recorded twice: four entries for one success instead of two, measured with Pulse 1.8.1. Listed there, the recorder is now Pulse's to wire, and `'enabled' => false` on that entry switches the recording off.
- **`webhooks:asyncapi --format=yaml` keeps an empty list a list.** The YAML was dumped from the JSON decoded to arrays, where an empty list and an empty object are the same `[]`, and both came out as `{}`: an empty `required` in a catalog schema, or an empty list in an example, became a map in the YAML document while the JSON one kept the list. Measured with symfony/yaml 8.1.8; the empty maps of an empty catalog stay maps.
- **The activity legend, the recent-queue code line and the portal's replay notice take their type from WireKit.** They set a text size from WireKit's tokens by hand, and now render through `<x-wirekit::text>` like the rest of both screens: with its family, letter spacing, line height and weight, and with whatever your WireKit configuration sets for that component. On a narrow recent-queue panel the response code under the event reads in the body weight, as it does in its own column on a wide one, rather than in the row header's heading weight.
- **The endpoint list's loading placeholder reserves the height of the empty list WireKit 2.64 draws.** From 2.64 WireKit pads its empty state vertically with its side padding, and the empty list stands 226px tall against a 208px placeholder, so the screen jumped when it loaded. The placeholder now reserves 224px, which also stays within one spacing step of the 210px that earlier WireKit releases draw.
- **The confirmation buttons follow their row action's icon, as the config said they would.** A published config without `delete_confirm` or `rotate_secret_confirm` had both filled in from the shipped file by the package's config merge, so a confirmation drew `trash` or `refresh` whatever icon the row used. The shipped file no longer sets the two keys; unset, they follow `delete` and `rotate_secret`, and an installation that never changed either renders exactly as before.
- **Six shipped statements now describe what the package does.** A blank `dashboard.all_tenants_ability` refuses with the remedy that repairs it, setting the key, instead of advising to define an ability that changes nothing. `rate_limit` is described as the fixed window it is: up to twice the limit can arrive across a window boundary, and the check and the count are separate steps, not an atomic token bucket. `register_routes: false` keeps the secret panel's script route. The portal routes file no longer advises putting the manage gate into the middleware, which would answer 403 whatever `refuse_with` says, and a broken sentence in the header-masking comment is whole again.
- **Four event docblocks and the events pages say what the events do.** `WebhookDeliveryFailed` also fires for a delivery refused unsent, its status saying so; `WebhookAttemptFailed` fires for the final attempt too, before `WebhookAttemptsExhausted`; `WebhookDeliveryDispatching` cannot veto or change a delivery, only observe it; and `InboundListenerFailed` also covers `InvalidWebhookSignature`, whose request was not authentic. The events page's example skips refused deliveries when it notifies an endpoint owner.
- **Canonical JSON (`WEBHOOKS_CANONICALIZE_JSON`) orders keys by their bytes.** PHP's default comparison ordered integer keys numerically (`9` before `10`, where RFC 8785 puts `10` first) and was no total order, so the same keys could serialize differently by insertion order. A receiver that re-canonicalizes now reproduces the signed bytes for such payloads too.
- **A form body that lost an over-nested field is refused in every environment.** PHP reports the dropped field only while `display_errors` is off, so the same body was refused in production and read as its remaining fields wherever the setting was on.
- **A bodyless legacy call imports as the empty object `{}`**, as `webhooks:import-calls` documents, not as `[]`.
- **An endpoint whose stored event types are not a list no longer takes down the operator's endpoint list.** A single name written without its brackets reads as that name, and a JSON null as no types; both threw a TypeError while the list rendered its rows.
- **The dashboard reads its payload settings null-safe.** `WEBHOOKS_DASHBOARD_PAYLOAD_DENIED=false` or `null` is read as `hidden`, like any other unknown value, and a payload ability switched off the same way falls back. Both threw an exception on the screen that opens a delivery.
- **`PendingWebhook::timeoutInSeconds()` and `connectTimeoutInSeconds()` floor at one second.** A 0 reached the HTTP client as no timeout at all, holding the request until the job's own timeout ended it, and a negative value went through unchecked.
- **The shipped config points at the styling guide on the documentation portal.** It sent the reader to a "Styling the UI" section of the README, which no longer exists.
- **The shipped config names both sources that reach `undetermined_status`.** It said only a `verifier` can, so a host with a `jwks` source read past the setting, while a key set that cannot be fetched is answered with it too.
- **A JWKS that mixes a numeric `kid` with a key without one keeps both keys.** A key without a `kid` is indexed by its position, and a numeric `kid` such as `"1"` landed in the same slot, so one of the two keys was dropped without a word and a pinned `kid` could resolve to the other key. The kids are now placed first and a key without one takes the next free position, in document order.
- **`InboundWebhookVerified` no longer promises the `kid` of a JWKS key.** Its docblock and the events page said `matchedKeyId` names the key's `kid` when the keys come from a JWKS document; it has always named the first and the second Ed25519 key of the document as `current` and `previous`, by position. Both now say so, and that a pinned `kid` reports its one key as `current`.
- **The shipped config and the dashboard migration name the t-digest aggregate the code uses.** Both said the Tier-2 percentile driver merges the hourly digests with `rollup()`, a function the tdigest extension does not define; the query has used the extension's `tdigest(tdigest)` aggregate since 2026-09-10. A DBA checking the extension before switching the driver on now finds the function that runs.
- **A delivery that `webhooks:partition-maintenance` is moving out of the default partition is still sent and still updated.** The move puts rows into a month that is attached only at its end, and until then the check before each send took a moved delivery for erased and refused it, and the status writes for it were dropped. Both now find the row where the move put it.
- **The PostgreSQL version refusal no longer says 13 is tested.** It called PostgreSQL 13 the oldest version the package is "built and tested against", while the package is proven on PostgreSQL 18. It now calls 13 the oldest it supports, which it remains.
- **Writing a delivery no longer reads it back for search when the delivery source model is not searchable.** With `search.enabled` on for the inbound log alone, every write to the delivery log read its row by id, across every monthly partition, and dropped it. The read that remains, for a searchable source model, carries the partition key.
- **The dashboard no longer reports a MySQL host's time zone offset as rollup lag.** Before the first `webhooks:refresh-metrics`, the lag is the age of the newest delivery, and its naive UTC timestamp was read in `app.timezone`: in Berlin in summer a delivery one minute old read as two hours and one minute, and the stale-rollup notice appeared at once.
- **The bundled Boost skill no longer carries lines meant for its author.** Its opening paragraph asked the reader to keep the skill focused on adoption, and its last rule not to document package internals in it, which an agent in your application read as rules about its own work.
- **The Pulse card names a single failure in the singular.** In Spanish, Italian and Portuguese it read "1 fallidos", "1 non riuscite" and "1 falhadas"; Spanish now also uses the feminine the rest of the package uses for a delivery.
- **The Pulse card no longer counts a refused delivery as a failure.** A delivery refused before it was sent, because its endpoint was switched off while it waited in the queue, raised the card's failure rate, while the circuit breaker, the endpoint health score and `DeliveryEngineCheck` leave it out. It is now counted on its own and shown under the rate.
- **`webhooks:partition-maintenance` exits non-zero when it found deliveries in the default partition.** It still moves them, provisions and prunes; the exit code is what reaches an `onFailure()` hook, since the scheduler discards the warning it printed.
- **A capped portal registration works on a cache store that cannot lock.** With `max_endpoints_per_tenant` set, every registration asked the default cache store for a lock, and the `session`, `apc` and `storage` stores answered with an error. On those stores the registration now runs without the lock, and the self-service page says what that leaves open.
- **The portal's delivery filter keeps a readable endpoint when another endpoint is saved or deleted.** An endpoint a host declared readable through `ReadableEndpoints` is not the reader's own, and the refresh after each save or delete asked the owner lookup whether it still existed, so the filter was dropped and the list widened to everything readable.
- **The endpoint console offers only the actions the operator may take.** A host that holds the console at one ability and lifts only `delete` showed every operator a delete dialog that ended in a refusal. The console now asks each ability before it draws the control, as the delivery log does; the actions still refuse on their own.
- **The hourly activity and latency panels read the hourly rollup once per refresh, not twice.** Each sized its bars from a peak that read the rows a second time, on every render and every poll of an open dashboard.
- **Recomputing every endpoint's health recomputes only the endpoints the board shows.** Past 200 endpoints the board shows the first 200 and says it is cut, while the pass recomputed 201, so one endpoint nobody could see had its health rewritten in the same request.
- **A single row's health recompute is braked like the full pass.** The recompute button on one row of the health board ran without the per-minute allowance the full pass has, so pressed in a loop it added up to the full pass one request at a time, two queries per press. It now counts against `platform.self_service.recomputes_per_minute` on an allowance of its own, so a few row presses never refuse the full pass, and a refused press shows the same message the full pass does.
- **`DeliveryEngineCheck` reports a failing endpoint beside a switched-off one.** The check ended at its first warning, and a switched-off endpoint ranked first, so while one endpoint was off the next one on its way to the circuit breaker was never named. A rate above the warning share, switched-off endpoints and failing endpoints are now reported together in one warning, and the short summary names each of them, such as `1 endpoint off, 1 endpoint failing`. A single warning reads as before, and a failure still ends the check on its own.

### Security

- **The self-service policy refuses every ability while `manage-webhook-endpoints` is undefined.** It authorized on tenant ownership alone in that case, the opposite of the fail-closed gate it is registered with. The package always defines the ability together with the policy, so only a policy registered by hand could reach the old branch.
- **The search index stores a delivery's endpoint URL without its userinfo and query.** With search switched on, `toSearchableArray()` wrote the URL whole into an index that is shared and kept outside the application, while the delivery's error text is redacted of exactly those parts. The index now gets the URL by the same rule, and the configuration and the search guide say so.
- **The dashboard names an endpoint without a name by its host, not by its URL.** The dashboard shows no endpoint URL anywhere, and its readers need not manage endpoints, but the accessible names of the delivery rows, the replay buttons and the drawer fell back to the full URL, userinfo and query included, when an endpoint had no name. They now fall back to the host, and to the endpoint's id when there is no host to read.
- **The script the screens load is cached for a year only under the URL that names its current content.** The endpoint answered every `id` with `public, max-age=31536000, immutable`, and the hash of the next release can be read from its source before it ships. A shared cache asked for that URL ahead of an update would have kept the old script under it for a year. Any other `id` now gets the script with `no-store`.
- **Ed25519 verifies the timestamp as the producer sent it, as the Standard Webhooks HMAC dialect already does.** It verified over the integer the header was normalized to, so a producer that signed a leading zero as sent was refused, and a delivery signed canonically stayed valid with its header rewritten to another spelling of the same instant. A delivery this package signed is unaffected.
- **`webhooks:preflight` names a receiving source whose secret gives a short HMAC key.** A secret that decodes to a few bytes, such as `whsec_test` with three, was accepted without a word, and a key that short can be found offline from one captured delivery. The preflight now warns for every source whose `secret` or `previous_secret` gives a key shorter than 16 bytes, and `WebhookConfig::signingKeyAdvisories()` returns the same messages for a health check. The configuration keeps working; nothing is refused.
- **A malformed webhook URL is no longer quoted in the error a delivery stores.** The SSRF guard's refusal named the URL, and the redaction of stored error messages finds a URL by its `http://` or `https://`, which a URL that fails to parse often lacks. So `https:/user:password@host/hook?token=…` reached the delivery log with its credentials whole. The refusal now says the URL is malformed without quoting it; the delivery row already says which endpoint it was.
- **The standalone server delivery log stores the endpoint URL without its credentials.** `webhook_server_deliveries.url` held the URL as it was sent, userinfo and query included, while the `error` column beside it was redacted of exactly those parts. The URL is now stored by the same rule: without userinfo, query and fragment.
- **The documentation of the signing secrets says where they are.** Three docblocks described a resolver that loads an endpoint's secret by id and keeps it out of the queue. There is no such resolver: every queued delivery carries its endpoint's secrets sealed with the application key, so whoever holds the queue and that key can read them, and a delivery queued before a rotation signs with the secret it was queued with for as long as it is retried. The docblocks say so now, and the sending guide says to keep the rotation window longer than a delivery can stay in the queue.
- **The egress proxy URL is sealed at rest, like the mutual-TLS passphrase.** A proxy that authenticates carries its credentials in the URL, and that URL traveled in cleartext in every queued delivery, in `failed_jobs` and in the delivery data of every attempt event, while the passphrase beside it was sealed. It is now sealed with the app encrypter and unsealed only at send time, so `$event->data->options->proxy` holds ciphertext; `toTransportOptions()` returns the URL. A delivery queued before the upgrade still goes through the proxy it carries.
- **The endpoint forms bound how many event types an endpoint carries, and how long each may be.** Without an event catalog, the default, the self-service portal and the operator console stored any number of event types of any length that a request written by hand sent, into the column the fan-out matches on and the console renders in every row. A save now carries at most 100 types of at most 255 characters each without a catalog, and at most as many as the catalog accepts with one. The length is the width of the MySQL index over the column, which refused a longer name with a server error. An endpoint that already holds more keeps them through an edit.
- **A delivery queued for an endpoint whose URL has since changed is no longer sent to the old address.** The URL is fixed when a delivery is queued, and the check before sending refused it only when the endpoint had been disabled or deleted, so a tenant who moved an endpoint to a new domain went on sending the queued deliveries and their retries to the one they had left. They are now refused like those of a disabled endpoint, and a replay sends them to the new URL.
- **`webhooks:import-calls` stores headers the way the live receiver would.** The import masked `Authorization` and `Cookie` and nothing else, and it stored every header a legacy row held, so a header a source's `redact` list hides arrived in the call log in clear, and `store_headers`, which keeps none by default, was not consulted. A source declared under `webhooks.client.configs` now gets its own `store_headers` selection and `redact` list; a source with no config is masked as before.
- **The inbound call log and the server delivery log keep the columns the engine owns out of mass assignment.** Both models accepted every column through `create()` and `fill()`, unlike the Platform delivery log, so a host subclass filled from request data could move a call to another source or forge a server delivery's outcome. `WebhookCall` now names its fillable columns and leaves out `source`; its `status` stays assignable, because your handler is what advances it. `WebhookServerDelivery` accepts `message_id`, `url`, `event_type` and `tags`, and its `uuid` and outcome columns are written by the engine through `forceFill()`. A host that writes one of these columns itself sets it the same way.
- **The preflight names a source that picks its handler from a header its signature does not cover.** `GitHubScheme` and `PlainHmacScheme` sign the body alone, and the recommended GitHub setup reads the event type from `X-GitHub-Event`, so a captured authentic delivery resent under another event type verified again and reached another handler in `process`. Nothing said so. `webhooks:preflight` now prints an advisory for such a source, unless its dedupe key is read from the body, and the config comment, the receiving guide and `EventTypeResolver` say the handler confirms the type against the body.
- **An inbound verifier that throws is answered as undetermined, not with a 500.** An `InboundVerifier` is asked to report a provider it could not reach as undetermined. One that let the transport's exception through instead, a provider callback that timed out for example, turned every forged POST into a `500` from an anonymous caller, which a producer reads as an invitation to retry. The receiver now reports the exception and answers it like any undetermined verification: `undetermined_status` where the config sets one, `invalid_status` otherwise. Nothing is stored.
- **The JSON metrics endpoint is braked per caller.** With `dashboard.expose_json_api` on, every request computed live latency percentiles over the window it asked for, up to 30 days of rows, and nothing limited how often one holder of `view-webhook-dashboard` could ask. The route now carries its own throttle, `dashboard.api_max_per_minute` requests per minute and caller (default 60, `WEBHOOKS_DASHBOARD_API_MAX_PER_MINUTE`), and answers `429` past it. It sits on that route alone, so the dashboard page and its panels are not throttled; `0` switches it off for a host that throttles the path itself.
- **A redacted delivery body hides a key that carries data.** Without `view-webhook-payload` the drawer showed a body's structure with every value replaced by its type, and every key as it was, so a body keyed by email address, account number or customer number showed exactly those. A key is now kept only while it reads as a field name: a letter or an underscore, then letters, digits, `_`, `.` or `-`, at most 64 characters, one optional leading `@`, and no four digits in a row. Any other key becomes `[key 1]`, `[key 2]` and so on, in order, so the entries stay countable. A list keeps its positions.
- **A dashboard panel asks the view gate again before it renders, also right after its lazy placeholder.** The seven lazy panels asked `view-webhook-dashboard` when they booted, and Livewire does not boot a component on the request that follows its placeholder. That request is meant to load the panel, and nothing made it so: a `$refresh` sent instead rendered the panel in full, so a reader whose ability had been revoked could still read an embedded panel from a page they had opened before. The dashboard's own route was not affected while its middleware carries `can:view-webhook-dashboard`. Each panel now asks the gate before every render as well.
- **An empty `WEBHOOKS_HTTPS_ONLY=` keeps endpoints on HTTPS.** The variable was read so that a set but empty value counted as off, so a line copied from an `.env` template, or a variable a deployment tool created without a value, let a tenant register a plaintext endpoint for signed traffic without a word. An empty or blank value now counts as unset and takes the default, on; `false`, `off`, `no` and `0` still switch it off. The switches that turn a layer on or off read an empty value as off, as before.
- **One inbound source can no longer answer for another whose name extends it with a colon.** The fast path's seen marker was keyed `webhooks:seen:{source}:{id}`, so source `tenant` with id `42:evt_1` and source `tenant:42` with id `evt_1` shared one key. A delivery to the first, signed with its own secret, marked the second's as seen, and the second was answered 200 and never stored. The key now carries the length of the source name. Markers written before the upgrade lapse within the tolerance window, and the database still recognizes a repeat on its own.
- **A JWKS URL that carries a token in its query no longer reaches the log.** When the key set had no usable key, or not the pinned `kid`, the reported message named the URL whole, on every delivery for as long as the key was missing. It now names the URL without its userinfo, query and fragment, as the report of a failed fetch already did.

### Documentation

- **The bundled Boost skill names every publish tag and Artisan command.** It named eight of the twelve tags and one of the ten commands, so an agent in your application learned neither of the operator console's two view tags nor that the package's maintenance needs Laravel's scheduler. It now lists the view and translation tags, the four scheduled commands with their cadence, and the five you run yourself.
- **The dashboard guide says what a metrics refresh costs.** Each run of `webhooks:refresh-metrics` rebuilds the full 35-day window, so its cost grows with the deliveries in that window rather than with what changed. The guide lists the times measured on PostgreSQL and MySQL and the two settings that answer high volume: a slower `dashboard.metrics.refresh` token, and on PostgreSQL more `work_mem` for the scheduler's connection. The shipped config says the same beside the key.
- **The sending guide says that the queued job carries the whole body.** `server.large_payload` moves the delivery log's copy of a large payload to a disk, not the queued job, and Laravel's queue encoding makes the job up to one and a half times the body's size for JSON full of quoted strings. On SQS, which refuses a message above 1 MiB, the guide points to the `sqs` connection's `overflow` option from Laravel 13.9, and the shipped config says the same beside `server.large_payload`.
- **The two retry caps name the SQS limit each one actually meets.** The config and the backoff class called the jitter cap's 900 seconds SQS's visibility-timeout ceiling, but an ordinary retry is a released job, and SQS holds one back for up to 12 hours. The 15-minute limit belongs to a message delay, which is what coming back at `retry_after_cap` dispatches. The comments say so now, and the sending guide tells an SQS host which cap may go above 900 and which may not.
- **The console guide and the 1.x upgrade guide say which Livewire releases leave a published `delete(…)` button dead under a strict CSP.** Both said that button never worked under a strict Content-Security-Policy. Livewire's CSP-safe build refuses `$wire.delete(…)` up to 4.4.4 only, and from 4.4.5 the call reaches `delete()`, which forwards to `destroy()`. The docblocks of `destroy()` and `delete()` on the portal's endpoint list and on the operator console say the same.
- **The security policy says what holds for an installed copy.** The supported-versions table answers in words rather than in shortcodes that show as their raw names outside GitHub, and the dependency paragraph says that the versions in your application come from your own `composer.lock`, kept current with `composer update` and checked with `composer audit`. The bug form asks for the version `composer show` reports.

## [3.14.0] - 2026-10-03

### Fixed

- **A connection with a table prefix is refused with a message instead of failing mid-migration.** The schema builder and Eloquent add the prefix, the package's own SQL names its tables as they are, so the first migration created the prefixed table and then failed on the unprefixed name. The connection check now refuses a prefixed connection before anything runs, and names the prefix and the way out.
- **The default-partition drain carries a column a host added under a reserved or capitalized name.** The drain reads the table's columns from the catalog, so that it moves every column including one a host's own migration added, and it spliced those names into its statements as they were. A column named `order` or `TenantId` broke the statement, and the daily maintenance run with it, on every retry. The names are quoted now.
- **`webhooks:preflight` without `--connection` checks the connection the package stores its tables on.** It read the engine and the collation of the application's default connection and the owner key declaration of `webhooks.database.connection`, so a host that keeps the tables on a side-car could be told the install passed while the side-car's engine and collation were never read. Without the option every check now reads `webhooks.database.connection`, or the application default when that is not set, and a blank `--connection=` counts as no option.
- **The circuit breaker stores the instant it switched an endpoint off.** It wrote `disabled_at` through a builder update, which binds a timestamp as a naive literal in the application's time zone, so outside UTC the column held an instant an offset away from the real one. `WebhookEndpointAutoDisabled` handed listeners that time, and a host query for the endpoints disabled in the last day missed breaker rows or took in others. The value now goes through the model's conversion, as it does in `Webhooks::disable()`.
- **`webhooks:import-calls` reads a timestamp without an offset in the application's time zone.** It read such values as UTC, while a table written by Eloquent's `timestamps()` holds the application's wall-clock time, so outside UTC every imported call landed an offset away from its real time, and a second run could not correct it because the import skips the ids it already wrote. A source table that really stores UTC takes the new `--from-timezone=UTC`; a value that carries its own offset keeps it either way.
- **`webhooks:import-calls` refuses to read the table it writes to.** Without `--from-table` its source is `webhook_calls`, and on an installation with one connection that is the package's own table: the import copied every call into itself under a new id, and a dry run announced the copies as calls to import. It now fails with a message naming the options to use, also when a second connection name reaches the same database.
- **The payload transform editor trims the invisible characters a copied field name brings along.** The include and exclude lists, both halves of a rename and the rewrap key went through PHP's `trim()`, which removes ASCII whitespace only, so a name copied with a no-break space, a zero-width space or a byte order mark saved a rule that matched no key and changed nothing on any delivery. They now go through `Str::trim()`.
- **A delivery on an SQS FIFO queue is no longer deferred onto that queue.** A FIFO queue takes no delay per message, so a deferred job came back on the next poll, and an endpoint's `Retry-After` longer than the cap was answered once per remaining deferral without the pause it asked for. On such a queue the hint is now clamped to the cap and counted as an ordinary retry, which a FIFO queue does wait for. A rate-limited delivery on the sync connection or a FIFO queue does not wait for its slot, a FIFO queue holding it for at most its own configured delay, and `WebhookDeliveryRateLimited` still fires with the delay. The configuration says so.

### Security

- **The SSRF guard refuses every IPv6 block IANA lists as not globally reachable.** `5f00::/16`, which holds the segment routing identifiers an operator routes inside its own network, and the dummy prefix `100:0:0:1::/64` passed as public, and so did the unassigned part of the IETF protocol assignments block `2001::/23`. All three are refused now. `2001::/23` is refused whole, like its IPv4 counterpart `192.0.0.0/24`: the addresses in it that IANA does list as reachable are anycast services, AMT relays, AS112 sinks and drone identifiers, none of them a webhook receiver.
- **The SSRF guard refuses IPv6 outside the global unicast space `2000::/3`.** IANA assigns routable IPv6 from that block only and keeps the rest reserved, but the guard judged an address there by its list of blocked ranges, so every reserved address the list did not name passed as public, such as `4000::1` or `::2:3:4:5`. Such an address is refused now. An IPv6 literal that embeds an IPv4 address is still judged as that IPv4 address.

## [3.13.0] - 2026-09-27

### Changed

- **`composer.json` no longer suggests `laravel/ai`.** The suggestion described a tool this repository uses to test itself, not something an application installing the package needs.

### Fixed

- **`webhooks:prune-orphaned-payloads` deletes only the objects the package wrote.** It swept every file under `webhooks/` on the disk and deleted what no log row referenced, so a host's own file under that prefix, such as `webhooks/exports/report.csv`, was deleted as an orphan. Only keys in the shape the package writes, `webhooks/{ab}/{sha256}`, are candidates now. Any other file there is left alone, and the command says how many it passed over.
- **Across tenants, the dashboard shows one bar per hour and the metrics API one bucket per hour.** The hourly rollup keeps a row per tenant and hour, and the cross-tenant view (`dashboard.all_tenants`) read those rows as they were. The activity chart drew a bar per tenant under the same hour label, and `hourly` in the metrics API listed the same bucket once per tenant with nothing to tell the entries apart. The counts of an hour are now summed across tenants. Its percentiles cannot be, so in that view the latency panel leaves the hourly trend out and says why, and the API reports `p50_ms` and `p95_ms` of those hours as `0.0`, its value for an hour without a measured latency. The window percentiles under `kpis` are computed live and are unaffected.
- **A tenant's replays from the dashboard spend the same allowance as in the portal.** The self-service portal limits how many deliveries a tenant may replay per minute (`self_service.replays_per_minute`), because every replay makes the server send a request to a URL the tenant registered. The per-tenant dashboard offered the same replay without any limit, so a tenant could send as many as it liked from there. Both now spend one budget per tenant. The operator views of the dashboard, like the operator console, are not limited.
- **`webhooks:prune-orphaned-payloads` and `webhooks:egress-ips` exist without the Platform layer.** Both were registered only while `webhooks.platform.enabled` was on, although the config recommends them to exactly the hosts that run without it: the prune command to a receive-only host that offloads inbound bodies, the egress list to a send-only host behind a proxy. Such a host got "Command not defined", and a scheduled prune failed in output nobody reads. Both are now registered whichever layers are on.
- **`vendor:publish --tag=webhooks` no longer holds the operator console at its neutral rendering.** `webhooks-views`, which the umbrella tag includes, copied the whole view tree, the console's neutral variant with it. A published view always wins, so a WireKit host that published config and translations through the umbrella lost the WireKit console. `webhooks-views` now leaves out both console variants, which `webhooks-ui` and `webhooks-ui-wirekit` publish. If you ran the umbrella before and never edited the console views, delete `resources/views/vendor/webhooks/livewire` to get the WireKit rendering back.
- **The platform rate limit holds under load that keeps arriving.** An endpoint's allowance was a fixed window that restarted at the first delivery after it lapsed, while the overflow of earlier minutes was already scheduled into the minutes ahead. Every new minute then sent its whole allowance on top of that overflow, so traffic arriving steadily above `max_per_minute` reached the endpoint at the full incoming rate. Each minute of the schedule now counts what is scheduled into it: the excess waits longer, and no minute carries more than the allowance. A single burst is spread exactly as before.
- **A delivery sent straight through the builder runs on the configured queue and connection.** `webhooks.server.queue` and `webhooks.server.connection` were applied only by the platform fan-out, so `WebhookSender::to()->payload()->dispatch()` pushed its job onto the default queue and connection, where a host's dedicated webhook workers never saw it. `PendingWebhook::create()` now seeds both like every other server setting, and the builder's `onQueue()` and `onConnection()` still override them per call.
- **A search engine that fails no longer costs an inbound call its processing.** The receiver indexed a stored call before queueing it, so an engine that threw answered the producer with a 500. Its retry then met the stored row as a duplicate, was answered 200, and the call was never processed. The call is now indexed after it is queued, and an engine failure is reported instead of failing the request.
- **A stored inbound call is never taken for a duplicate by the read that follows it.** After inserting a call, the receiver read the row back through the configured model on the default connection, and an empty result counted as a duplicate: answered 200 and never processed. A replica behind a `read`/`write` split without `sticky`, or a global scope on the host's model, produced exactly that. The row is now read from the write connection without the model's global scopes. A row that still cannot be read back is removed again and the request fails, so the producer's retry stores it anew.
- **The portal's window picker keeps every window on offer after the reader narrowed it.** The choices were built against the window the reader had picked rather than the host's ceiling, so after choosing seven days the picker offered seven days and nothing else, and only a reload brought the wider windows back.
- **`webhooks:preflight` no longer fails a MySQL rollup keyed by uuid or ulid owners.** The rollup's `owner_id` is the second column of its unique index, and under a `uuid` or `ulid` owner key it is a `CHAR` column that took the connection's collation, which is `utf8mb4_unicode_ci` in Laravel's default configuration. The preflight then failed a table the package had created, and its repair hint named a collation the shipped schema did not declare. The create migration now declares `utf8mb4_0900_as_cs` on the column, and a new migration applies it to a table migrated before, so run `php artisan migrate` after updating.
- **The MySQL connection check no longer raises a deprecation on PHP 8.5.** It read `PDO::MYSQL_ATTR_FOUND_ROWS`, which PHP 8.5 reports as deprecated, before every migration of the package. It reads `Pdo\Mysql::ATTR_FOUND_ROWS` now, which carries the same value, so a connection that sets the option under its old name is still refused.
- **The security policy names the version line that receives fixes.** `SECURITY.md` still said that only the latest `2.x` minor is supported, although every release since `3.0.0` is on `3.x`. It now names `3.x` and lists `2.x` as ended with `3.0.0`.
- **A payload rename no longer drops a field when the rename after it is refused.** A rename target counted as free as soon as another rule moved that key away, whether or not that rule went ahead. With `['a' => 'b', 'b' => 'c']` over a payload that already carries `c`, the second rule was refused and `b` stayed, but `a` was still moved onto it, and the value of `b` was lost without a trace in the signed body. The refusals are now settled together, so such a chain is refused as a whole and the payload keeps every field.
- **`ui.secondary_surface` reaches the dashboard.** The key promised every shipped screen, but the dashboard's replay buttons and the close button of its delivery drawer stayed hardwired to `ghost`, so a host that set its own secondary style saw it on the operator screens and in the portal and not on the dashboard. The three buttons read the key now.

### Security

- **A source whose keys are public no longer uses them as an HMAC secret.** An entry with a `jwks` url, or with a `whpk_` public key as its `secret`, fell back to the Standard Webhooks scheme when it named none, and that scheme computes an HMAC with its key: anyone who could read the public key could sign a delivery the endpoint accepted. Such an entry now verifies with `Ed25519Scheme` when it names no scheme or `'auto'`. One that names any other scheme for public keys is refused like a config that cannot verify, answered with its `invalid_status` and reported by `webhooks:preflight`.
- **An unusable entry in `admin.abilities` no longer lifts the check `'*'` holds.** An exact-action entry that was not a non-empty string, such as `'delete' => env('WEBHOOKS_DELETE_PERMISSION', '')` with the variable missing, won over the catch-all, was then discarded, and left the action to `admin.ability`, which is unset by default. The action ran with no check while every other action stayed under `'*'`. Such an entry is now ignored as the configuration describes: the action stays under `'*'`, and only an unusable `'*'` falls through to `admin.ability`.
- **Reading every tenant's deliveries no longer lets a user replay them.** In cross-tenant mode (`dashboard.all_tenants`) the delivery policy fell back to its turnkey default when the host had not defined `webhooks.manage`, so the read-only ability that opens that view, `view-all-tenant-webhooks` by default, was enough to resend any customer's delivery to that customer's endpoint. In that mode a replay now needs `webhooks.manage` defined and granted, and is refused while it is undefined. The per-tenant dashboard keeps its default, where owning the row is enough.
- **Every dashboard panel checks the view gate itself, on every request.** The panels are registered under an alias each so a host page can embed one, and the `view-webhook-dashboard` gate hung only on the dashboard route's middleware. Embedded in a page without that middleware, or behind a `dashboard.middleware` without `can:`, a panel rendered its rows and offered its replay to a user the gate denies. Each panel now asks the gate on its first render and on every update, as the JSON metrics endpoint already did, so a reader whose ability was revoked is also refused on the next refresh of an open tab.
- **A replayed delivery that the profile filters out no longer spends the source's rate limit again.** A filtered delivery was counted against the limit but never marked as seen, so every replay of the same signed request inside the timestamp tolerance spent another token. Anyone who could capture one such delivery could empty the source's allowance with it, and the real producer's next deliveries were refused with 429. A filtered delivery is now marked like a stored one, so its replays are answered without spending anything.
- **An endpoint whose host does not resolve is refused with the field error, in the portal and in the operator console.** The guard reports such a host with its own exception, and neither form caught it, so saving answered with a server error and logged the host on every attempt. The answer also differed from the one for a host that resolves to a private address, which let a tenant find out which internal names exist. Both forms now show the same sentence for both cases.
- **The test-ping brake counts per destination as well as per endpoint.** Endpoint URLs are not unique, so one tenant could register the same destination again and again and ping each copy up to `test_ping.max_per_minute`, multiplying the requests the brake exists to bound. A ping now also spends a bucket for its destination host within the endpoint's owner, so repeated endpoints share one allowance, while the same host under another owner keeps its own.
- **Without a configured egress proxy, deliveries and JWKS fetches no longer go through a proxy from the environment.** The transport left the proxy option out when `core.egress.proxy` was unset, and the HTTP client then used `HTTPS_PROXY`, or `HTTP_PROXY` on the command line a queue worker runs on. That proxy resolves the host itself, so the guard's pin against DNS rebinding never reached the connection, and a proxy inside the network could reach destinations the guard refuses. An unset egress proxy now means a direct connection.
- **An address in `core.ssrf.blocked_hosts` is refused however it is written.** The list was compared as text, so a blocked IP address was reachable as a decimal number, as an IPv4-mapped IPv6 literal or in an uncompressed IPv6 spelling, and a name resolving to it passed too. The guard then pinned exactly the address it had been told to refuse. Address entries are now compared as addresses, against the host as written and against every address it resolves to. Name entries still block that name only.
- **The SSRF guard refuses three more IPv6 ranges that are not globally reachable.** The local-use NAT64 prefix `64:ff9b:1::/48` embeds an IPv4 address like the well-known one the guard already refused, so in an IPv6-only network translating on it `64:ff9b:1::a9fe:a9fe` reached the cloud metadata address while the guard classified it as public. The IPv6 benchmarking range `2001:2::/48` and the second documentation block `3fff::/20` are refused as well, like their IPv4 counterparts.
- **The four abuse brakes read an allowance set through `env()`.** The test-ping brake and the portal's registration, replay and recompute brakes took only an int as an allowance and read everything else as "no brake". `env()` returns a number as a string, so a host that moved one of the shipped values into `env('…', 10)` and set the variable switched the brake off completely, with nothing logged. A digit string is now read as the number it spells, a value that cannot be read falls back to the shipped default, and only `null` or a number of zero or less switches a brake off.

## [3.12.0] - 2026-09-25

### Added

- **The operator console can check reading on every request.** Name `view` in `webhooks.admin.abilities` and both components ask it at the first render and on every filter, page turn and refresh after it, so an operator whose capability was revoked is refused on the next request instead of going on reading every tenant's deliveries in the open tab. Neither `*` nor `webhooks.admin.ability` covers reading, so nothing changes for a host that names no `view`. The operator console page now says that a page gate reaches Livewire's own endpoint only as persistent middleware, which `can:` is and a middleware of your own is not until you register it.

## [3.11.0] - 2026-09-25

### Added

- **The delivery engine check can keep reporting a jam that is older than its window.** Both stall limits looked back one window from the limit, so after a worker stopped and nothing new arrived, the last waiting deliveries aged out of it and the check turned green over a queue nobody had cleared. `lookForStallsBack(int $hours)` sets a longer look-back for the two limits alone. Without it the check reads as before.
- **The delivery engine check can warn while an endpoint is failing.** One endpoint that fails while the others deliver keeps the rate of the whole engine low, so nothing warned before the circuit breaker switched it off. `warnWhileAnEndpointIsFailing()` warns while a switched-on endpoint has a failing health score and adds `failing_endpoints` to the result. It needs endpoint health scoring on, and a switched-off endpoint still outranks it in the warning.

## [3.10.1] - 2026-09-24

### Fixed

- **Recompute all on the health board asks the same permission as recomputing one endpoint.** A single row's recompute refused a reader the endpoint policy denies `update`, while recomputing every row did not ask at all, so that reader could recompute the whole board but not one row of it. The board now asks `update` of every row before it recomputes any, a refused pass changes no row and spends nothing of the recompute limit, and a reader who may only look at the board can do neither. A host whose roles hold `update` on their own endpoints sees no change.

## [3.10.0] - 2026-09-22

### Added

- **The health check can tell a stopped delivery engine from a quiet one.** A failure rate cannot see a dead worker: with no outcomes there are no failures, so the rate of a stopped queue reads like a quiet night. `DeliveryEngineCheck::failWhenADeliveryIsPendingLongerThan()` fails when a delivery has waited longer than the limit to be sent at all, and `failWhenARetryIsOverdueAfter()` fails when a delivery to a switched-on endpoint is still waiting for its next attempt after the limit, which is what a worker that stopped between two attempts leaves behind. Both are off until you set them, and their counts join the result only then.
- **The disabled-endpoint warning can leave out endpoints switched off by hand.** `DeliveryEngineCheck::warnOnlyAboutEndpointsTheBreakerDisabled()` warns only about endpoints the circuit breaker switched off; the result still counts both kinds, and adds the breaker's count beside them.

## [3.9.0] - 2026-09-22

### Added

- **The disabled-endpoint warning of the health check can say where an endpoint is switched back on.** `DeliveryEngineCheck::remedyForDisabledEndpoints()` takes a sentence and appends it to the warning, so it reaches the notification Laravel Health sends. The package cannot know the place: one application lets the owning customer do it from their own settings, another has an operator do it from a back office. Without the call the warning reads as before.

## [3.8.0] - 2026-09-22

### Added

- **The package's models are replaceable.** Map `WebhookSubscription`, `WebhookDelivery`, `WebhookServerDelivery` or `WebhookCall` to your own subclass in the new `webhooks.models` config key, and the package uses that class on every path: every query, every row it writes, and the relations between its models. It is the default the two narrower settings fall back on: a client entry without its own `model`, and a dashboard without `source_model`, now use the class mapped here. A class that does not exist, or does not extend the package class, is ignored in favor of the package class.

- **The AsyncAPI document can be served over HTTP.** `Webhooks::asyncApi()` returns the document `php artisan webhooks:asyncapi` writes, built from the catalog as it is configured at that moment, so a host can publish its catalog from a route of its own and choose the path, the middleware and the caching there. Until now the command was the only way to get the document, and it is registered only in the console: a route that called it passed every test and failed in production. The method takes an optional title, the application's name by default, and a document version.

- **A health check for the delivery engine.** With `spatie/laravel-health` installed, register `Pushery\Webhooks\Health\DeliveryEngineCheck` beside your other checks. It reads the delivery log of the last 24 hours, fails when more than half of those deliveries failed and warns while an endpoint is switched off; the window, both thresholds, a minimum sample and the endpoint warning are configurable. The rate is the one the endpoint health score uses: a pending delivery has no outcome yet and a refused one was never sent, so neither counts toward it, and both are still listed in the result. The read is bounded on `created_at`, so on PostgreSQL only the partitions the window reaches are scanned, and the result carries counts and the rate, never a payload, a URL or an error text.

- **`ui.page_heading_level` sets the heading level of the full-page screens' titles.** `1` by default; set `2` when your layout carries its own `h1`, and the dashboard, the portal, the health board and the transform editor title their page one level down. Any other value is ignored in favor of `1`.

### Changed

- **`WebhookSubscription`, `SearchableWebhookCall` and `SearchableWebhookDelivery` are no longer `final`,** so a host can extend them like the other models.
- **The full-page screens title themselves with WireKit's `page-header`.** The dashboard, the portal, the health board and the transform editor built their title by hand, with a size and spacing of their own, beside screens of the host application that all use the component. They now draw it with `x-wirekit::page-header`: the title and its introduction on the left, the actions beside them or below them where the row runs out of room.
- **The orphaned-payload sweep and `webhooks:import-calls --dry-run` read the tables directly** rather than through a model, so a scope on a subclass can never hide a row from them. For the sweep that matters beyond accuracy: a hidden row's payload would be deleted while the row still references it.

### Fixed

- **`webhooks:preflight` asks about the icon set your WireKit preset needs, not about Heroicons.** The shipped screens name WireKit icon aliases and never a set, but the icon advisory asked only whether `blade-ui-kit/blade-heroicons` was installed. On a host whose preset is lucide, phosphor or tabler, with that preset's set installed and every icon rendering, it warned that the icon pair was half installed, so a host that gates on the preflight had to learn to tolerate a warning. It now resolves every icon the shipped screens draw through your WireKit configuration and asks Blade Icons whether it can draw the result, which is the question a page answers when it renders. It names the set your preset resolves into when that set is not registered, stays quiet when you ship the glyphs yourself under the preset's prefix, and names a row action icon that no preset defines rather than sending you to Composer. The `suggest` entries for the two icon packages now say the same.
- **On a phone, a delivery error in the portal gets a column wide enough to read.** With `platform.deliveries.show_errors` on, the error column of the delivery list collapsed to about 80px at 375px wide, one word to a line, while the table scrolled sideways anyway. It now keeps at least 16rem, the same floor the endpoint list gives its URL column.
- **Under Laravel Octane the SSRF guard is built from the container that resolves it.** Its binding read the host resolver and the address classifier through the container the provider was registered with, so a binding a host swaps in for one request was not the one the guard used. The container now arrives as the binding's argument.

## [3.7.0] - 2026-09-19

### Security

- **An IP address written straight into a webhook URL is now refused by the guard itself, not by whichever resolver happens to be installed.** `DefaultSsrfGuard` classified only what the resolver handed back, so the defense against `http://[::1]/`, `http://169.254.169.254/` and every IPv6 literal that carries a v4 address inside it rested on `SystemHostResolver` echoing a literal unchanged — true of the shipped one, and nowhere stated in the `HostResolver` contract, which promises only to resolve a hostname and whose class comment invites replacing it. Measured with a resolver that answers one public address for every host: ten literal forms went straight through, and the guard still looked like a guard. It now classifies a host that is already an address before resolution, so a caching resolver, one backed by an upstream API, or one written for a test cannot silently take the protection with it. **Nothing changes for a host that is a name**, which is still resolved and pinned exactly as before, and nothing changes in production behavior — the shipped resolver was already refusing these. What changes is where the obligation lives. The alternate *encodings* — `http://2130706433/`, `http://0x7f000001/`, `http://0177.0.0.1/` — are deliberately **not** covered here: to `filter_var` they are names rather than addresses, decoding them is genuinely resolution, and `SystemHostResolver` does it. The new `BypassVectorCatalogTest` keeps the two layers apart on purpose: under a resolver that answers one public address for every host the literals are refused and the encodings are not, which is exactly what "the guard decodes one and not the other" means — and it holds without a lookup, so the arm reports on the code rather than on whether the runner has DNS.

### Changed

- **Three packages the shipped code imports directly are now declared in `require`: `guzzlehttp/promises`, `nesbot/carbon` and `symfony/http-foundation`.** They arrived transitively through the illuminate split packages, so nothing about the resolved tree changes and no consumer installs anything new. What changes is who promises their version: an undeclared import is one Composer may resolve below what this code calls, and the failure then surfaces as a missing method in a class nobody here names. Each floor is the **base of its major** -- `^2.0 || ^3.0`, `^3.0`, `^7.0 || ^8.0` -- not the tighter constraint `laravel/framework` happens to carry today, because a floor decides who may install this package and a later minor would shut out an application the code runs on perfectly well. The classes actually imported are old enough for those bases: `Carbon\CarbonImmutable`, `CarbonInterface` and `CarbonInterval` predate Carbon 3, `GuzzleHttp\Promise\PromiseInterface` predates promises 2, and `Symfony\Component\HttpFoundation\Response` and `BinaryFileResponse` predate Symfony 7. The new `PackageDependencyContractTest` keeps the manifest and the imports answerable to each other from here on.

- **On the WireKit rendering the pagination control now comes from WireKit instead of from this package, and the enforced WireKit floor moves to 2.53.** The package drew its own pager because neither alternative worked: Livewire's built-in view paints a raw palette no design token reaches and names its landmark in hardcoded English, and WireKit's `pagination` could only page by *navigating* — every control was a real link, so a click reloaded the document and discarded any component state that was not in the URL, which on a filtered table meant page two came back unfiltered. WireKit 2.51 closed that and ships the two views Livewire's `paginationView()` and `paginationSimpleView()` point at, so keeping a second copy here would be this package re-implementing what it consumes. Nothing about how you page changes: the control still turns pages inside the component, still passes the paginator's own page name so several paginators on one screen do not collide, still marks the current page and still speaks the reader's language — WireKit's wording rather than ours, from its locale files. It also gains something the old control did not have, because WireKit renders each page as an anchor with a real address: the numbered pages are links you can open in a new tab. **The floor is 2.53 rather than 2.51**, and the two versions apart are the reason this is worth reading: until 2.53 WireKit's pager dimmed its inert boundary control with an `opacity` on top of an already-muted pair, and opacity applies to the element, so text and background composite against the page together and a 4.5:1 pair reads at about 1.6:1. This package had removed exactly that line from its own control after measuring it, so adopting at 2.51 would have handed the property back. **A host on another UI kit is unaffected**: `pushery/wirekit` is optional here and the neutral screens keep the package's own control, which is written against design tokens and needs no package to render. `webhooks::pagination` therefore still ships and still publishes with the views.

## [3.6.0] - 2026-09-16

### Added

- **The operator console draws icons on its row actions, out of the same `ui.row_action_icons` block the self-service endpoint list already reads.** Since 3.4.0 the tenant's list has carried a symbol on every row action while the operator console beside it carried none, and there was no setting for it: a host who wanted them there could only publish the view, which is the copy that config block exists to abolish. Two screens of one package disagreeing about whether a row action carries a symbol is a difference a reader notices and cannot explain. Four keys are new — `enable` and `disable`, shipped as `play` and `pause`, and `rotate_secret` with `rotate_secret_confirm`, both `refresh`; the console's remaining three actions read `edit`, `delete` and `delete_confirm`, the entries the other screen already uses. The toggle takes two keys rather than one because it is two actions wearing one position and its visible word switches with the row's state, so a single symbol would contradict the word on every second row. A config published before these keys existed has no entry for the rotation's confirmation, and there it follows `rotate_secret`, so both buttons of one rotation keep one symbol — the same rule `delete_confirm` follows. `null` still drops an icon, and an empty map draws the label-only row this screen shipped until now.

### Changed

- **`laravel/ai` is a `suggest` rather than a dev dependency.** It arrived in `require-dev` alongside `laravel/boost` and `laravel/mcp`, and unlike those two it is only ever called by an eval that scores through a real judge — this package has no evals at all. It also does not travel alone: it requires `aws/aws-sdk-php` for a provider nothing here calls, which is 67 MB and 3512 files in every checkout and every CI lane. Nothing a consumer installs, calls or configures changes either way.

### Fixed

- **In the operator subscription table a delivery URL could not wrap, so it set the width of its column and pushed the columns beside it out of the table.** A delivery URL is one unbroken token — a Slack hook runs about a hundred characters with no space, hyphen or other break opportunity — and the row header cell therefore grew to the width of the longest one. Reported against 3.5.1 in both engines: at 1280 pixels with a single endpoint, the status column was cut mid-word and all four row actions stood outside the table, reachable only by a sideways scroll nobody expects on a desktop. The URL now breaks anywhere, and the column carrying it has a width floor, because the two only work together: without the floor the column is free to collapse instead, and a real URL becomes a dozen short lines in a row three times its proper height. This is the answer the self-service endpoint list already uses for the same string. Phone widths are unchanged — that table is wider than a phone either way, and scrolling it sideways is correct there.
- **The four row actions in that table touched each other once the column was narrow.** They sat as inline children of the cell with no container, so the moment the column was squeezed each one broke onto its own line, right-aligned and flush against its neighbor — a staircase rather than a group. They are now a wrapping row carrying the kit's own gap spacing, the same shape the delivery log's filter row uses. Two browser arms hold both fixes, and the one over the buttons asks for a separation rather than for the absence of an overlap: touching is a gap of exactly zero, which a plain overlap check reads as correct.

## [3.5.2] - 2026-09-15

### Fixed

- **The button that confirms a deletion in the self-service endpoint list carries the delete icon.** Since 3.4.0 the row action that opens the delete dialog drew `ui.row_action_icons.delete`, while the button inside the dialog that actually deletes the endpoint, the one action on the list that cannot be taken back, drew only its label, with no setting for it. It now takes `ui.row_action_icons.delete_confirm`, shipped as `trash`. A config published before the key existed has no entry for it, and there the confirmation follows `delete`, so both buttons of one deletion keep one symbol; `null` drops the icon.

## [3.5.1] - 2026-09-15

### Fixed

- **The hourly activity chart did not draw its bars.** Its bar row aligns entries to the bottom with `items-end`, which sizes each entry to its content instead of stretching it to the row, and the entry had no height of its own — so every percentage inside it resolved to zero. Measured on a dashboard with a delivery in the window: a bar labeled "1 total, 1 delivered" rendered 709 pixels wide and 0 pixels tall, and every hour is drawn by the same markup. The accessible labels were right all along, which is why nothing noticed: the existing tests read the rendered HTML, not the size of what it draws. Each bar now fills the row's height, so its column is the share of the busiest hour it was always computed to be, and a browser test measures the rendered boxes.
- **A window with a single hourly value drew one bar across the whole panel.** Both dashboard charts give a bar `flex-1`, so with one value the latency trend painted a 42 ms reading as a dark 709 × 64 pixel block, and the activity chart would have done the same once its bars were visible. Bars are now capped at `--wh-chart-bar-max-width` and `--wh-sparkline-bar-max-width`, 2rem by default and overridable like the chart heights beside them. Windows with many hours are unchanged.
- **The self-service portal was the narrowest of its own family of screens while carrying the widest table, so two row actions were off the right edge.** The endpoint table needs 1015 pixels for its eight columns and five row actions; the page gave it 864. WireKit wraps a table in a scroll region, so nothing was unreachable and every existing arm was right to stay green -- the document never scrolled sideways and the actions were present, labeled and focusable. They were simply not on the screen: measured at a 1728-pixel viewport, `Transform` was cut mid-word and `Delete` and the `Actions` column header were outside the container entirely, with 432 pixels of empty margin on either side. A reader has no reason to go looking for a horizontal scrollbar inside a table on a wide display. The page now uses the dashboard's width, which is the other screen whose primary content is a wide table; the health board and the transform editor are separate full-page routes one step above where the portal used to sit, so the portal being the narrowest was the inconsistency rather than the rule. The next width up would not have been enough -- it leaves 992 pixels against the 1015 the table asks for. A browser arm holds the result as geometry, walking from each table up to its own scrolling ancestor and comparing what it needs against what it has, because a class assertion would keep passing on the day a new column pushes the content back over the edge. Scrolling at phone width is unchanged and correct: 1015 pixels fit in no phone.
- **The recent queue keeps Replay in view in a narrow panel.** On the overview the panel sits in the dashboard's side column, where its four columns needed 359 px against 331 on a desktop and 254 on a phone, so Replay slid past the table's edge. Below 24rem of panel width the Status and Code columns now step aside and their values stack under the event, which leaves the event and Replay side by side.
- **Closing the delivery drawer returns focus to the row that opened it in WebKit on macOS, the engine behind Safari, too.** That engine does not focus a button on click; it focuses the nearest focusable ancestor, here the deliveries table's scroll region, so the drawer remembered the region and gave focus back to it instead of to the row. The drawer now remembers the control that was pressed whenever focus sits on something that contains it.

## [3.5.0] - 2026-09-15

### Changed

- **The WireKit floor moves to 2.44, where a code block stops being a landmark unless the caller names it.** Until 2.44 every code block carried `role="region"` with an accessible name derived from its language, so the self-service transform editor -- which shows the sample payload and the transformed result side by side, both JSON -- rendered two landmarks both called "json code". Two landmarks of one name is `landmark-unique`, a WCAG-mapped failure that an automated audit of your own application reports against your page rather than ours, and nothing on the screen looks wrong while it is true: a duplicate region name is something a screen-reader rotor shows and a sighted reader never sees. `conflict` is now `<2.44` and the dev constraint `^2.44`; the four places that state the number moved together, the styling guide included. The accessibility check over that screen also gained a companion that reads the landmark names straight off the page, because a scan that finds nothing and a scan that cannot see are the same green line -- and this repository does not commit its lockfile, so the version a contributor happens to have installed is not the one the package promises.

## [3.4.0] - 2026-09-14

### Fixed

- **The endpoint list's loading skeleton reserves the height an empty list will take.** The list is lazy, so it arrives on a second request behind a skeleton of four rows -- a fixed height swapped for a variable one, and the two matched only by accident. Measured: 125 pixels of skeleton against the 210 the list takes when the account is empty, so everything below it jumped 85 pixels down on the first screen every new tenant sees. A consuming application that embeds the panel near the top of its own page measured a Cumulative Layout Shift of 0.148 against the 0.1 budget for "good", on that screen and on none of its other twenty-three. The skeleton now keeps a floor at the empty list's height, and a browser arm holds the floor against that height as it is really drawn rather than against a number written down twice. An account with rows will still move what follows it -- ten rows are taller than four skeleton ones whatever the skeleton says -- and the portal guide now says how to switch the deferral off where that matters, with the warning that `lazy="false"` passes a string and only `:lazy="false"` turns it off.

- **A closed self-service panel no longer sets a gap aside for itself.** The new-endpoint form and the signing-secret panel render their root whatever their state, because the live region that announces them has to be in the DOM before its text appears. A rendered root is a child of the portal's column, so each closed panel -- drawing nothing at all -- still collected a gap before it and a gap after it. Between the page heading and the endpoint list stood three gaps where one belongs: 36 pixels on the portal's own page, and 48 on an embedding host that stacks wider, against 27 on a screen carrying a single panel. A closed panel now forms no box, which removes the box and not the element: the live region keeps its place in the accessibility tree and still announces the form opening, and an opened panel pushes the list down exactly as before. Browser arms in Blink and WebKit hold all three. A view you published keeps the old markup until you publish it again.

- **On a phone, an endpoint row is the height of a row instead of the height of the screen.** The self-service endpoint list scrolls sideways inside its own container on a narrow screen, which is the right answer for a table this wide -- but the column carrying the name and URL was free to collapse under the auto table layout, and a real URL set in `break-all` has a minimum width of about one character. It was measured at 92 pixels, and the row it belonged to stood 359 pixels tall: taller than the screen, and almost all of it whitespace beside the health badge. The identifying column now keeps a floor of 16rem, on the header cell and the row cell both, because a column's width is a property of the column and one floor alone would not hold it. Measured over two endpoints with realistic names and URLs at 375 pixels: the row falls from 197 pixels to 77. A browser arm at phone size holds both halves in Blink and WebKit. A view you published keeps the old markup until you publish it again.

### Changed

- **Fourteen gaps between elements are drawn from the spacing scale instead of the padding scale.** A margin, a `gap` or a `space-*` utility fed from a `--padding-wk-*` token is the wrong family for the job: padding says how much room a control keeps inside its own box, and a host that retunes that scale was silently moving the distance BETWEEN boxes as well. Only the occurrences whose value exists on the `--space-wk-*` scale moved, so every one of the fourteen is pixel-identical to what it drew before. The reported fix was a plain rename on the premise that the two scales agree today -- measured against WireKit's stylesheet they do not, the padding scale runs 0.25 / 0.375 / 0.5 / 0.625 / 0.75 / 1 / 1.5rem against the space scale's 0.25 / 0.5 / 1 / 1.5 / 2.5 / 4rem, and renaming `--padding-wk-y-md` to `--space-wk-md` would have doubled that gap in all fourteen places. The 35 occurrences sitting at a step the space scale has no name for keep their padding token rather than take a resize nobody asked for; a guard derived from the stylesheet itself reports them the day that step exists. A view you published keeps the old markup until you publish it again.

### Added

- **The self-service panels draw their secondary actions with the surface you configure, and the endpoint list's row actions carry icons.** `ui.secondary_surface` moved the two operator screens and left the tenant-facing panels on a hardwired `ghost`, so a host with its own secondary style had to publish the view and carry the whole diff through every update -- for a style choice. It now covers both, unchanged at `ghost`, so nothing moves for a host that sets nothing. One button stays hardwired because it is not a secondary action: the active/inactive toggle in the endpoint list, whose `ghost` is the OFF half of a two-state control. The list's five row actions -- test, secret, edit, transform, delete -- also take an icon each, configurable per action under `ui.row_action_icons`; set one entry to `null` to drop that icon, or the whole key to `[]` for the label-only row this package shipped before. The shipped aliases are ones all four WireKit icon presets carry, and without `blade-ui-kit/blade-icons` installed WireKit draws its inert placeholder, exactly as the rest of the package's iconography already does.

## [3.3.2] - 2026-09-14

### Fixed

- **The neutral operator log's date filters are as tall as the controls beside them in WebKit's Linux build too.** 3.3.1 gave the row's selects and the free-text event type field one fixed height and left the two date fields at the height their engine draws them. That matched in Blink and in WebKit on macOS, but WebKit's Linux build draws a date field 2 pixels taller, so there the row stood at 38 pixels beside 40. Every filter control in the row, the date fields included, is now 40 pixels tall. A view you published keeps the old markup until you publish it again.

- **The self-service portal's headings go down one level at a time.** The new-endpoint form and the signing-secret panel open above the endpoint list, directly under the page's `h1`, and both titled themselves with an `h3`. So did the health board's empty state. A screen reader's list of headings went from the page title straight to a third level. All three are `h2` now, beside "Your endpoints" and "Recent deliveries". A view you published keeps the old markup until you publish it again.

- **The WireKit operator screens' empty states no longer skip a heading level.** The subscription manager and the delivery log bring no heading of their own, so the title of an empty state is the first heading under the host page's. WireKit renders that title as an `h3` unless told otherwise, which under a page's `h1` skipped a level. Both empty states are `h2` now. A view you published keeps the old markup until you publish it again.

## [3.3.1] - 2026-09-14

### Fixed

- **The delivery filters say which date starts the range, and lay out alike in every engine.** The two date filters on the portal's recent deliveries and on both operator delivery logs hid their labels, so two bare date fields sat side by side with nothing to say which was `From` and which `Until`. Both now carry visible labels. Each also keeps a width of its own: a date input without one takes the width its engine gives it, Chromium and WebKit disagreed by up to 53 pixels, and on a phone the portal's filters wrapped into three rows in one engine and two in the other. On a phone the portal now sets the two lists side by side and the two dates side by side below them. A view you published keeps the old markup until you publish it again.

- **On a phone, both operator delivery logs stack their filters into one column of equal width.** The filter row wrapped with the width each control brings, so at 375 pixels it broke into three rows of different lengths, with a right edge somewhere else on every line, in both renderings and both engines. Below the `sm` breakpoint every filter now takes the full width; from `sm` up the row keeps its own widths. A view you published keeps the old markup until you publish it again.

- **The neutral operator log's filter selects are as tall as the date fields beside them, in every engine.** WebKit draws a native select as a menu-list button and drops its vertical padding, so the three selects came out 23 pixels tall next to 38-pixel date fields, below the 24-pixel minimum for a target, while Blink drew them at 38. Every filter control in the row now has one fixed height, the one size WebKit honors on a select, and the selects keep their native arrow. The free-text event type field, shown when the catalog is empty, takes the same height. A view you published keeps the old markup until you publish it again.

## [3.3.0] - 2026-09-13

### Security

- **A form on the package's own pages could be sent as a GET before Livewire had bound it, with its fields in the address.** A `<form wire:submit>` carries no `method`, so until Livewire's scripts at the end of the body had loaded, Enter sent the page to its own URL and every named field landed in the query string: the endpoint form's name and URL, and from there the browser history, the access log and an error tracker's request URL. The window opened on every page load. Both package layouts, the self-service portal's and the dashboard's, now load a guard in the head that cancels the default submission of a `wire:submit` form; a bound form saves as before. The guard is a constant inline script, so a host under a strict Content-Security-Policy admits it once with `LivewireSubmitGuard::cspHash()` in `script-src`. A layout you published keeps the old head until you publish it again.

## [3.2.3] - 2026-09-11

### Security

- **A revoked tenant could still reload the endpoint list it had open.** `EndpointList` is lazy,
  and Livewire skips the hydrate of a lazy placeholder, `boot()` included, on the request that
  follows it. The portal's gate lived in `boot()`, so a refresh or a page change sent against a
  placeholder that had not loaded yet rendered the tenant's endpoints after `webhooks.manage` had
  been revoked. The same gate now runs before every render, which no placeholder skips.

  What was exposed is narrow: the tenant's own list, scoped to its owner, with no secrets on it.
  Every row action already carried its own authorization and was refused on that path.

### Fixed

- **The browser suite rendered every screen unstyled, so nothing it said about appearance was
  about your screen.** The package ships no compiled CSS on purpose — its views are Tailwind
  utilities over WireKit's design tokens, built by the host — and the demo host that stands in for
  that build linked its stylesheet from a provider the test case deliberately never registers,
  while the route serving the file lived in a route file only the demo reads. Two independent
  halves, both false, and the suite stayed green because text, clicks, Livewire round trips and
  JavaScript-error checks all pass on an unstyled page. Only geometry notices.

  Measured on 2026-09-10: the dashboard loaded WireKit's token sheet and nothing else, every
  element `display: inline` at `min-height: 0px`. Both halves now sit in the shared host provider,
  `composer test:browser` compiles the sheet before it runs, and a precondition arm fails the suite
  when the stylesheet stops arriving — proven against its own defect rather than assumed.

  Nothing here reaches an installed copy: the demo host and the test harness are development
  surfaces. What changes is that the suite's visual arms now measure the screen you get.

  **For contributors:** `composer test:browser` is now `composer demo-css && pest tests/Browser`,
  and `composer demo-css` is the single definition of that build — `just demo-css` calls it rather
  than repeating the Tailwind command. Running the browser suite needs no separate step any more,
  in CI or locally.

### Changed

- The dashboard's section tabs carry an explicit 24px minimum height (WCAG 2.5.8, Target Size).
  On a styled page they already measure 36px, so this fixes nothing visible today — it pins a
  floor that is otherwise the sum of a padding token and a line height, both of which a host
  re-themes.

## [3.2.2] - 2026-09-10

### Changed

- **The manifest now declares the four PHP extensions the shipped code calls directly** — `ext-ctype`, `ext-filter`, `ext-hash` and `ext-mbstring`. All four signing schemes reach for `hash_hmac()` and `hash_equals()`, the SSRF address classifiers for `filter_var()`, the owner-key type and the delivery log for `ctype_digit()`, and the owner-key declaration and the client config for `mb_strlen()`, `mb_strtolower()` and `mb_substr()`. None of the four was required.

  **Nothing changes for an install that already worked, and that is worth saying plainly rather than leaving you to check:** `laravel/framework ^13.0` requires all four itself (and openssl, session and tokenizer besides), so every PHP that could resolve this package already had them. What changes is where the requirement is written. A transitive guarantee is a property of someone else's manifest — nothing that reads THIS one can see it, `composer check-platform-reqs` included, and it can be narrowed upstream without a signal here.

  A contract test now holds both directions: every extension the shipped source calls is declared, and every declared extension is called. The second direction is the one that breaks a consumer — Composer refuses an install over a requirement nobody uses, and such a requirement passes a forward-only check forever.

### Fixed

- **The `tdigest` percentile driver could never have worked, and now does.** Its SQL merged the hourly latency digests with `rollup(latency_digest)` — and the PostgreSQL `tdigest` extension has no function by that name. It defines five aggregates (`tdigest`, `tdigest_avg`, `tdigest_percentile`, `tdigest_percentile_of`, `tdigest_sum`), and the one that merges digests is `tdigest(tdigest)`. Selecting `webhooks.dashboard.percentiles.driver = 'tdigest'` on a database that HAS the extension raised `function rollup(tdigest) does not exist` on the first dashboard read. The default driver (`live`) was never affected, and neither was any installation without the extension — there the driver stops at its own actionable guard before reaching SQL.

  **It shipped because it had never executed.** The extension is not part of the `postgres` image, so the end-to-end arm skipped in every lane and on every developer machine; the driver's query, bindings and result mapping were asserted against a stubbed connection, which pins what the driver ASKS for and cannot notice that the database has no such function. Installing the extension in CI is what made the arm run, and it failed on its first real execution.

## [3.2.1] - 2026-09-09

### Added

- A contract test holds the documented `Gate::define()` examples to the ability layer they belong to. The package defines `manage-webhook-endpoints` and `view-webhook-dashboard` itself, fail-closed, and each asks whether the HOST ability exists before allowing anything, so a consumer following an example that named a package-owned ability would replace that definition and lose the check entirely. The arm refuses that shape, and strips comments before reading the source, since one lane file spells out a host-side `Gate::define()` inside a docblock.

## [3.2.0] - 2026-09-08

### Added

- **A host can declare who may REPLAY from an endpoint they do not own.** `Pushery\Webhooks\Platform\Support\ReplayableEndpoints::resolveUsing()`, the sibling of the read seam beside it. Reading and replaying were one question with one answer — ownership — so an application that shares a destination into an organization could grant its members the history and had no way to grant an administrator the replay. The button rendered and then refused, which is the shape that teaches a reader the screen is unreliable.

  A second resolver rather than the first one consulted twice, because the two permissions genuinely differ: membership is enough to see what an organization's destinations received, while causing a fresh request to leave your installation under one of those destinations is what you reserve for an administrator. Declaring an endpoint readable still grants no right to replay from it.

  It can only **add**. The owner path is untouched, `manage-webhook-endpoints` still has to pass where you define it — this answers whose endpoint, never whether somebody may manage webhooks at all — and with nothing registered replay is exactly what it was. One invariant falls out of the order rather than being enforced twice: replay implies readable, because the action loads the delivery row through the read-scoped query before it looks at the endpoint.

## [3.1.0] - 2026-09-08

### Added

- **The operator delivery log says which endpoint each row went to.** That console is deliberately unscoped across every tenant, so rows for different endpoints stand under one another — and the endpoint appeared only inside the redeliver button's accessible name, present for a screen reader and absent for everybody else. The endpoint filter is optional, so "set one first" was never an answer. The subscription is eager-loaded with it: the accessible names were already resolving it lazily, one query per row, which cost nothing visible and so nobody counted it.
- **The response code carries its duration**, in the shape the portal panel already uses: `202 · 143 ms`. It hangs off the code rather than standing in its own column because a duration without an answer says nothing, and the pair is the question a reader actually has — it arrived, but how slow was it? A receiver getting slower is the run-up to one that fails.
- **The event-type filter is a choice wherever the application declares a catalog**, built from the same `Settings::eventTypes()` the self-service form already picks from. It stays free text where the catalog is empty, and that is the load-bearing case rather than a fallback: a host that declares none goes on registering any type it likes, so a select there would offer nothing while hiding the only control that works. Free text is compared with an exact `where`, so a typo returned an empty list indistinguishable from "nothing was delivered".
- **`AuthorizesOperatorActions::canAction()`**, the non-throwing twin of `authorizeAction()`, and both stubs now ask it before rendering a row action. It walks the same two config keys in the same order, so the markup and the action cannot disagree; with neither key set — the shape most hosts run — every control renders exactly as before. The markup is the courtesy, not the control: the action still authorizes and still refuses, which is what protects an operator whose capability was revoked while a page stayed open.
- **A replay is confirmed before it fires.** Pressing it sends a real HTTP request to a customer's endpoint under the delivery's original id, so a receiver that deduplicates treats it as one it has already seen — and the wrong row is one keystroke away in a list of twenty identical-looking actions. The WireKit stub confirms through an `alert-dialog`, the neutral one through `wire:confirm`, the same split the subscription manager already uses for rotate and delete. The ping is deliberately left unconfirmed: it sends nothing of the customer's and spends an allowance the component already refuses past, and confirming everything is how a confirmation stops being read.
- **The portal delivery panel can be bound to ONE endpoint, bindingly.** `<livewire:webhooks.self-service.endpoint-deliveries :subscription="$subscription" />`, and the pin is `#[Locked]` so it is the host's and not the reader's. Both scoping seams the panel had are static and answer for the whole request, which is the right shape for a portal cut per account and the wrong one for a surface cut per resource — there the only way to satisfy a request-wide resolver was to declare every endpoint the account may read anywhere and then lean on the endpoint filter, and `endpointId` is a public property, so a tampered update walked straight across everything the resolver had just declared readable. A filter is not an authorization. Narrowing the resolver just before rendering does not work either: a Livewire update request never runs the page build that would do it.

  It narrows and never grants. The pin is applied beside the owner scoping rather than in place of it, so pinning an endpoint the reader may not see yields an empty list rather than access to it. An endpoint **id** is accepted in place of the model, for a host that holds one without having loaded it. While it is set the endpoint filter is not offered — a select whose only usable option is the one already pinned is a control that cannot do anything — and the empty-state sentence speaks about that endpoint rather than about "your endpoints".
- **A refused operator action can answer 404 instead of 403.** `webhooks.ui.refusal_status`, default 403, so nothing changes unless a host asks. It exists for the host whose admin area is deliberately unfindable: there a 403 confirms that something is at that address and only the permission is missing, while a 404 says nothing at all — and such a host wants every surface answering alike rather than one imported console announcing itself. Only a client- or server-error status is honored; anything else leaves the refusal untouched, because a refusal that answered 200 would read as success to every caller. At the default the original `AuthorizationException` is rethrown rather than rebuilt, so the type a host already catches is the type it keeps. `refusalStatus()` is overridable for a rule a status cannot express.

Both stubs default `eventTypes`, `canRedeliver` and `canPing` when they are absent, to the behavior that shipped before this change. The package's own suite renders these views directly through `View::make()` from ten call sites across four files, and requiring the keys there would turn each into a place to remember rather than a place to read. A test asserts the component really does pass all three, so a `render()` that stopped would still go red.


### Changed

- **The operator console's endpoint filter is `$endpointId`; `$subscriptionId` still works.** It held an endpoint's id while calling it a subscription, and the two words are not interchangeable to somebody reading from outside: the portal panel beside it already called the same thing `endpointId`, every option the filter renders is described as one customer endpoint, and its own docblock said endpoint. A consumer evaluating adoption lined the property names up — the cheapest comparison and therefore the usual one — read a name that named something else, and recorded the endpoint filter as absent. The capability was there the whole time and got built a second time anyway, which is the expensive kind of naming defect: it disguises itself as a missing feature.

  **This is not a breaking change.** Both stubs are meant to be published and edited — that is this package's main customization path — and a published copy binds this name in its own markup, so dropping it would have broken the filter in exactly the hosts that took the package up on its advice. The two names are kept in step in both directions, so a host on either sees no difference; bind the new one in anything you write from here.

### Fixed

- **A date range passed into the operator delivery log survives mounting.** The default window was applied unconditionally, so a link carrying `from` lost it on arrival: the reader opened on the last thirty days and never saw what somebody had sent them, with nothing on screen to suggest a range had been discarded. The default itself is right and stays exactly as it was — including `0` for a host that wants no default at all — it simply now applies when nobody said otherwise. Measured by a consumer part-way through replacing its own copy of this console, with twenty of its twenty-four existing arms already green.
- **Every boolean switch in the shipped config now reads `off`, `no` and `OFF` as off.** `env()` converts exactly the spellings `true` and `false`; anything else comes back as a string, and a non-empty string is truthy. All seventeen switches carried a `(bool)` cast, which does not convert anything — it confirms what `env()` already returned, so `WEBHOOKS_DASHBOARD_ALL_TENANTS=off` turned the switch on. Fourteen of the seventeen default to `false`, which is the direction that hurts: the operator got the opposite of what they wrote, with nothing red anywhere to say so. The cast was worse than none at all, because a reader checking the line saw a conversion and moved on.

  Each one is now `filter_var(env(KEY, DEFAULT), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? DEFAULT`, which is the expression the rest of the fleet already uses. A value nothing can parse — a typo — falls back to the **declared default** rather than to off, because the switch whose default is protective is `WEBHOOKS_HTTPS_ONLY`, and answering "unreadable" with "off" there would open plaintext egress on a misspelling.

  A host that publishes the config carries its own copy, so republish it or apply the same change to pick this up. `KEY=` with no value reads as off, unchanged from the other configs in the fleet.

## [3.0.1] - 2026-09-06

### Fixed

- **The 3.0.0 entry is formatted the way a release body needs, so it reads as written.** A published release body renders a single newline as a LINE BREAK, unlike the same newline in a Markdown file — so the hard-wrapped 3.0.0 entry came out as a narrow column of stubs beside the paragraphs nobody wrapped. It is one paragraph per line now, and the paragraphs that continue a bullet are indented so they stay part of it rather than closing the list. No wording changed; the reflow verifies the word sequence and refuses if it did.

## [3.0.0] - 2026-09-05

### Added

- **A host can declare which endpoints a reader may SEE.** The self-service delivery panel scopes by the denormalized owner pair, which answers *does this row belong to this owner?* An application that shares an endpoint has a second question — *may this person see this row?* — and the two part company at exactly that point: a member of an organization a destination is shared into is not the owner, so under owner scoping alone they see nothing.

  `Pushery\Webhooks\Platform\Support\ReadableEndpoints::resolveUsing()` takes a closure returning the endpoint ids that reader may read beyond the ones they own. A set of ids rather than a predicate, on purpose: a closure handed the query could drop the owner scoping, and then the panel's central promise would depend on host code. The resolver can only **add**.

  It reaches the delivery list, the endpoint filter and the filter's option list. It does **not** reach the replay, which still loads its endpoint through the owner-scoped lookup — so a reader who may see a shared endpoint's history cannot send from it. With no resolver registered nothing changes anywhere.

- **The delivery list shows how long each delivery took**, beside the response code it took that long to get. The package already measured it on every attempt. Bound to the code rather than standing alone: a failure that never got an answer also has a duration — the time spent failing to get one — and printing that beside an em dash would read as latency.

### Changed

- **BREAKING — `webhooks:import-spatie-calls` is now `webhooks:import-calls`, and it reads the shape you declare instead of one it assumed.** Five new options name the source columns (`--from-id`, `--from-source`, `--from-payload`, `--from-headers`, `--from-error`), and their defaults are exactly the columns the old command hardcoded — so an existing invocation needs only the new name. Any prior inbound-webhook table can now be imported, not just one shape.
- **BREAKING — the derived import key changed, so ids from an earlier import no longer re-derive.** Running the command over a source table you already imported would import all of it a second time; the old rows are indistinguishable from ones this package received itself, so nothing can detect it for you. `--dry-run` shows it before any write: over a backlog you already imported it must report everything as already present.
- The operator console's permission guidance names the mechanism rather than one package: the trap is any permission package that resolves through its own `Gate::before` hook and reads the first positional gate argument as a guard name. `admin.abilities` remains the way past it, and nothing about its behavior changed.

### Removed

- **BREAKING — the `exception` column is dropped from `webhook_calls`.** Nothing in this package ever wrote it: not the receive path, not the backlog import, not a listener. It was declared when the table was created, outlived the rewrite it came in with, and stood null on every row while reading like a feature — the documentation promised the import filled it, and the import never did. A migration drops it from existing installations; if you adopted the column for your own bookkeeping, copy the values out before migrating. The status it decided is unchanged and still imported.

  The shipped `WebhookCall` factory follows: its `failed()` state now sets the status alone, which is the whole of what that state means here — this log records *that* a call failed, not what was thrown. If your own tests asserted on the value that state used to write, that assertion goes.

### Fixed

- **A replay refused by the engine is answered with a sentence instead of a 500.** The panel checks whether the endpoint is active on the row it loaded; the manager re-reads the subscription off the delivery and checks again. Between the two the endpoint can be switched off — by the circuit breaker on a concurrent failure, or by the tenant in another tab — and the refusal then arrived over an ordinary button press. The window is small and not hypothetical: the breaker disables an endpoint precisely while its deliveries are failing, which is when somebody is looking at that list and pressing Send again.

- The localization guide now lists all six shipped translation files. `formats` — the thousands and decimal separators every screen counts with — was missing from its table, so a host whose house style disagrees with ICU's had no documented place to change them. That file's own comment pointed at the wrong place for date patterns as well: those sit under each surface's own `formats` key, not beside the separators.

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
  the query and fails not-found before any action runs — defense in depth, so a single policy
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

[Unreleased]: https://github.com/pushery/webhooks-for-laravel/compare/v3.16.1...HEAD
[3.16.1]: https://github.com/pushery/webhooks-for-laravel/compare/v3.16.0...v3.16.1
[3.16.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.15.0...v3.16.0
[3.15.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.14.0...v3.15.0
[3.14.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.13.0...v3.14.0
[3.13.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.12.0...v3.13.0
[3.12.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.11.0...v3.12.0
[3.11.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.10.1...v3.11.0
[3.10.1]: https://github.com/pushery/webhooks-for-laravel/compare/v3.10.0...v3.10.1
[3.10.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.9.0...v3.10.0
[3.9.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.8.0...v3.9.0
[3.8.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.7.0...v3.8.0
[3.7.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.6.0...v3.7.0
[3.6.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.5.2...v3.6.0
[3.5.2]: https://github.com/pushery/webhooks-for-laravel/compare/v3.5.1...v3.5.2
[3.5.1]: https://github.com/pushery/webhooks-for-laravel/compare/v3.5.0...v3.5.1
[3.5.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.4.0...v3.5.0
[3.4.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.3.2...v3.4.0
[3.3.2]: https://github.com/pushery/webhooks-for-laravel/compare/v3.3.1...v3.3.2
[3.3.1]: https://github.com/pushery/webhooks-for-laravel/compare/v3.3.0...v3.3.1
[3.3.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.2.3...v3.3.0
[3.2.3]: https://github.com/pushery/webhooks-for-laravel/compare/v3.2.2...v3.2.3
[3.2.2]: https://github.com/pushery/webhooks-for-laravel/compare/v3.2.1...v3.2.2
[3.2.1]: https://github.com/pushery/webhooks-for-laravel/compare/v3.2.0...v3.2.1
[3.2.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.1.0...v3.2.0
[3.1.0]: https://github.com/pushery/webhooks-for-laravel/compare/v3.0.1...v3.1.0
[3.0.1]: https://github.com/pushery/webhooks-for-laravel/compare/v3.0.0...v3.0.1
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
