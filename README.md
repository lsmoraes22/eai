# EAI

Laravel application for configuring HTTP integrations and processing JSON/XML documents through configurable processing stages.

## Overview

EAI stores integration settings in a relational database and processes documents through Artisan commands. A Filament panel manages clients, HTTP endpoints, mapping rules, schemas, webhooks, and processing status.

The most extensively tested paths are HTTP fetching/sending, page-based pagination, JSON Schema validation, and JSON-to-JSON transformation. Other paths have limitations described under **Project Status**.

## Features

- Configurable HTTP endpoints: method, headers, payload, authentication, timeout, retry settings, and polling interval.
- Integration authentication using configured tokens, token files, Basic authentication in fetching, and OAuth authorization/refresh flows. Authentication behavior depends on the endpoint and provider configuration.
- Page-based pagination with parameters in the query string or JSON body, configurable metadata paths, and a maximum page count.
- JSON Schema validation, including accepted/refused document handling.
- XML validation against XSD and XML normalization helpers.
- JSON-to-JSON mapping with defaults, explicit input/output endpoints, and optional per-item collection output.
- JSON-to-XML and XML-to-JSON mapping commands.
- Webhook reception with optional configurable HMAC verification.
- File-based processing stages and database status records.
- Cache locks for fetching, sending, JSON transformation, and JSON validation transitions.
- Filament/Livewire administration and role/permission policies through Filament Shield and Spatie Permission.

## Architecture

```text
Filament panel / configuration
          ↓
Relational database
          ↓
Artisan commands / processing
          ↓
Private files + status records
          ↓
External HTTP systems
```

Polling and webhooks receive documents through separate entry points. Commands validate, transform, or send eligible files. Stages use directories such as `raw`, `validated`, `refused`, and `processed`. The scheduler currently runs only the fetch command; the remaining stages require explicit execution or deployment-specific orchestration.

Integration processing is implemented in Commands, not queued Jobs. The queue worker present in the default development script does not make this pipeline asynchronous through Laravel queues.

## Tech Stack

Versions below come from the committed lockfiles; no dependency upgrade is required for these instructions.

| Component | Version / constraint |
| --- | --- |
| PHP | Application constraint `^8.2`; development/test dependencies require PHP 8.3+ |
| Laravel | 12.38.1 (`^12.0`) |
| Filament | 3.3.45 |
| Livewire | 3.6.4 |
| Filament Shield / Spatie Permission | Roles, permissions, policies |
| justinrainbow/json-schema | 6.6.3 |
| Pest / PHPUnit | 4.1.3 / 12.4.1 |
| Vite / Tailwind CSS | Frontend asset build |
| Database | MySQL/MariaDB schema; validated with MariaDB 10.11 |

## Requirements

- PHP 8.3+ for installation with development dependencies and running tests (validated with PHP 8.4.1).
- Composer 2.
- PHP extensions required by Composer, plus `pdo_mysql` for the application/Feature tests and `pdo_sqlite` for the isolated Unit tests. XML handling uses DOM, SimpleXML, and libxml.
- MySQL/MariaDB and a dedicated application database. Historical migrations use MySQL-specific SQL and collations; SQLite is not supported for installing the complete application schema.
- Node.js 20.19+ or 22.12+ and npm for the locked Vite build.
- Write access for the application user to `storage/` and `bootstrap/cache/`.

Use `composer check-platform-reqs` to verify installed PHP dependencies.

## Installation

These steps are for a **new local installation**. For an existing installation, read the storage transition instructions below before starting the updated application.

1. Clone this repository and enter its directory.
2. Create an empty application database and a database user with permissions on that database.
3. Copy the environment template:

   ```bash
   cp .env.example .env
   ```

4. Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Set `APP_URL` to the actual application origin, for example `http://127.0.0.1:8000` for local development. A Unix socket can alternatively be configured using `DB_SOCKET`.
5. Install dependencies, generate the application key, and run migrations:

   ```bash
   composer install
   php artisan key:generate
   php artisan migrate
   npm ci
   npm run build
   ```

6. Bootstrap permissions and create the first administrator:

   ```bash
   php artisan db:seed --class=ShieldSeeder
   php artisan make:filament-user
   php artisan shield:super-admin --panel=admin
   ```

   The user creation command prompts for credentials. On a new installation with one user, the final command assigns that user the super-admin role. With multiple users, select the intended account; `--user=<id>` is also available. The permission seeder is additive and generates database permissions without rewriting policies.

