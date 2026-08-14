# 0001 — Custom DDD module layout

- **Status:** accepted
- **Date:** 2026-08-10
- **Decision owner:** AB
- **Implemented by:** T2 (module boundaries and merchant-user identity)

## Context

Ledgerflow has two bounded contexts with materially different persistence needs. Payments is
event-sourced, with protected schema, idempotency and replay semantics. Merchant is ordinary Eloquent
CRUD. They must not share a persistence model, and Payments' domain must stay independent of the
framework so its invariants can be reasoned about without booting Laravel.

Laravel's default layout offers no unit of ownership between "the application" and "a class". Some
grouping mechanism had to exist before any domain code was written, because retrofitting boundaries
onto an established `app/` tree means moving every file and rewriting every import — and the Payments
slice is where that cost would land hardest.

Three properties were needed:

1. A physical unit that owns a bounded context's code, migrations, providers and tests.
2. A layer convention inside that unit that puts framework-free domain code somewhere a rule can name.
3. Enforcement, so a boundary is a test failure rather than a review comment.

## Decision

Modules are top-level directories, `Modules/<Context>`, registered and discovered by a module
management package and autoloaded through per-module Composer PSR-4 entries merged into the root
manifest.

Inside each module, application code is organised as:

| Layer | Contents |
|---|---|
| `app/Domain` | Framework-free domain types. May reference nothing outside its own namespace. |
| `app/Application` | Use cases and the contracts consumers depend on. May reference its own `Domain` and `Application`, plus any other module's `Application`. |
| `app/Infrastructure` | Eloquent models, adapters, and the implementations of Application contracts. |
| `app/Http` | Controllers, requests, middleware, resources. |

`app/Providers` sits beside those four. It is framework wiring the module manager discovers, not a
layer, and it is the only other directory permitted directly under `app/`.

Each module carries only what discovery, providers, migrations, tests and Composer autoloading
require. Views, assets, frontend files, module configuration, language files and example routes are
removed at generation time and their generators are switched off in `config/modules.php`, so a later
`module:make` cannot silently recreate them.

Cross-module access goes through `Application` and no deeper. Merchant additionally knows nothing of
Payments at all: the event-sourced context may ask who owns a payment, but the merchant context has
no business knowing payments exist.

Layers are created because a context needs them, not to complete a template. A module with no domain
invariant beyond the database's own keeps an empty `Domain` and holds its Eloquent models in
`Infrastructure`. Entities, identifier value objects and repository interfaces arrive with the first
use case that requires them.

## Alternatives considered

**A single `app/` tree with namespace conventions.** No physical ownership, no migration or provider
locality, and boundary rules must enumerate namespaces that any new directory silently escapes.
Rejected: the convention is invisible at the filesystem level, which is where contributors actually
look.

**One Composer package per context, in separate repositories.** Genuine enforcement, at the cost of
cross-repository versioning and release coordination for a system that deploys as one artifact.
Rejected as premature for a single deployable. The option stays open, because a module directory that
already has its own `composer.json` can be extracted later.

**A third-party opinionated DDD scaffolding package.** Would supply the layer convention directly.
Rejected on the general principle that a layout is a decision this project must own and be able to
change: an opinionated generator's conventions become load-bearing precisely where the project's own
needs diverge, and its maturity is a risk the project cannot control. The convention is cheap to
reproduce; the coupling is not. This applies to the category, not to any particular package.

**Module management without a layer convention.** Modules alone give ownership but leave each context
free to organise internally, which is exactly the drift the Payments/Merchant split must avoid.

## Consequences

- Boundary violations fail `make check`. `tests/Architecture/ModuleBoundaryTest.php` enforces the
  layer allowlists, the cross-module rule, the permitted directory set, and the absence of the
  removed boilerplate. `tests/Architecture/BaselineTest.php` keeps the codebase-wide invariants.
- The rules are derived from disk: modules and module pairs are discovered by scanning `Modules/`, so
  a third module is governed the moment it exists rather than when someone remembers to register it.
- Each new module costs an autoload entry, a `module.json` provider entry, and a provider class. It
  costs nothing in the test binding, which globs `../Modules/*/tests/Feature`.
- **The enforcement has real limits, and they are named rather than papered over.** The scanner reads
  PHP tokens, so it sees `use` statements and inline fully-qualified names, and correctly ignores a
  file's own `namespace` declaration and comments. It does **not** see a dynamic class string such as
  `app('Some\\Class')`, a class name assembled at runtime, or a helper invoked through a variable.
  The "framework-free Domain" guarantee is therefore two rules, not one: an allowlist over namespaced
  references, plus a **named denylist** of Laravel global helpers (`now()`, `config()`, `app()` and
  the rest), because those are ordinary function calls that emit no name token at all. A helper
  outside that list would pass. The scan catches what a developer writes by habit, not what someone
  writes to evade it.
- Static analysis, refactoring and the test suite must each be told about `Modules/`; a tool that is
  not silently stops enforcing anything there. PHPStan's exclusion in particular needs a trailing
  `/*`, because the pattern is matched against file paths rather than treated as a directory.
- PHPStan level 10 is now exercised against real Eloquent code and holds with no baseline. The cost
  is `@property` annotations on models: attribute access and assignment are typed from them, and an
  incomplete list fails analysis rather than silently passing, which is the safe direction.
- The Payments exception for event-sourcing base types was **not** granted here, deliberately: the
  permitted classes could not be named honestly before a task needed them in production code.
  [ADR 0002](0002-payments-domain-event-sourcing-allowlist.md) grants it, and narrows it to exactly
  `Spatie\EventSourcing\AggregateRoots\AggregateRoot` and
  `Spatie\EventSourcing\StoredEvents\ShouldBeStored`, matched as exact class names and **only for the
  Payments module**. `Projector` is named and excluded. Merchant's `Domain` remains subject to the
  unmodified rule above.
- Empty `Domain` directories are a deliberate state, not an unfinished one. A reviewer expecting a
  populated domain layer in every module should read this section rather than file it as a gap.

## Related

- [System overview](../architecture/system-overview.md)
- [Development environment](../specifications/foundation/development-environment.md)
- `tests/Architecture/ModuleBoundaryTest.php`, `tests/Architecture/BaselineTest.php`
- `config/modules.php` — generator policy that keeps removed boilerplate removed
