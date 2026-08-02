# How the accessibility gate works

This repo runs accessibility checks in three different places, for three different reasons. This
document explains what each one does, what actually blocks what, and how to set it up.

The short version: **a pull request can't be merged until the branch has been deployed to staging
and scanned there. Merging is what deploys to production.**

---

## The flow

```
  ┌─────────────────────────────────────────────────────────────────────┐
  │  You, locally                                                       │
  │  docker compose up  →  npm run test:a11y                            │
  │  Ephemeral WordPress in Docker. Fast, private, blocks nothing.      │
  └─────────────────────────────────────────────────────────────────────┘
                                    │
                                    │  git push
                                    ▼
  ┌─────────────────────────────────────────────────────────────────────┐
  │  a11y-check.yml            on: push, pull_request                   │
  │  Boots the same Docker WordPress on a runner and scans it.          │
  │  Tells you early. Independent of any server.                        │
  └─────────────────────────────────────────────────────────────────────┘
                                    │
                                    │  open a pull request
                                    ▼
  ┌─────────────────────────────────────────────────────────────────────┐
  │  a11y-staging-gate.yml     on: pull_request                         │
  │                                                                     │
  │    deploy-staging   rsync this branch's plugin → staging server     │
  │           ↓                                                         │
  │    scan-staging     run the same suite against the real staging URL │
  │                                                                     │
  │  Job name "Scan staging" is a REQUIRED status check on main.        │
  └─────────────────────────────────────────────────────────────────────┘
                    │                                 │
            scan fails                          scan passes
                    │                                 │
                    ▼                                 ▼
        ┌───────────────────────┐        ┌───────────────────────────────┐
        │ Merge button disabled │        │ Merge allowed                 │
        │ Nothing ships.        │        │            │                  │
        └───────────────────────┘        └────────────┼──────────────────┘
                                                      │  merge to main
                                                      ▼
  ┌─────────────────────────────────────────────────────────────────────┐
  │  a11y-deploy-production.yml    on: push to main                     │
  │  rsync plugin → production, wait for it to come back up.            │
  │  No scan here — it already happened, on staging, before the merge.  │
  └─────────────────────────────────────────────────────────────────────┘
```

---

## The three workflows

| File | Trigger | Scans | Blocks anything? |
|---|---|---|---|
| `a11y-check.yml` | every push and PR | ephemeral Docker WordPress on the runner | Fails the run. Make it a required check too if you want it to block. |
| `a11y-staging-gate.yml` | pull requests targeting `main` | the **real staging site**, after deploying the branch to it | Yes — via branch protection. This is the merge gate. |
| `a11y-deploy-production.yml` | push to `main` (i.e. a merge) | nothing | No. By the time it runs, the gate has already been passed. |

### Why two scans

`a11y-check.yml` scans a container: clean WordPress, your plugin, nothing else. It's fast, it runs
on every push, and it catches your own regressions before anyone reviews the PR.

`a11y-staging-gate.yml` scans a real server: your theme, your content, your other plugins, your
caching layer, your CDN. That combination is what your users actually get, and it's the thing a
clean container can't tell you about. It's slower and it needs infrastructure, which is why it runs
on pull requests rather than every push.

### Why production isn't scanned

Because it's too late to be useful. If a scan on production fails, the broken code is already
serving real users — you'd be finding out, not preventing. The check that matters happens while the
change is still a pull request, on a server that's shaped like production but isn't.

---

## What actually blocks the merge

**The workflow does not block anything. Branch protection does.** This trips people up constantly:
a failing workflow just puts a red X on the PR, and anyone can merge past a red X unless you've
told GitHub not to let them.

Set it up once, after the workflow has run at least one time (GitHub only offers checks it has
seen):

1. **Settings → Branches → Add branch protection rule**
2. Branch name pattern: `main`
3. Tick **Require status checks to pass before merging**
4. In the search box, select **`Scan staging`** — this is the job's `name:`, not the file name
5. Optionally tick **Require branches to be up to date before merging** so a PR is re-scanned when
   `main` moves underneath it
6. Save

To prove it works, open a PR that introduces a violation. The check goes red and the merge button
greys out with "Required statuses must pass before merging".

---

## Setting it up

### 1. Two servers

You need staging and production, each with WordPress already installed, the plugin directory
writable over SSH, and the plugin already activated. **The deploy only pushes plugin files** — it
does not install WordPress, create pages, or seed content. Run
`scripts/bootstrap-wp.sh`'s equivalent by hand on each server once, or set the sites up however
you normally would.

Staging should be as close to production as you can make it — same theme, same plugins, comparable
content. The whole value of this gate is that staging tells you something the container can't, and
that value drops the further staging drifts from production.

### 2. Deploy keys

Generate a **separate** key pair per environment:

```bash
ssh-keygen -t ed25519 -f deploy_staging -N "" -C "a11y-gate staging"
ssh-keygen -t ed25519 -f deploy_production -N "" -C "a11y-gate production"
```

