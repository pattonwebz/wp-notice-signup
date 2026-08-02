# WP Notice Signup

A small WordPress plugin — an announcement bar, a modal and a signup form — and the accessibility
gating that ships with it.

Built for the WP Accessibility Day talk *WCAG in CI/CD*. The plugin is deliberately ordinary. What
is worth copying is everything around it.

## The idea

**A plugin is responsible for its own accessibility testing.**

It does not own the site it gets installed into. That site has its own theme, its own content and
its own other plugins, and none of that is this repository's business. What this repository can do
is deploy itself into a site, scan what it finds there, and refuse to ship if it made things worse.

That framing decides everything else here:

- The local environment is **bare WordPress** — stock theme, no content, this plugin. Pretending
  otherwise would make it a worse model of production, not a better one.
- Which host pages get scanned comes from `SITE_PAGES`, because the plugin cannot know what pages
  exist on somebody else's site.
- Deploys push **only this plugin**. Never a theme, never content.

## The two gates

### Block on merge — `.github/workflows/a11y-staging-gate.yml`

A pull request deploys its branch to a real staging site and scans it there. The `Scan staging`
check goes red on failure; with a ruleset requiring that check, the merge button is disabled.

**Gates the source.**

### Block on release — `.github/workflows/a11y-release-gate.yml`

Pushing a `v*` tag builds the plugin zip, installs *that artifact* on staging, scans it, and
publishes the GitHub Release only if it passes.

**Gates the artifact.** Source can be clean while the built zip is broken — a file missed by the
build, an asset mangled on the way in, a stale vendored dependency. The published zip is the exact
file that was scanned, never rebuilt afterwards.

Also here: `a11y-check.yml` boots a throwaway WordPress in Docker on the runner and scans it — fast,
needs no server, blocks nothing. And `a11y-deploy-production.yml`, which deploys on merge and
contains no scan, deliberately: the scan already happened while the change was still a pull request.

Full detail: [`docs/how-the-gate-works.md`](docs/how-the-gate-works.md).

## Why Playwright rather than a scan action

Two of the targets cannot simply be fetched:

- **wp-admin needs a login.** The plugin's settings screen is behind authentication, and an
  unauthenticated scanner sees a login form instead. This is the practical reason most setups never
  test their own admin screens.
- **The modal does not exist until something clicks.** axe only ever sees the DOM that is on screen.
  A closed modal has no markup, so it has no violations, so it passes.

Playwright drives a real browser, so it can do both. `@axe-core/playwright` injects axe-core into
the running page. For a site where every page renders on its own, a simpler fetch-and-scan action is
the better tool — that is what the static demo in this talk uses.

## Running it

Requires Node 18+, Docker, and a free port 8080.

```bash
npm install
npx playwright install --with-deps chromium
cp .env.example .env

npm run stack:up      # bare WordPress in Docker
npm run stack:init    # install WP, activate this plugin, pretty permalinks

SCAN_PLUGIN=1 npm run test:a11y
npm run aggregate:a11y
npm run report:a11y
```

| Variable | Purpose |
| --- | --- |
| `WORDPRESS_BASE_URL` | Site to scan. No trailing slash. Required — there is no localhost fallback |
| `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` | For targets marked `auth: true` |
| `SITE_PAGES` | Comma-separated host-site paths, e.g. `/features/,/pricing/` |
| `SCAN_PLUGIN=1` | Include this plugin's own surfaces |
| `AXE_TAGS` | Override the ruleset. Default `wcag2a,wcag2aa` |

The same suite runs against local Docker, staging or production. Only `WORDPRESS_BASE_URL` changes.

## What gets scanned

`tests/a11y.targets.ts`, in three groups:

1. **Stock WordPress** — the login screen and the dashboard. A baseline, and proof the harness can
   reach an authenticated screen at all.
2. **The host site** — `/`, plus whatever `SITE_PAGES` lists. These matter because the interesting
   failures are the ones a plugin causes on pages it did not write: anything hooked to `wp_footer`
   renders on all of them.
3. **This plugin's surfaces** — its front end in more than one state, and its settings screen.
   Behind `SCAN_PLUGIN=1`.

The dashboard target excludes `#dashboard_primary`. That widget renders content fetched live from
wordpress.org, so it passes or fails depending on what it downloaded that day. It is the only
exclusion, and it exists because a gate that fails at random gets ignored.

## Deploying

`scripts/deploy-to-staging.sh` is used by every workflow and takes `DEPLOY_TRANSPORT`:

| Value | Uses | When |
| --- | --- | --- |
| `sftp` (default) | `lftp mirror -R --delete` | Deploy account is chrooted and SFTP-only |
| `rsync` | `rsync -avz --delete` | Deploy account has a real shell |

This is an incompatibility rather than a preference: rsync starts `rsync --server` on the far end,
so it cannot work against an account with `ForceCommand internal-sftp`.

Required secrets are listed at the top of each workflow file.

## The deliberate failures

Every accessibility failure this plugin can exhibit is behind a flag in `get_demo_issue_flags()`,
all defaulting to `false`. The baseline is clean; a demo switches exactly one on.

See [`docs/plugin-failure-modes.md`](docs/plugin-failure-modes.md) for what each one does, which axe
rule it triggers, and — for two of them — why a WCAG-tagged scan reports nothing at all.

## What this does not prove

- axe-core finds roughly a third of WCAG issues. A green gate means no *detected* violations on the
  *scanned* states of the *scanned* pages.
- `heading-order` is tagged `best-practice`, not WCAG — a WCAG-only gate never reports a heading
  jump.
- Duplicate IDs are no longer a WCAG failure: `duplicate-id-active` is deprecated because WCAG 2.2
  removed SC 4.1.1.
- A `placeholder` satisfies the accessible-name computation, so a field labelled only by placeholder
  text passes — and still loses its label the moment somebody types.
- Nothing here tests with a screen reader, and nothing here asks a disabled person whether the
  result is usable. The gate is a floor, not a ceiling.