7. Run `php artisan serve` and open `/admin`.

Do not use `DatabaseSeeder` as an administrator bootstrap: it creates a factory test account. The repository's `composer setup` script runs migrations automatically and does not configure the database or administrator for you; the explicit steps above are the validated installation procedure.

Internal integration files do **not** require `php artisan storage:link`.

## Configuration

- **Environment:** `.env` holds deployment configuration. Keep it out of version control. Use `APP_DEBUG=false` in production. `FORCE_HTTPS=true` also enables HTTPS URL generation outside production; the provider already forces HTTPS in production.
- **Database:** use the `mysql` connection for the MySQL/MariaDB schema. Session and cache tables are created by the migrations.
- **Private storage:** the `integrations` disk is rooted at `storage/app/private/integrations`. It has private visibility, no public storage link, and no automatic file-serving route. Tokens, payloads, schemas, uploads, processing commands, and the panel explorer use this disk. Relative processing paths remain unchanged.
- **Endpoint credentials:** configured through the client/endpoint forms. Token files are read from the private disk. Provider responses used as input remain intact in private storage; diagnostic output does not intentionally include their bodies.
- **OAuth:** use **Vincular Conta API** in the client table. The signed-in user needs permission to update the client. The callback is `APP_URL` plus `/token/{client_id}`; register that exact URL with the provider. Start and complete authorization in the same browser session and application origin. State expires after ten minutes and is consumed once, including when the provider exchange fails. Start a new attempt after an error or deployment that replaces the old flow. Database/file-backed sessions and a cache driver supporting locks are required; cookie-only sessions do not support the route's session blocking.
- **Schemas:** upload JSON Schema/XSD files in the panel. Filenames must match the names expected by the relevant validation command. To avoid existing normalization inconsistencies, use simple lowercase alphanumeric client codes and consistently named endpoints/processes.
- **Webhooks:** `POST /webhook/{client_id}/{interface}`. Enable HMAC verification and configure the provider's header/algorithm when supported. Verification is optional in the existing configuration; this round does not change that behavior.

### Existing installations: private storage transition

This release changes the storage location. It does not move existing files automatically or silently read public files during normal processing.

1. Back up the database and integration files. Stop the scheduler, manual routines, application writers, and webhook ingestion. Keep them stopped until the transition is verified.
2. Block HTTP access to legacy internal directories under `/storage` at the web server. New private writes do not protect existing public copies.
3. Deploy the code while processing remains stopped. Clear stale configuration/routes with `php artisan config:clear` and `php artisan route:clear`.
4. Inspect the legacy public files without changing them:

   ```bash
   php artisan app:import-private-storage
   ```

5. Copy and verify files, using a single importer:

   ```bash
   php artisan app:import-private-storage --copy
   ```

   The command preserves relative paths under `token`, `tokens`, `polling`, `webhooks`, `clients`, `temp/xsd`, and `temp/json_schema`. It verifies SHA-256 checksums, skips identical copies, and fails on conflicting content without overwriting either version. A failed write leaves the original untouched; inspect any incomplete private copy before retrying.

6. Older upload/service code could also use the default `local` disk. If applicable, inspect and copy those locations:

   ```bash
   php artisan app:import-private-storage --source=local
   php artisan app:import-private-storage --source=local --copy
   ```

   This scans the same internal prefixes on `local` and maps the old `public/clients` prefix to `clients`. If the old installation used a custom disk/root or external tooling, inventory and copy those files separately before resuming; the command does not guess custom locations.

7. Confirm zero conflicts and zero pending files by repeating inspection **before** resuming processing. Verify required schemas, tokens, and pending documents. Resolve conflicts manually; there is no force-overwrite option.
8. Archive or remove legacy public copies in a separate, reviewed operation. The importer never deletes them. Keep HTTP access blocked and rotate credentials if they were previously exposed.
9. Update external file readers/writers to the private location, then resume processing.

Do not repeat the import after processing resumes: old public `raw` files could be copied back after their private counterparts have moved to later stages. Rolling back also requires reconciling newly processed private files; it is not a storage-path toggle.

## Running the Application

