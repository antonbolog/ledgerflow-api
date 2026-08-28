# Documentation instructions

Applies to `docs/**` in addition to the repository root instructions.

## Ownership

- `roadmap.md` owns delivery order, task state, dependencies, and tracker links.
- `architecture/` explains boundaries, flows, and trade-offs.
- `specifications/` defines canonical behavior and invariants.
- `testing/` maps behavior to executable evidence.
- `operations/` owns process topology, observability, and runtime readiness.
- `adr/` records durable decisions using `adr/README.md`.

## Rules

- Update the smallest canonical document; link to it instead of copying task history or evidence.
- Keep the root README and documentation indexes product-oriented. The roadmap owns delivery
  chronology; a specification may retain task attribution only when it explains durable evidence or
  ownership.
- Record implementation evidence only after inspecting the exact source and running the owning
  repository validation or measurement.
- Distinguish implemented behavior, measured compatibility evidence, and planned work explicitly.
- Keep cross-repository ownership clear: application here, merchant dashboard in `ledgerflow-spa`,
  infrastructure in `ledgerflow-infra`.
- When behavior changes, check inbound documentation references and update related ADR/spec/runtime
  claims in the same change.
