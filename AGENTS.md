# Skladex development conventions

Production: Debian 13, PHP 8.4, Laravel 13, MariaDB 11.8, Nginx, Redis. The interface uses Blade and local CSS/JS/fonts; Node and a CDN are not required at runtime. Read README.md and docs/operations.md before changing deployment.

- Keep equipment limited to technical equipment: IT, audio and existing stage lighting. Server/network racks are equipment; furniture is out of scope. Preserve uncategorized legacy items explicitly as unknown.
- Never mutate the legacy .200 application. Legacy import requires an explicit operator command after backup and preflight; the user authorized the current migration. Preserve exact old code and numeric-ID mappings independently; never derive EQ codes from IDs.
- Public responses use explicit field allowlists. Never expose license keys, responsible people, network addresses, documents or internal notes.
- License keys use the separate master key, not APP_KEY. Do not commit secrets, certificates, dumps, logs, generated PDF or .env.
- Business writes belong in services with authorization, transactions, version checks and audit metadata. API writes require a scoped bearer token and Idempotency-Key. Revealing keys is never replay-cached.
- Physical graph writes take Codes::lockPhysicalGraph before any asset locks; read graph edges using current locking reads. Multi-asset actions validate all submitted versions.
- Run tests only against a dedicated *_test MariaDB database. TestCase refuses other database names. Do not use migrate:fresh against production. deploy/test.sh refuses cached production config.
- Prefer meaningful regression tests for invariants and real MariaDB constraints. Run PHP lint, the affected suite and relevant HTTP/PDF/UI checks.
- Keep all UI copy Russian, light/dark colors monochrome, approved LINK mark, Inter UI and IBM Plex Mono codes/wordmark. Local assets and readable ordinary HTML are deliberate.