Put each `.pub` in the matching server's `~/.ssh/authorized_keys`, and the private key in the
matching GitHub secret. Don't reuse one key for both — the point of separate environments is that
compromising one doesn't hand over the other.

### 3. Secrets

**Staging** (used by `a11y-staging-gate.yml`):

| Secret | Example |
|---|---|
| `DEPLOY_SSH_HOST` | `staging.example.com` |
| `DEPLOY_SSH_USER` | `deploy` |
| `DEPLOY_SSH_PRIVATE_KEY` | contents of `deploy_staging` |
| `DEPLOY_REMOTE_PATH` | `/var/www/html/wp-content/plugins/wp-notice-signup/` |
| `DEPLOY_BASE_URL` | `https://staging.example.com` |
| `DEPLOY_WP_ADMIN_USER` / `DEPLOY_WP_ADMIN_PASSWORD` | admin login on staging |
| `DEPLOY_SSH_KNOWN_HOSTS` | output of `ssh-keyscan -H staging.example.com` (optional, recommended) |

**Production** (used by `a11y-deploy-production.yml`):

| Secret | Example |
|---|---|
| `PROD_SSH_HOST` | `www.example.com` |
| `PROD_SSH_USER` | `deploy` |
| `PROD_SSH_PRIVATE_KEY` | contents of `deploy_production` |
| `PROD_REMOTE_PATH` | `/var/www/html/wp-content/plugins/wp-notice-signup/` |
| `PROD_BASE_URL` | `https://www.example.com` |
| `PROD_SSH_KNOWN_HOSTS` | output of `ssh-keyscan -H www.example.com` (optional, recommended) |

Admin credentials are only needed for staging, because only the staging scan signs in — the suite's
`admin` category targets require a logged-in session.

### 4. First run

Deploy paths are the dangerous part. `rsync --delete` makes the remote directory an exact mirror of
`plugin/wp-notice-signup/`, so a `*_REMOTE_PATH` pointing one level too high will delete things you
care about. Both workflows refuse to run if the path secret is empty or not absolute, but neither
can tell that an absolute path is the *wrong* absolute path.

**The first time you point this at a real server, add `--dry-run` to the rsync line, run it
manually via `workflow_dispatch`, and read the output.** Then remove it.

---

## What the gate does and doesn't cover

The gate is exactly as good as the scan behind it. Two limits worth knowing before you tell your
team accessibility is now enforced:

**It only looks at what you list.** `tests/a11y.targets.ts` is the full universe of what gets
scanned. A page nobody added is a page this gate will happily promote. Adding a target is one line;
the habit of adding it is the hard part.

**It only runs the rules you asked for.** The suite scans `wcag2a` and `wcag2aa` tags (see
`tests/a11y.spec.ts`). That deliberately excludes:

- **best-practice** rules — `heading-order`, `region`, `empty-table-header`. Real problems, not WCAG
  failures.
- **experimental** rules — including `td-has-header`, which is tagged `wcag2a`/`1.3.1` but is off by
  default. This repo's own pricing page has a table with `<td>` column headers that the suite
  reports nothing about, on purpose. Run it with `.withRules(["td-has-header"])` and it fails
  instantly.

`fail-on: serious` in the report step sets the severity floor — `moderate` and `minor` findings show
up in the summary without failing the job. Raise or lower it to match what your team will actually
act on.

A green gate means "the rules I enabled found nothing on the pages I listed". That is a genuinely
useful sentence. It is not "this site is accessible", and nobody should leave the room thinking it
is.

---

## Known limitations

**Fork pull requests don't get secrets.** GitHub withholds secrets from `pull_request` runs
originating in forks, so the deploy would fail with confusing SSH errors. The gate job is guarded
with an `if:` that skips fork PRs outright. If you accept contributions from forks, you need a
maintainer to push the branch to the main repo before the gate can run — or a
`pull_request_target` setup, which has its own security tradeoffs and is not what this repo does.

**One staging site, one PR at a time.** Both deploy workflows use `concurrency` groups so runs
queue instead of overlapping. Without that, two PRs rsync over each other and both get scanned
against whichever deploy landed last — a false pass, which is worse than no gate. If your team has
more PR traffic than one staging site can serialise, you need per-PR environments, which is a
different (and much bigger) piece of infrastructure.

**Staging drift.** The gate's value depends on staging resembling production. When they diverge,
the gate keeps passing while production breaks in ways staging never sees.

**Nothing here rolls back.** If production deploys and something is wrong, the fix is another PR
through the same gate.

---

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Merge button still enabled with a red check | Branch protection not configured, or `Scan staging` not ticked as required |
| `Missing required secret(s)` | Named secret is unset or empty in repo settings |
| `must be an absolute path` | `*_REMOTE_PATH` is relative — it needs to start with `/` |
| SSH permission denied | Public key not in the server's `authorized_keys`, or wrong `*_SSH_USER` |
| Host key verification failed | `*_SSH_KNOWN_HOSTS` is set but stale — regenerate with `ssh-keyscan -H` |
| Scan fails but the site looks fine | Check `DEPLOY_BASE_URL` points at staging, not production or localhost |
| Staging scan passes, container scan fails | Real difference between the two environments — usually a theme or another plugin. This is the gate earning its keep. |
| Deploy job skipped on a PR | The PR is from a fork; see limitations above |

