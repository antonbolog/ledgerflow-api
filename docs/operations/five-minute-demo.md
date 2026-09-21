# Five-Minute Resilience Demo

A reproducible local walkthrough of the Payments slice: a healthy payment, idempotency, a real Redis
outage and recovery, deliberate read-model corruption, read-only detection, an explicit rebuild, and
a final healthy verification.

Everything here runs against the local Docker Compose stack through project-defined commands.
Nothing in this guide requires AWS, Terraform, deployment credentials, or the merchant dashboard;
those belong to `ledgerflow-infra` and `ledgerflow-spa`.

The exact wire contract this guide exercises is published as
[`docs/api/openapi.json`](../api/openapi.json), whose parity with the implementation is asserted by
`tests/Feature/OpenApiContractTest.php` under `make test`.

## Before you start

Prerequisites are Docker with Compose v2, GNU Make, and Git.
No host PHP or Composer is used, and no step below asks you to install one.

Setup is deliberately outside the timed sequence, because a cold checkout spends most of its time
building images and installing Composer dependencies:

```sh
make install          # build, install dependencies, start services, migrate
make db-seed          # create the two fixture merchant identities
make projection-start # start the scheduler and Horizon consumer together
```

`make install` and `make start` leave the scheduler and Horizon **down** by design.
If you skip `make projection-start`, payments commit correctly but never project, and
`GET /api/payments/{paymentId}` answers `202 projection_pending` forever.
That is a missing process, not a defect: see
[application process topology](runtime-contract.md#application-process-topology).

Then take a bearer token and keep it in a shell variable:

```sh
API=http://localhost:8080
TOKEN=$(make demo-token)
```

`make demo-token` prints the token and nothing else on stdout, so the capture above works.
It issues a one-hour Passport personal access token for a seeded fixture identity through the running `app`
container.
`EMAIL=` selects the identity and accepts only `owner@northwind.test` (the default) and
`owner@atlantic.test`, the two merchants `make db-seed` creates.
The demo drives the resource API with that token directly. The merchant login page and the
authorization code flow are exercised by `ledgerflow-spa`, not by this walkthrough; locally they need
`make demo-password EMAIL=...` for a known fixture password and `make demo-bff-client` for the OAuth
client.

Keep the token in the variable.
Do not paste it into a file, a commit, or an issue: it authenticates as a merchant for as long as it
exists.

## The sequence

### 1. Initiate a payment

```sh
curl -i -X POST "$API/api/payments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: demo-0001" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"amount_minor":1250,"currency":"USD"}'
```

Expect `202 Accepted`, `Content-Type: application/json`, a `Location` header, and:

```json
{"payment_id":"01a07d3e-1546-70db-90d6-a00fdf020d70","status":"initiated"}
```

The idempotency receipt, the stored event, and the outbox envelope committed in one PostgreSQL
transaction, or none of them did.
Keep the identifier:

```sh
PAYMENT_ID=01a07d3e-1546-70db-90d6-a00fdf020d70
```

The raw `Idempotency-Key` is hashed on the way in.
It never reaches an event, an envelope, a receipt, or a log line, which is why nothing below asks you
to redact output.

### 2. Watch the read model catch up

```sh
curl -i "$API/api/payments/$PAYMENT_ID" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

The first read may answer either way, and both are correct:

- `202 Accepted` with `Retry-After: 1` and `{"payment_id":"...","status":"projection_pending"}`
  while the projection lags;
- `200 OK` once the Horizon consumer has written the read row.

Repeat until you see `200`, whose body is exactly:

```json
{"payment_id":"...","amount_minor":1250,"currency":"USD","status":"initiated","initiated_at":"2026-09-07T19:00:15.816120+00:00"}
```

Seeing `200` on the very first read is not a failure of the demo.
The point is that the write path never waits for the projection.

### 3. Prove idempotency

Replay the identical request with the same key:

```sh
curl -i -X POST "$API/api/payments" \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: demo-0001" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"amount_minor":1250,"currency":"USD"}'
```

Expect the same `202`, the same `payment_id`, the same `Location`, and byte-identical body: the
response is replayed from the stored receipt rather than recomputed.

Now reuse the key with a different payload:

```sh
curl -i -X POST "$API/api/payments" \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: demo-0001" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"amount_minor":9900,"currency":"USD"}'
```

Expect `409` and an RFC 9457 problem document with `"code": "idempotency_payload_mismatch"` and the
media type `application/problem+json`.
Nothing new is written.

### 4. Take Redis away

```sh
docker compose stop redis
```

Initiate another payment with a fresh key:

```sh
curl -i -X POST "$API/api/payments" \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: demo-0002" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"amount_minor":4200,"currency":"USD"}'
```

It still returns `202`.
The write path is PostgreSQL only, so the transport being down cannot stop a payment from being
accepted.
Reading it answers `202 projection_pending`, because the relay cannot publish.

```sh
make demo-status
```

`payments:outbox:status` exits non-zero and reports the backlog honestly:

```json
{"healthy":false,"heartbeat":{"state":"unavailable",...},"database":{"state":"ok","eligible_count":1,"oldest_eligible_age_seconds":10,"active_count":1,"gate":"open"}}
```

A transport outage is not a delivery failure.
It consumes no projection failure budget and exhausts no envelope, which you can confirm directly:

```sh
docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -c \
  'SELECT projection_failure_count, exhausted_at FROM payment_outbox ORDER BY created_at DESC LIMIT 3;'
```

Every row still shows `0` and a null `exhausted_at`.

### 5. Give Redis back

```sh
docker compose start redis
docker compose restart scheduler
```

The restart is an accelerator, not a repair.
`php artisan schedule:work` plans sub-minute events one minute at a time, and the Redis outage kills
the plan it is currently running, so an untouched scheduler resumes on its own at the next minute
boundary. Measured here, that took just over two minutes; restarting it makes recovery immediate.
Horizon needs nothing: its supervisor reconnects by itself.

Read the payment from step 4 again until it answers `200`.
No manual redelivery step exists, because outbox reconciliation owns republication.

### 6. Break the read model on purpose

```sh
make demo-corrupt PAYMENT_ID="$PAYMENT_ID" CONFIRM=corrupt-projection
```

This deletes exactly the one `payment_details` row you name.
It touches no canonical history: `stored_events`, `payment_outbox`, the idempotency receipts, and the
projector receipts are all untouched, which is what makes step 8 a genuine reconstruction rather than
a restore.

The target refuses, without deleting anything, if you omit `PAYMENT_ID`, pass something that is not a
canonical UUID, omit the typed `CONFIRM`, or name a payment that does not have exactly one projection
row. It only ever touches the allowlisted local `ledgerflow` database.

It is local development tooling.
Neither `make check` nor `make ci` invokes it or exercises its shell refusals.

Read the payment again: it answers `202 projection_pending`, because the idempotency receipt outlived
the deleted read row.

### 7. Detect the corruption, read-only

```sh
docker compose exec -T app php artisan payments:projections:verify
```

Exit status `1`, and a JSON report naming the finding. The counts below are for the two payments this
guide creates, in steps 1 and 4; the record carries more fields than are shown here:

```json
{"outcome":"inconsistent","reason":"audit_complete","complete":true,"projection_control_state":"ready",
 "high_watermark":2,"scanned":{"canonical_events":2,"projector_receipts":2,"payment_details":1,"payment_outbox":2},
 "finding_counts":{"projector_receipt_without_read_row":1},"...":"..."}
```

Verification mutates nothing.
It may run while delivery is active, it stops no workers, and it never repairs what it finds.
Exit codes and their meanings are in
[manual verification and targeted repair](runtime-contract.md#manual-verification-and-targeted-repair).

### 8. Rebuild, explicitly

```sh
docker compose exec -T app php artisan payments:projections:rebuild --confirm-rebuild
```

```json
{"outcome":"completed","reason":"rebuild_complete","high_watermark":2,"events_replayed":2,"batches_committed":1,"guarded_recovery":false,"...":"..."}
```

The confirmation flag is required and this guide deliberately provides no target that supplies it for
you.
Rebuilding is an availability interruption: while the projection gate is closed, `POST` and `GET`
answer `503 projection_rebuilding` with `Retry-After: 1`, and the relay and consumer defer without
consuming any delivery or failure budget.

**You will almost certainly not observe that `503` by hand here.** On this dataset the rebuild
committed in 36 ms during the recorded demo.
`ProjectionRebuildTest` covers the gate with database barriers and failure injection in the current Pest suite.
The real-process kill rehearsal is [historical evidence](../testing/payments-resilience.md#process-level-resilience), retired from CI by ANT-18.

### 9. Verify healthy

```sh
docker compose exec -T app php artisan payments:projections:verify
curl -i "$API/api/payments/$PAYMENT_ID" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Exit status `0`, `"outcome":"consistent"`, every event classified consistent, and the payment reads
`200` again with the same body it had before the corruption.

### 10. Stop

```sh
make projection-stop
make stop
```

`make stop` keeps all data. Use `make destroy CONFIRM=destroy` only when you want the volumes gone.

## Measured run

On 2026-09-07, on an Apple M3 with 8 cores, macOS 26.6.2, Docker 29.7.2 and Compose 5.5.1, a scripted
execution of steps 1 through 9 against an already-running stack completed in **51 seconds**, of which
46 seconds was step 5 waiting for the relay to republish after the Redis restart. A clean-checkout
rehearsal of the same steps converged in 18 seconds at that step, so treat the recovery wait as the
one variable part: it depends on where the outage falls relative to the scheduler's minute boundary.

That is one observation on one machine, and it is not an SLA, a throughput figure, or a claim about
any other hardware. It also excludes setup, which is dominated by the image build and Composer
install on a cold checkout. Its purpose is only to show that the timed sequence has a wide margin
under five minutes when a person is pasting the commands rather than a script running them.

## Appendix: cross-merchant isolation

Outside the timed sequence, because it demonstrates a security property rather than a resilience one.

Take a token for the second seeded merchant and ask for the first merchant's payment:

```sh
OTHER=$(make demo-token EMAIL=owner@atlantic.test)
curl -i "$API/api/payments/$PAYMENT_ID" -H "Authorization: Bearer $OTHER" -H 'Accept: application/json'
curl -i "$API/api/payments/01a07d3e-0000-7000-8000-000000000000" -H "Authorization: Bearer $OTHER" -H 'Accept: application/json'
```

Both answer `404` with `"code": "payment_not_found"`, and the two documents differ only in the
`instance` path each echoes.
A merchant cannot distinguish "this payment belongs to someone else" from "this payment does not
exist".
A malformed identifier gets the same answer for the same reason.

## Not covered here

- `payments:outbox:repair` is deliberately absent. It requires stopping delivery, independently
  confirming every worker has drained, and passing `--delivery-quiesced` as an explicit operator
  assertion, which cannot be honestly compressed into a timed demo. Follow
  [manual verification and targeted repair](runtime-contract.md#manual-verification-and-targeted-repair)
  instead.
- This guide remains a manual demonstration.
  Current CI runs Pest evidence for the component contracts and does not execute the process outage or signal scenarios.
  Earlier process rehearsal results are recorded as historical evidence in the resilience test plan.

## Related

- [Runtime contract](runtime-contract.md) - process topology, evidence, and operator procedures.
- [OpenAPI artifact](../api/openapi.json) - the exact `POST` and `GET` contracts.
- [Payments flow](../architecture/payments-flow.md) - the architectural map.
- [Resilience test plan](../testing/payments-resilience.md) - what is proved, and its limits.
