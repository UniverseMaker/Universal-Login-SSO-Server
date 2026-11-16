<div align="center">

# ulogin — a self-hosted login & SSO server

**A login server that keeps API keys out of the browser.**
It centralizes sign-in for local HTML apps and AI apps, and hands each signed-in user only the keys and settings that service allows.

[![PHP 7.2+](https://img.shields.io/badge/PHP-7.2%2B-777bb4)](#stack-and-requirements)
[![SQLite default · MySQL optional](https://img.shields.io/badge/DB-SQLite%20%C2%B7%20MySQL-0f6e5e)](#stack-and-requirements)
[![Composer 0](https://img.shields.io/badge/Composer-0%20dependencies-555)](#stack-and-requirements)
[![Tests](https://img.shields.io/badge/tests-php%20tests%2Frun.php-2f6f4f)](#regression-tests)
[![License MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

[한국어](README.md) · English

<img src="docs/images/04-admin-dashboard.png" alt="Admin console dashboard" width="860">

</div>

The screenshots show the Korean UI. Every account, e-mail address and key in them is fake demo data.

---

## Strengths

| Area | What it does |
|---|---|
| **AI credential management** | LLM API keys, model names, API base URLs and call limits live on the server as service secrets, encrypted with AES-256-CBC. Right after sign-in they are delivered as `payload` to that user only. Each key can require a minimum account level, billing tier or role; keys the user does not qualify for are simply left out |
| **SSO** | One-time code exchange (60 s), PKCE (S256) supported. A separate silent-SSO path issues a code for a browser that is already signed in, without asking for the password again |
| **Account linking** | Each connected system pushes its own account list over a server-to-server API; the admin console links those accounts to unified accounts, showing same-e-mail matches as candidates first |
| **Fine-grained access levels** | Four levels per account × service: `none`, `view`, `use`, `admin`. Grant through groups, override per account (allow or deny), and set start/end times. Deny always wins, and revoking access ends that service's sessions |
| **Platform API** | Five shared services for connected systems: remote config (conditions, versions, rollback), operational log intake, audit log intake with an HMAC-SHA256 hash chain and a chain check, document tables, and usage metering |
| **Emergency admin** | An emergency account named `admin`. Its password can only be set from the server's CLI, and it cannot sign in until then. Console-only; it is never an SSO target for other services |
| **Install & operate** | SQLite by default, so no database server is required; MySQL is one config line away. PHP 7.2+, no Composer, no build step — upload the files. Schema changes go through a migration runner |
| **Settings bundle** | Export and import services, secrets and global settings as one JSON file, in three steps: validate → plan → apply. Exports never contain secret values. A separate guide lets an AI write valid bundles |
| **Regression tests** | `php tests/run.php` runs static checks, unit tests and live-server tests. A self-check verifies that the checkers actually catch planted defects |
| **research-ui theme** | Left navigation and top bar, light and dark modes, selectable palettes. Usable down to a 390 px phone screen |

---

## Why

If you build an AI app as a single HTML file, the API key ships inside that file. JavaScript that a browser runs is readable by anyone, so the key is public the moment you share the app.

Keeping the key on a server means sign-up, sign-in, sessions, permissions and an admin screen. ulogin does only that part.

1. The app hands sign-in over to ulogin.
2. ulogin gives the signed-in user that app's secrets. Keys above the user's level are not sent.
3. The app keeps what it received in memory only.

It is built to run on shared hosting (PHP only, no Composer, no shell). It also serves as a single sign-on hub for several systems.

---

## Architecture

```mermaid
flowchart LR
    subgraph Clients[Connected systems]
        A[Local HTML / AI app<br/>ulogin-client.js]
        B[Other web systems<br/>server-side integration]
    end
    subgraph ulogin[ulogin server · PHP 7.2+]
        P[public/<br/>sign-in · my page · admin console]
        API[server/api/<br/>auth · sso · me · admin · platform]
        CORE[server/core/<br/>decisions · crypto · audit chain]
    end
    DB[(SQLite default<br/>MySQL optional)]
    A -- "sign-in redirect · code exchange · payload" --> API
    B -- "service_secret: accounts · logs · audit · config" --> API
    P --> API
    API --> CORE --> DB
```

- Screens (`public/`) and APIs (`server/api/`) map one file to one URL, so nothing breaks without `mod_rewrite`.
- Access decisions are made in one place, `server/core/access.php`; the admin screens display the server's verdict as is.
- Browser apps use PKCE and user tokens; other systems' servers authenticate with `service_id` + `service_secret`.

### SSO flow

```mermaid
sequenceDiagram
    participant App as My app (browser)
    participant UL as ulogin
    participant User as User
    App->>App: create code_verifier, store state
    App->>UL: /login?service_id&redirect_uri&state&code_challenge
    UL->>User: sign-in screen (skipped if already signed in)
    User->>UL: username · password
    UL->>App: redirect_uri?code&state (one-time, 60 s)
    App->>UL: POST /server/api/auth/exchange.php (code, code_verifier)
    UL->>App: access_token · refresh_token · user · payload
    Note over App: keep payload (keys, model info) in memory only
```

---

## Screens

All captures come from a freshly installed demo instance with fake data.

### Sign-in and my page

| | |
|---|---|
| ![Sign-in](docs/images/01-login.png)<br>**Sign-in** — connected systems on the left, the form on the right | ![Sign-in, dark](docs/images/02-login-dark.png)<br>**Sign-in (dark)** |
| ![My page portal](docs/images/03-mypage-portal.png)<br>**My page** — account level, role and billing tier badges, plus shortcuts to connected systems | ![Phone sign-in](docs/images/20-mobile-login.png)<br>**Phone, 390 px** |

### Admin console — members and services

| | |
|---|---|
| ![Dashboard](docs/images/04-admin-dashboard.png)<br>**Dashboard** — member, sign-in, point and session metrics with tier distribution | ![Members](docs/images/05-admin-users.png)<br>**Members** — filters by status, role, account level and billing tier |
| ![Account linking](docs/images/06-admin-links.png)<br>**Account linking** — per-system intake status and link counts | ![Services](docs/images/07-admin-services.png)<br>**Services** — redirect allow-list, allowed origins, session lifetimes |
| ![Secrets](docs/images/08-admin-secrets.png)<br>**Secrets (AI credentials)** — key, model, base URL, limits; values always shown as `****`, each with a minimum level | ![Settings bundle](docs/images/17-admin-settings-bundle.png)<br>**Settings bundle** — export and import as one JSON file |

### Admin console — access

| | |
|---|---|
| ![Access matrix](docs/images/09-admin-access-matrix.png)<br>**Account × service matrix** — each cell shows the server's verdict, its source (rule, group or override) and expiry | ![Groups](docs/images/10-admin-access-groups.png)<br>**Groups** — members and per-service levels |

### Admin console — logs and platform

| | |
|---|---|
| ![Log statistics](docs/images/11-admin-logs-stats.png)<br>**Sign-in log statistics** — success, failure and security-signal groups | ![Audit log](docs/images/12-admin-audit-chain.png)<br>**Audit log** — hash-chain check result |
| ![Remote config](docs/images/13-admin-global-config.png)<br>**Remote config** — drafts, published version, conditional values | ![Operational logs](docs/images/16-admin-ops-logs.png)<br>**Operational logs** — logs sent by connected systems |
| ![Tables](docs/images/14-admin-tables.png)<br>**Tables** — collections and documents per service | ![Usage](docs/images/15-admin-usage.png)<br>**Usage** — daily totals for logs, audit events, document reads and writes |

### Light, dark, phone

| | | |
|---|---|---|
| ![Dashboard dark](docs/images/18-admin-dashboard-dark.png)<br>**Dashboard (dark)** | ![Access dark](docs/images/19-admin-access-matrix-dark.png)<br>**Access (dark)** | ![Phone my page](docs/images/21-mobile-mypage.png)<br>**My page (390 px)** |

---

## Features

### AI credential management — secrets and payload

- Register key-value secrets per service. Value types: `string`, `json`, `url`.
- Stored with AES-256-CBC. List and update responses always show `****`; only a superadmin can reveal a value, and each reveal is audit-logged.
- Each key may require a minimum account level (`guest` … `staff`), billing tier (`free` … `enterprise`) or role. A user below the bar gets no error — that key is just missing from `payload`.
- Keys whose names look sensitive (`*_api_key`, `token`, `secret`, …) cannot be saved without a gate unless `allow_public` is set explicitly.
- Accounts at the `view` level receive no secrets (`payload` is `{}`).
- Secret deliveries are logged per account, service and time window.

### Authentication and SSO

- Sign-up, sign-in, password reset, e-mail verification, session management, account withdrawal.
- Three-axis permissions: account level, role, billing tier. Expired billing falls back to the lower tier.
- SSO redirect URIs must match the service's allow-list exactly. Codes are one-time and live 60 s; PKCE accepts S256 only.
- Silent SSO (`/server/api/sso/authorize_session.php`) re-issues a code from an existing browser session. It is not open to public clients.
- Public clients (native apps) may use RFC 8252 private-use URI schemes as redirect URIs.
- Lockout on repeated failures, rate limiting, CSRF tokens, `HttpOnly` / `SameSite` cookies.

### Account linking

- Each system sends its account list to `/server/api/sso/accounts_push.php` (server-to-server).
- In the console's account-linking screen, link, create or ignore accounts as a two-step job (plan → commit) with a job history.
- Accounts flagged as emergency accounts are never linked automatically.

### Access levels and groups

- Service rules: `all` (everyone uses), `all_view` (everyone views), `granted` (listed accounts only), `admin` (admins only).
- Give a group a level per service and add members; both memberships and grants can have start/end times.
- Per-account overrides are allow (with an optional level) or deny. Deny beats groups and rules.
- Unless a service declares that it understands levels (`level_aware`), `view` accounts cannot enter it — a read-only account never lands with write access.
- The verdict is returned as `access` (a per-service level map) and `access_level`; the existing boolean `services` field is unchanged.
- Fine-grained features inside a service (`features`) are registered by the service; ulogin stores and forwards them.

### Platform API

Shared server features for connected systems, authenticated with `service_secret` (server-to-server) or that service's user token.

| Feature | Endpoint | Admin console |
|---|---|---|
| Remote config | `platform/remote_config.php` — returns `changed:false` when the etag matches | draft → publish, version diff, rollback, conditional values |
| Operational logs | `platform/logs.php` — batched intake, sensitive values redacted | filter by service, level, event, period |
| Audit log | `platform/audit.php` — each row stores `row_hash = HMAC-SHA256(K, prev_hash ‖ row)` | chain check (reports the first broken sequence), export |
| Document tables | `platform/collections.php`, `platform/docs.php` — per-collection read/write rules, three indexes | service → collection → document browser |
| Usage | metered automatically | daily table per service |

Actions taken in the admin console (permission changes, config publishes, …) go into the same audit chain.

### Emergency admin

```bash
php server/tools/emergency_admin.php --init --apply          # create the "admin" emergency account, password unset
php server/tools/emergency_admin.php --set-password --apply  # set the password (read twice from stdin)
php server/tools/emergency_admin.php --status                # show state (never prints the value)
```

- There is no web screen for setting this password; a shell on the server is the proof of being on the server.
- The admin password policy (12+ characters) and lockout apply. Setup and sign-ins are audit-logged without the value.

---

## Quick start

### 1. Shared hosting

1. Upload the repository's `server/`, `public/` and `examples/` folders to your web root or a sub-folder. That location is the **install root**.
2. Open `{install root}/server/install.php` in a browser.
3. The first screen shows where the **install token file** is (shared hosting: `server/storage/.install-token`). Open it over FTP and enter the value.
4. Walk through: environment check → database (SQLite or MySQL) → schema → site security → admin account → first service → secrets → done.
5. The installer locks itself when finished. The admin console is `{install root}/public/admin.html`.

Full steps and troubleshooting are in [`INSTALL.md`](INSTALL.md) (Korean).

### 2. Run it locally

```bash
php -S 127.0.0.1:8080 -t . tools/router-ulogin.php
```

Install at `http://127.0.0.1:8080/server/install.php`, then open `http://127.0.0.1:8080/login`. The router maps short paths (`/login`, `/admin`, `/mypage`) to the screen files.

### 3. Schema updates

```bash
php server/migrate.php status   # list pending migrations
php server/migrate.php apply    # apply them
php server/migrate.php verify   # compare schema.sql with the live database
```

---

## Integration

### Browser app — `ulogin-client.js`

```html
<script src="https://login.example.com/public/assets/ulogin-client.js"></script>
<script>
const ulogin = new UloginClient({
  server: 'https://login.example.com',                     // install root
  serviceId: 'demo-ai',                                    // service_id registered in the console
  redirectUri: location.origin + '/callback.html'          // must match the allow-list exactly
});

async function ask(prompt) {
  if (!ulogin.isLoggedIn()) return ulogin.login();         // go to the sign-in screen
  await ulogin.getSession();                               // verify the token, fetch payload again
  const p = ulogin.getPayload();                           // { openai_api_key, ai_model, ... }
  if (!p.openai_api_key) throw new Error('AI is not available at this level');
  // call the AI API with p.openai_api_key; never persist it
  await ulogin.consumePoints(10, { reason: 'usage' });
}
</script>
```

On the callback page (`callback.html`), create the client with the same options and call `ulogin.handleCallback()`. A complete working app is in [`examples/local-ai-app.html`](examples/local-ai-app.html).

### Server-to-server — sending operational logs

```bash
curl -s -X POST https://login.example.com/server/api/platform/logs.php \
  -H 'Content-Type: application/json' \
  -d '{"service_id":"demo-notes","service_secret":"<service secret>",
       "events":[{"event_id":"8f0c6c1e-2b7a-4d2e-9a51-3c1d2e4f5a6b","ts":"2026-11-16T09:00:00Z",
                  "level":"error","event":"job.export.failed","message":"export failed"}]}'
```

The response looks like `{"ok":true,"data":{"accepted":1,"duplicate":0,...},"error":null}`. Node, Python and PHP samples are in [`examples/platform/`](examples/platform/).

---

## Documentation

Most documents are in Korean.

| Document | Contents |
|---|---|
| [`INSTALL.md`](INSTALL.md) | Installation, post-install security checks, operations, troubleshooting |
| [`AI_INTEGRATION_GUIDE.md`](AI_INTEGRATION_GUIDE.md) | A self-contained guide an AI agent can read alone to write integration code: every endpoint, response and error code |
| [`AI_SETTINGS_JSON_GUIDE.md`](AI_SETTINGS_JSON_GUIDE.md) | How to write a settings bundle (for AI agents) |
| [`examples/README.md`](examples/README.md) | Wiring a local app |
| [`tests/README.md`](tests/README.md) | Test suite layout and what each tier covers |
| [`tools/README.md`](tools/README.md) | Router and operations tools |
| [`CHANGELOG.md`](CHANGELOG.md) | Change history |
| [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) | Design background and verification (recorded at v1.3.0) |

---

## Stack and requirements

| Item | Detail |
|---|---|
| Language | PHP 7.2+ (8.x works). Extensions: PDO (`pdo_sqlite` or `pdo_mysql`), OpenSSL, mbstring |
| Storage | SQLite by default. MySQL / MariaDB via `DB_DRIVER` in `config.php`, table prefix `app_ulogin_` |
| Dependencies | None. No Composer, npm or build step |
| UI | HTML, CSS, vanilla JS with the research-ui theme (light/dark, palettes) |
| Web server | Apache (ships `.htaccess`) or `php -S` with `tools/router-ulogin.php` |

---

## Regression tests

```bash
php tests/run.php
```

| Tier | Contents |
|---|---|
| Tier 1, static | Cross-checks API response fields against fields the screens use, error-code tables, links, PHP 7.2 syntax |
| Tier 2, unit | Decision functions, gate parity, SQLite storage, router |
| Tier 3, live | Sandbox install + `php -S` with real HTTP round trips (concurrency, cookies, migrations, platform API) |
| Self-check | Confirms each checker fires on fixtures with planted defects |

Exit codes: `0` pass · `1` failure · `2` bad arguments · `3` nothing ran · `4` the runner itself is broken. Without a configured test database some MySQL-only tier-3 cases are skipped; [`tests/README.md`](tests/README.md) lists what is not covered then.

---

## Known limits

- **No 2FA.** The column exists; the feature does not.
- **No `SECRET_ENC_KEY` rotation.** Changing the key makes stored secrets undecryptable.
- **No absolute session lifetime and no HSTS header.** Refreshing keeps a session alive; add HSTS in your web server.
- **No log retention policy.** Sign-in, admin, point and secret-delivery logs are not pruned automatically.
- **PKCE is not mandatory.** Clients that send no `code_challenge` are accepted; over `file://` the client skips PKCE because `crypto.subtle` is unavailable.
- **`docs/PORTFOLIO.md` and `docs/screenshots/` are v1.3.0-era records.** Current screens are in `docs/images/`.

---

## License

[MIT](LICENSE)
