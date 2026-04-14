# LDAP Tools for GLPI 11

Enhanced LDAP diagnostic and testing plugin for **GLPI 11.0.x**. Forked and modernized from [pluginsglpi/ldaptools](https://github.com/pluginsglpi/ldaptools) (GLPI 10 only).

Runs a full diagnostic suite against all LDAP directories configured in GLPI, with detailed per-step timing, structured logging, and a historical log viewer.

![License](https://img.shields.io/badge/license-GPLv3-blue)
![GLPI](https://img.shields.io/badge/GLPI-11.0.x-green)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-purple)

---

## Features

### Diagnostic Tests (8 steps, sequential)

| # | Test | What it checks |
|---|------|---------------|
| 1 | **DNS Resolution** | Resolves the server hostname and displays resolved IPs with timing |
| 2 | **TCP Connectivity** | Tests TCP connection to the configured port (389/636/custom) |
| 3 | **BaseDN Validation** | Verifies the Base DN field is not empty |
| 4 | **LDAP URI + TLS** | Connects via `ldap_connect`, applies StartTLS if configured |
| 5 | **Bind Authentication** | Tests bind with RootDN/password, retrieves server info (vendor, version, naming contexts) |
| 6 | **Generic Search** | Runs `(cn=*)` search with configurable entry limit |
| 7 | **Filtered Search** | Runs the LDAP filter configured in GLPI |
| 8 | **Attribute Discovery** | Lists available LDAP attributes from the first returned entry |

### Enhancements over the original plugin

- **GLPI 11 compatible** (PHP 8.3+, updated APIs)
- **Configurable search limit** — dropdown to choose max entries (5 / 50 / 200 / 1000 / unlimited)
- **Per-step timing** — each test shows elapsed time in milliseconds
- **Server info extraction** — after bind, displays vendor name, version, supported SASL mechanisms, naming contexts
- **TLS indicator** — lock icon shows StartTLS status (success/failure/not configured)
- **Persistent test logs** — all results saved to `glpi_plugin_ldaptools_logs` (34 fields)
- **Log viewer** — dedicated page with server filter and historical results
- **Slow test warning** — tests exceeding 30s are flagged as WARN
- **Re-run button** — re-execute all tests without page reload
- **LDAP replica support** — tests master and all configured replicas
- **Portuguese (pt_BR) translation** included

---

## Requirements

- **GLPI** 11.0.x
- **PHP** 8.3+
- **php-ldap** extension

---

## Installation

### Option 1: Git clone (recommended for easy updates)

```bash
cd /path/to/glpi/plugins
git clone https://github.com/rbferreira/glpi-ldaptools.git ldaptools
```

> The folder **must** be named `ldaptools`.

### Option 2: Manual copy

Download/extract the release into `/path/to/glpi/plugins/ldaptools/`.

### Activate the plugin

**Via CLI:**
```bash
cd /path/to/glpi
php bin/console plugin:install ldaptools
php bin/console plugin:activate ldaptools
```

**Via web UI:**
1. Go to **Setup > Plugins**
2. Find **LDAP Tools** → Install → Enable

### Docker

```bash
# Copy into the container
docker cp ldaptools glpi:/var/www/glpi/plugins/ldaptools

# Install and activate
docker exec -it glpi bash -c "cd ~/glpi && php bin/console plugin:install ldaptools && php bin/console plugin:activate ldaptools"
```

To update after a `git pull`:
```bash
docker cp ldaptools/. glpi:/var/www/glpi/plugins/ldaptools/
```

---

## Usage

After activation, go to **Tools > LDAP Tools** in the sidebar menu.

- **LDAP Test** — runs all 8 diagnostic tests on every configured LDAP server
- **Test Logs** — historical view of all past test results with per-step status

### Search limit

Use the **Max entries** dropdown to control how many LDAP entries the search tests return:

| Limit | Use case | Typical time |
|-------|----------|-------------|
| 5 | Quick connectivity check | ~1s |
| 50 | Standard diagnostic (default) | ~2-3s |
| 200+ | Checking larger result sets | ~10-30s |
| Unlimited | Full directory scan (use with caution) | Minutes, depends on directory size |

> **Tip:** If you get proxy timeouts (502/504), reduce the limit. The test is diagnostic — it doesn't need to return all entries to confirm the LDAP connection works.

---

## File Structure

```
ldaptools/
├── setup.php              # Plugin init, version, GLPI hooks
├── hook.php               # Install/uninstall (table creation)
├── composer.json           # PHP 8.3+ requirement
├── ldaptools.xml           # Plugin manifest
├── inc/
│   ├── menu.class.php     # Main menu, tool discovery
│   ├── test.class.php     # Test page controller
│   └── log.class.php      # Log model (CommonDBTM), DB table management
├── ajax/
│   └── test.php           # AJAX handler — runs the 8 diagnostic tests
├── front/
│   ├── menu.php           # Menu route
│   ├── test.php           # Test page route
│   └── log.php            # Log viewer route
├── templates/
│   ├── menu.html.twig     # Menu template
│   ├── test.html.twig     # Test UI (table + JS + limit dropdown)
│   └── log.html.twig      # Log viewer (filter + history table)
└── locales/
    ├── en_GB.po / .mo     # English
    └── pt_BR.po / .mo     # Portuguese (Brazil)
```

---

## Log Table Schema

The plugin creates `glpi_plugin_ldaptools_logs` with fields for each test step:

- **Identification:** server name, hostname, port, master/replica flag
- **Per-step status:** `ok`, `error`, `warn`, or `skip` for each of the 8 tests
- **Timing:** milliseconds for DNS, TCP, connect, TLS, bind, search, filter, and total
- **Results:** entry counts, attribute lists, server info (JSON), error details
- **Metadata:** timestamp, user ID, overall status

Logs can be filtered by server in the viewer. Cleanup via `PluginLdaptoolsLog::purgeLogs($days)`.

---

## License

GPLv3 — see [LICENSE](LICENSE).

Based on the original work by François Legastelois / [Teclib'](https://www.teclib.com).
