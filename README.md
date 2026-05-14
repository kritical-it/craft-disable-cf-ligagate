# Disable CF Ligagate

Craft CMS plugin for temporarily disabling the Cloudflare orange-cloud proxy when Spanish ISPs are blocking Cloudflare IPs during football matches.

The plugin checks an external text endpoint with blocked IPs, decides whether the proxy should be disabled, and updates the configured Cloudflare DNS records only when a state change is needed.

## Requirements

- Craft CMS 5.9.0 or later
- PHP 8.2 or later
- A Cloudflare API token with DNS read/edit permissions for the target zone

## Installation

Install the plugin in a Craft project with Composer, then install it in Craft:

```bash
composer require kritical-it/craft-disable-cf-ligagate
php craft plugin/install disable-cf-ligagate
```

If your project runs through DDEV:

```bash
ddev composer require kritical-it/craft-disable-cf-ligagate
ddev craft plugin/install disable-cf-ligagate
```

Installing the plugin creates a state table used to remember which DNS records were changed by the plugin.

## Settings

Configure the plugin from the Craft control panel plugin settings.

### Cloudflare Zone ID

Cloudflare zone ID for the site.

Environment variables are supported, for example:

```text
$CLOUDFLARE_ZONE_ID
```

### Cloudflare API Token

Cloudflare API token used to read and update DNS records.

The token needs permission to:

- read DNS records
- edit DNS records

Environment variables are supported, for example:

```text
$CLOUDFLARE_API_TOKEN
```

### DNS Record Hostnames

Hostnames whose Cloudflare DNS records should be managed.

Examples:

```text
example.com
www.example.com
```

Multiple hostnames can be separated by commas, spaces, or new lines. Environment variables are supported.

The plugin looks up matching proxied DNS records in the configured Cloudflare zone and persists their Cloudflare record IDs after discovery.

### Disable Strategy

Available values:

- `Disable if match exact IP`
- `Disable if any IP`

`Disable if match exact IP` resolves the configured hostnames from the server running Craft using DNS lookups for `A` and `AAAA` records, then disables Cloudflare only if one of those resolved IPs appears in the blocked-IP list. If the hostname is proxied by Cloudflare, these resolved IPs will normally be Cloudflare edge IPs, which is the intended comparison.

`Disable if any IP` disables Cloudflare when the blocked-IP list contains at least the configured threshold number of valid IPs.

### Any IP Threshold

Used only by `Disable if any IP`.

Default:

```text
10
```

If the status URL returns at least this many valid blocked IPs, the plugin switches to proxy-disabled mode.

### Blocked IP Status URL

Plain text URL where each line contains one blocked IP.

Default:

```text
https://hayahora.futbol/estado/blocked-any.txt
```

### Custom Resolver Class

Optional PHP class that overrides the default status resolver.

The class must implement:

```php
KriticalIT\Ligagate\contracts\StatusResolverInterface
```

The environment variable `DISABLE_CF_LIGAGATE_RESOLVER_CLASS` takes precedence over the setting field.

### Respect Manual Changes

Controls whether the plugin should preserve manual Cloudflare proxy changes.

Default:

```text
true
```

During a detected block, the plugin always checks all configured DNS records and ensures they are unproxied. This setting only changes what happens when the block is no longer detected.

When enabled, the plugin only restores DNS records that it disabled itself and that were originally proxied.

When disabled, each check enforces the desired state:

- blocked: configured records should be unproxied
- not blocked: configured records should be proxied

This setting supports boolean environment variables through the control panel field. Accepted values include `true`, `false`, `1`, `0`, `yes`, `no`, `on`, and `off`.

### Request Timeout

Timeout in seconds for HTTP calls to the status URL and Cloudflare API.

Default:

```text
10
```

## Commands

All examples use DDEV because this project runs Craft through DDEV.

For production environments without DDEV, use the equivalent `./craft ...` command from the project root.

### Check Current Status

Runs the resolver, decides whether Cloudflare should be disabled or enabled, and applies only the required changes.

```bash
ddev craft disable-cf-ligagate/proxy/check
```

This is the command intended for periodic execution.

### Dry Run Check

Runs the same check and prints the desired state and how many records would change, but does not update local state and does not change Cloudflare.

```bash
ddev craft disable-cf-ligagate/proxy/check --dry-run
```

Short alias:

```bash
ddev craft disable-cf-ligagate/proxy/check -d
```

`--dry-run` cannot be combined with `--queue` because the result is meant to be printed immediately.

Dry run output includes resolver diagnostics:

- resolver strategy
- blocked IPs found in the status URL
- server-resolved IPs for the configured hostnames, when using `Disable if match exact IP`
- matched IPs, when using `Disable if match exact IP`
- current Cloudflare `proxied` state for each checked DNS record
- target `proxied` state and whether each record would change

### Enqueue A Check

Pushes a check job into Craft Queue instead of executing it inline.

```bash
ddev craft disable-cf-ligagate/proxy/check --queue
```

Short alias:

```bash
ddev craft disable-cf-ligagate/proxy/check -q
```

Queue workers must be running separately for the job to execute.

### Force Disable Proxy

Useful for testing. Disables the proxy for configured Cloudflare DNS records without consulting the blocked-IP status URL.

```bash
ddev craft disable-cf-ligagate/proxy/disable
```

### Force Enable Proxy

Useful for testing. Restores only records that were disabled by the plugin and whose original state was proxied.

```bash
ddev craft disable-cf-ligagate/proxy/enable
```

## Periodic Execution

The plugin does not include an internal scheduler. Use cron, systemd timers, or your hosting scheduler.

Recommended setup: run the check every 1 or 2 minutes. A 2-minute interval is a good default because the check is lightweight and still reacts quickly.

Example cron using DDEV:

```cron
*/2 * * * * cd /path/to/project && ddev craft disable-cf-ligagate/proxy/check >/dev/null 2>&1
```

Example cron without DDEV:

```cron
*/2 * * * * cd /path/to/project && ./craft disable-cf-ligagate/proxy/check >/dev/null 2>&1
```

If you prefer to enqueue the work, schedule the queued command:

```cron
*/2 * * * * cd /path/to/project && ddev craft disable-cf-ligagate/proxy/check --queue >/dev/null 2>&1
```

Then make sure a queue runner is active, for example:

```bash
ddev craft queue/listen
```

or run queue processing from your process manager/hosting platform.

## State And Manual Changes

The plugin stores state per Cloudflare DNS record.

It tracks:

- hostname
- Cloudflare record ID
- last known `proxied` state
- whether the plugin disabled the record
- original `proxied` state before the plugin changed it
- last check/change timestamps
- last error

Restoration is conservative:

- If the plugin disabled a record that was originally proxied, it can re-enable it when the block ends.
- If a record was already unproxied manually, the plugin will not turn it back on.
- If someone manually re-enables a record while the plugin thinks it disabled it, the next restore pass clears the plugin-disabled state.

This conservative behavior applies when `Respect Manual Changes` is enabled. During a detected block, all configured records are still checked and unproxied if needed. If `Respect Manual Changes` is disabled, the plugin also enforces the desired proxied state when no block is detected.

If the status URL or Cloudflare API fails, the plugin logs the error and does not intentionally change Cloudflare state for that failing check.