---

# Update, 2026-08-01

Two things changed: there is now a **second gate pattern**, and the deploy transport is no longer
hardcoded to rsync.

## Pattern 2: block on release

`a11y-release-gate.yml` — triggered by pushing a `v*` tag.

```
  push tag v0.3.0
        │
        ▼
  Build plugin zip          ← the artifact, built once
        │
        ▼
  Release Accessibility Gate
        │   install THAT zip on staging, scan it
        │
        ├── fails ─→ no release is published. The tag exists; nothing ships.
        │
        ▼ passes
  Publish release           ← attaches the same zip that was scanned
```

**Why this is not the same check as the PR gate.** The PR gate scans the *source* before it can be
merged. This scans the *artifact* before it can be handed to anyone. Source can be clean while the
built zip is broken: a file missing from the build, an asset mangled on the way in, a stale vendored
dependency. The only way to know what you are shipping is accessible is to install what you are
shipping and scan that.

The published zip is the same file that was gated — never rebuilt after the scan. Rebuilding would
publish an artifact nothing had tested.

The version in the tag is checked against the plugin header before anything else runs. WordPress
reads the header, so a mismatch means the tag is lying about what it contains.

## Deploy transport: SFTP by default

`scripts/deploy-to-staging.sh` handles both gates, and takes `DEPLOY_TRANSPORT`:

| Value | Uses | When |
| --- | --- | --- |
| `sftp` (default) | `lftp mirror -R --delete` | Deploy account is chrooted and SFTP-only |
| `rsync` | `rsync -avz --delete` | Deploy account has a real shell |

**This was a real incompatibility, not a preference.** rsync needs to start `rsync --server` on the
far end, so it cannot work against an account locked down with `ForceCommand internal-sftp` — which
is exactly what a hardened, chrooted deploy user looks like. It fails there with a confusing
protocol error rather than a clear permission denial. SFTP is the safer default because it works
with the locked-down account; rsync stays available where the account has a shell, since its delta
transfer is faster on large trees.

Set the repository variable `DEPLOY_TRANSPORT` (Settings → Secrets and variables → Actions →
Variables) to `rsync` to switch.

## The theme is deployed too

Staging needs `theme/corveto` as well as `wp-notice-signup`. The theme renders the pages being
scanned; the plugin is the thing under test. Deploying only the plugin meant the scan ran against
whatever version of the site already happened to be on the server.

## What the local Docker run now covers

`a11y-check.yml` sets `SCAN_PLUGIN=1`, so the plugin's own front-end states and its **admin settings
screen** are scanned, not just the public site. Scanning an admin screen needs an authenticated
session, which is the practical reason it usually gets skipped — and why half of a plugin commonly
goes untested.

---

# Findings you did not cause

A plugin does not own the site it is installed into, so its scan will find things that are nobody's
fault but the host's. This is not hypothetical: the first run of this suite against a bare local
WordPress failed on `list` (serious) — Twenty Twenty-Five renders its Navigation block as a nested
`<ul>` when no menu has been defined. Real markup, real violation, entirely the theme's.

That is an awkward and genuine problem, and it deserves an honest answer rather than a shrug.

## Locally, fix the fixture

`scripts/bootstrap-local.sh` replaces the theme's page-list fallback with a real navigation menu. Not
because the theme's bug matters to this plugin, but because **a fixture that starts red teaches you
to ignore red**. If the baseline is dirty, every future run has to be read rather than trusted, and
within a week nobody reads it.

The baseline must be clean so that anything appearing later was caused by the change under test.
That is the entire value of a gate.

## On somebody else's staging site, you have three options

**1. Scope the scan to what you own.** `includes` on each target, limiting axe to your plugin's
selectors. Honest about responsibility — and it silently gives up on the most valuable finding of
all, which is the damage your plugin does to pages it did not write. A `wp_footer` hook renders
everywhere. Scoping hides exactly that.

**2. Gate on your surfaces, report on theirs.** Fail the build only on targets you own, and publish
the host-site findings without blocking. The host's team gets a report they did not have before, and
your release does not hang on their backlog. More wiring, but it is the answer that survives contact
with a real client site.

**3. Fail on everything, and treat host findings as blocking.** Defensible when you also control the
site — an agency running its own client estate, say. Miserable when you do not.

There is no fourth option where the problem disappears. Pick deliberately and write down which one
you picked, because the failure mode of not deciding is that somebody quietly adds `continue-on-error`
six months later and the gate stops meaning anything.

## What this repository does

Local: fixes the fixture, so the baseline is clean.

Staging: scans the host pages listed in `SITE_PAGES` and gates on everything. That is option 3, and
it is only appropriate because the staging site here is a demo fixture that we do control. On a real
client site, option 2 is almost always the right trade.
