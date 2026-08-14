# 0002 — Payments Domain event-sourcing allowlist

- **Status:** accepted
- **Date:** 2026-08-14
- **Decision owner:** AB
- **Implemented by:** T5 (production Payments domain, event schema, persistence schema)

## Context

[ADR 0001](0001-custom-ddd-module-layout.md) gives every module a `Domain` layer that "may reference
nothing outside its own namespace", and closes by reserving one question: the Payments exception for
event-sourcing base types is *not* granted there, and "the task that introduces those base types
records the exact permitted classes and narrows the rule to that allowlist." T5 is that task.

Payments is event-sourced on `spatie/laravel-event-sourcing` 7.15.1. Its aggregate cannot exist
without extending `AggregateRoot`: the uuid is a private property on that class, version stamping
happens inside `recordThat()`, and handler discovery reads the subclass's own methods. Its event
cannot exist without extending `ShouldBeStored`: the package stores only that type, and the
serializer, the `(aggregate_uuid, aggregate_version)` constraint and the handler-method matching all
key off it.

Merchant has no such need. Its `Domain` is deliberately empty and stays under the unmodified rule.

So the choice is not "allow the library or not" — it is how *narrowly* the exception can be drawn,
and whether the narrowing is enforced or merely described.

## Decision

`Modules\Payments\Domain` may reference exactly these two **external namespaced package classes** in
addition to its own namespace:

| Class | Kind | Why it is unavoidable |
|---|---|---|
| `Spatie\EventSourcing\AggregateRoots\AggregateRoot` | abstract class | uuid binding, version stamping, handler discovery |
| `Spatie\EventSourcing\StoredEvents\ShouldBeStored` | abstract class | the only type the package persists |

**Scope of the exception.** It covers namespaced classes from an external package, and nothing else.
Native single-segment PHP types — `DateTimeImmutable`, `InvalidArgumentException`, `DomainException`
and the rest of the SPL — are **not** part of this exception and never were. They are already outside
the rule by [ADR 0001](0001-custom-ddd-module-layout.md)'s documented scanner semantics: the token
scan skips any name without a backslash, because a single-segment name is global and treating
`\RuntimeException` differently from `use RuntimeException;` would make the rule measure spelling.
That behaviour is unchanged here, and this ADR introduces no second allowlist for native types. A
domain class extending `InvalidArgumentException` needs no entry below and must not be given one.

Three properties make this narrow rather than nominal:

1. **Exact matches, never prefixes.** A `Spatie\EventSourcing\` prefix would admit `Projector`,
   `StoredEvent`, `EloquentStoredEvent` and every other type in the package. The rule compares
   fully-qualified names for equality.
2. **Payments only.** The allowlist is keyed by module. `Merchant` has no entry, so the same
   reference from Merchant's `Domain` is a violation.
3. **Enforced, not documented.** `LEDGERFLOW_DOMAIN_ALLOWLIST` in
   `tests/Architecture/ModuleBoundaryTest.php` is the enforcement; this document is the record. The
   rule is expressed as a pure function over a reference map so its *failure* path is tested against
   fabricated input, not merely its success path against a tree that happens to comply.

### Named and excluded

- **`Spatie\EventSourcing\EventHandlers\Projectors\Projector`.** A projector writes a read model. It
  is a persistence adapter driven by the event stream, so it belongs in `Infrastructure`, where the
  unmodified boundary rules already permit it without any exception. Widening `Domain` to include it
  would grant the domain layer access to a type it must never reference. This is the type a careless
  prefix-based allowlist would admit, so the architecture tests assert its rejection explicitly.
- **`Spatie\EventSourcing\Attributes\EventVersion`.** `ShouldBeStored::eventVersion()` returns `1`
  when no attribute is present, and `PaymentInitiated` overrides that method instead. The attribute
  would be a third `Domain` type for no behavioural gain. Spatie's `ObjectNormalizer` filters
  `eventVersion` out of the serialized payload unless the child declares a *property* of that name,
  so an overridden method changes the durable key set not at all.

## Alternatives considered

**A `Spatie\EventSourcing\` namespace prefix.** One line shorter and admits the entire package,
including the projector and the Eloquent persistence models. Rejected: it would make the rule's name
("framework-free Domain") false while leaving it green, which is worse than having no rule.

**A hand-written, framework-independent aggregate and event abstraction, with adapters in
`Infrastructure`.** This is the textbook answer and it does keep `Domain` literally dependency-free.
Rejected: it is a second event-sourcing implementation whose only benefit is a purity the allowlist
already buys. It would have to reproduce version stamping, handler discovery, the serializer contract
and the aggregate-version concurrency behaviour — every one of which T3 measured against the real
library — and each reproduction is a place where the adapter and the library can disagree silently.
The option stays open: the two permitted classes are the entire coupling surface, so replacing them
later means changing two `extends` clauses and this ADR.

**Moving `PaymentAggregate` into `Application`.** Sidesteps the rule instead of deciding it, and
makes the layer convention meaningless for the one module that has real domain invariants. Rejected.

## Consequences

- The `Domain` guarantee is now precisely "no external package classes, except two named ones, in one
  module". That is a weaker sentence than before and it is written down rather than implied. Native
  PHP types are unaffected in either direction.
- Any further vendor type in Payments `Domain` requires a new ADR. There is no discretionary space:
  the rule fails on anything not spelled out in the constant.
- The allowlist and this document can drift, and nothing mechanical prevents it. The constant's
  docblock names this file, and this file names the constant; keeping them in agreement is a review
  responsibility. An earlier draft enforced agreement by parsing this document from a test, which was
  cut as a brittle test of prose.
- PHPStan level 10 now analyses domain code for the first time.
  [Development environment](../specifications/foundation/development-environment.md) previously
  recorded that level 10 "remains untested against domain code proper"; that is no longer true.
- The exception is granted where the cost of not having it is a second event-sourcing
  implementation, and nowhere else. `Merchant` gains nothing and is unaffected.

## Related

- [ADR 0001](0001-custom-ddd-module-layout.md) — the module layout that reserved this decision
- [Initiation and idempotency](../specifications/payments/initiation-and-idempotency.md)
- [Development environment](../specifications/foundation/development-environment.md) — the three
  Spatie base types read from source during T3, and which layer each belongs in
- `tests/Architecture/ModuleBoundaryTest.php` — `LEDGERFLOW_DOMAIN_ALLOWLIST` and its mutation tests