For local development after building assets:

```bash
php artisan serve
```

Use `npm run dev` when editing frontend assets. Run the scheduler separately:

```bash
php artisan schedule:work
```

For deployment, invoke `php artisan schedule:run` once per minute through the scheduler mechanism used by your host. The committed schedule invokes `app:fetch-endpoints` every minute with overlap protection. It does not automatically validate, transform, or send all received documents.

Available processing commands include:

```bash
php artisan app:fetch-endpoints
php artisan app:validate-json
php artisan app:validate-xml
php artisan app:convert-json-json
php artisan app:convert-json-xml
php artisan app:convert-xml-json
php artisan app:send-endpoints
```

Fetch/send accept `--id=<endpoint-id>`. They can contact real systems and change processing state: use synthetic data and dedicated endpoints for demonstrations. The panel also exposes manual routines. Do not use the incomplete XML-to-XML routine as part of an operational pipeline.

## Running Tests

Feature tests use the real migrations on a **dedicated MySQL/MariaDB database named `eai_testing`**. They refresh its schema; never point them at an application database. Use credentials restricted to that disposable database. Unit tests retain their existing small, independently constructed SQLite in-memory schemas; these do not establish full SQLite compatibility.

1. Create `eai_testing` on your test MySQL/MariaDB server.
2. Create a separate test environment:

   ```bash
   cp .env.testing.example .env.testing
   ```

3. In `.env.testing`, configure the test-only host/port/username/password. Create a dedicated database user restricted to `eai_testing`; do not use an application account or require `root`. Set `DB_SOCKET` locally only if your server uses a Unix socket. Keep `DB_DATABASE=eai_testing` and use a local `APP_URL`. The template intentionally has no application key or password: generate your own test key below. `.env.testing` remains ignored by Git; only `.env.testing.example` is versioned. Do not copy real integration credentials into this file.
4. Prepare and run:

   ```bash
   php artisan key:generate --env=testing
   npm ci
   npm run build
   composer test
   ```

`phpunit.xml` fixes the database connection/name, bypasses application route/config caches, and configures in-memory session/cache drivers for tests. Feature bootstrap requires `.env.testing` and a test database name. Assets are built so that HTTP view tests render their real Vite references; no assertions or tests are disabled to bypass missing assets.

Tests cover authentication/profile behavior, OAuth state validation/replay, private file writes and legacy copying, permission bootstrap, pagination, locks, JSON mapping/collections, and JSON validation. HTTP requests in integration tests are faked; passing them does not certify a live provider integration.

## Security Considerations

- Keep the web server document root at `public/`. Never expose `storage/app/private` through aliases or symlinks.
- Complete the documented transition for existing installations. Ignoring files in Git does not prevent HTTP exposure of legacy files.
- OAuth state is bound to the session, user, and client, with single-use consumption. The redirect URI saved at authorization start is reused during code exchange.
- OAuth, refresh, fetch, and send diagnostics retain identifiers/status or exception types instead of response bodies, full endpoint query strings, or exception messages that can contain secrets.
- Database-stored integration credentials still need database/backup access controls. This change does not introduce encryption of existing database columns.
- The panel's existing authorization model is not a guarantee of per-client isolation. Custom routine/file actions and proxy trust settings still need deployment-specific security review.

## Project Status

EAI contains a working and tested HTTP/JSON integration core alongside less mature XML and administrative paths.

Known boundaries:

- XML-to-XML is incomplete and is not advertised as operational.
- JSON-to-XML and XML-to-JSON exist, but do not have the same test coverage as JSON-to-JSON.
- Webhook reception exists; the committed validators scan polling directories, so a complete automatic webhook pipeline is not implemented.
- No complete public REST API, integration Jobs architecture, exactly-once delivery, or secure tenant isolation is claimed.
- Existing path-normalization differences, status updates keyed only by filename, and send authentication edge cases remain follow-up work.
- `RolePolicy` still contains template placeholders for unsupported operations; the supported role operations and resource permissions are bootstrapped, without redefining those operations here.
- SAP, Infolog, M40, and other domain references describe the existing model; they do not by themselves establish a complete certified connector for those products.

`composer.json` declares MIT, but this repository currently has no standalone `LICENSE` file. Ownership and licensing should be confirmed before adding or changing a license notice.
