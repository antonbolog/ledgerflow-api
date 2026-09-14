# GitHub automation instructions

Applies to `.github/**` in addition to the repository root instructions.

- Workflows orchestrate public Make targets; they must not duplicate Composer, Artisan, Pest, Pint,
  Rector, PHPStan, database, or image-build command sequences.
- Keep job permissions least-privilege and disable persisted checkout credentials when no later
  step needs to push.
- Run `make install` before quality tools or Pest.
- Give each run attempt its own Compose project through `COMPOSE_PROJECT_NAME`. GitHub-hosted runner
  disposal is the cleanup boundary; do not add teardown harnesses.
- Keep quality, unit tests, integration tests, runtime image build, and Nginx image build in
  separately named steps calling the corresponding Make targets.
- `make ci` mirrors those five steps for local use after installation.
- Build images without publishing, credentials, deployment, or additional security stages.
- Failure diagnostics may inspect Compose state and logs, but must not hide the original failure.
- Pin runner images deliberately. Do not add caches, service substitutes, or setup actions that
  create a second development environment without an accepted contract change.
