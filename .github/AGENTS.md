# GitHub automation instructions

Applies to `.github/**` in addition to the repository root instructions.

- Workflows orchestrate public Make targets; they must not duplicate Composer, Artisan, Pest, Pint,
  Rector, PHPStan, database, or image-build command sequences.
- Keep job permissions least-privilege and disable persisted checkout credentials when no later
  step needs to push.
- Keep clean-checkout setup explicit. Application validation belongs behind `make check`; the full
  guard, isolation, and image contract belongs behind `make ci`.
- Failure diagnostics may inspect Compose state and logs, but must not hide the original failure.
- Pin runner images deliberately. Do not add caches, service substitutes, or setup actions that
  create a second development environment without an accepted contract change.
