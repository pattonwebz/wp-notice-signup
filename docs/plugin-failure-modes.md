# wp-notice-signup: the failure modes

How the demo plugin breaks, on purpose, and why each break is worth showing.

## The split, and why it matters

Two plugins, with different jobs:

| Part | Role | State |
| --- | --- | --- |
| `theme/corveto` + `content/*.html` | The Corveto site: a child theme and ordinary pages built from core blocks | **Clean.** Verified across all six pages |
| `plugin/wp-notice-signup` | The product plugin under test | **Where the failures live** |

This split is what makes the talk's argument literal rather than metaphorical. The site was
accessible. A plugin shipped into it. Now it is not. That is the actual situation for the plugin
developers in the audience — they are the ones shipping into somebody else's accessible site.

It also means the report can say *which* of the two broke something, instead of "the site has
violations."

## Two surfaces, and why both matter

A plugin can fail in two places, and teams routinely test only the first:

1. **The front end**, which visitors see.
2. **The plugin's own admin screen**, which only logged-in administrators see.

The second is the one that gets skipped, and skipping it is a choice with consequences. WordPress
administrators are users. Some of them use screen readers. A settings page that cannot be operated
without a mouse is not a lesser bug because the audience is smaller — it is a bug that locks a
specific person out of doing their job.

Testing the admin surface also requires something a naive scanner cannot do: **authenticate first**.
That is the practical reason it gets skipped, and it is why `tests/a11y.spec.ts` supports an `auth`
flag per target. Worth saying out loud during the talk: if your accessibility tooling cannot log in,
half your plugin is untested.

## Baseline status: clean, verified

As of 2026-08-01 the plugin scans at **zero violations**, verified against real WordPress:
**16/16 targets passing**, including the modal-open state and the plugin's own admin settings
screen. `SCAN_PLUGIN=1 npm run test:a11y`.

What was fixed to get there — each of these is a failure mode that can now be switched back on
deliberately for a demo:

| Surface | Was | Now |
| --- | --- | --- |
| Banner | `<img>` with no `alt` | Decorative illustration with `alt=""` |
| Banner | `#b6bdc8` on `#f6f7fb` — **1.7:1** | `#1f2734` on white — 14.4:1 |
| Banner | Button `#99a1af` on `#dce2ee` — **1.9:1** | White on `#1f4b8f` — 8.6:1 |
| Banner | `<h4>` injected into every page's outline | Landmark with `aria-label`, no heading |
| Modal | `aria-hidden="true"` with focusable children | `hidden` attribute; out of the tree *and* tab order |
| Modal | Parked at `left:-9999px`, still tabbable | Genuinely hidden when closed |
| Modal | Close button was an unlabelled `×` | Visually hidden "Close" text |
| Modal | No focus management at all | Focus moves in, is trapped, Escape closes, focus returns |
| Forms | `<span class="wpns-field-caption">` posing as a label | Real `<label for>` |
| Forms | Same IDs in modal and inline copies | IDs suffixed per rendered instance |
| Admin | `<h1>` → `<h3>` → `<h5>` | `<h1>` → `<h2>` → `<h3>` |
| Admin | Preview `<img>` with no `alt` | `alt=""`, documented as a decision |
| Admin | Icon-only `⚙` button, no accessible name | Visually hidden label text |
| Admin | `#8f96a3` on white — **2.8:1** | `#45505f` — 8.3:1 |
| Admin | `#b3bac5` on `#eff3f8` — **1.7:1** | `#404a58` on `#f4f7fb` — 8.9:1 |

Also removed: the plugin no longer renders the marketing site. `class-wp-notice-signup-site-pages.php`
and `site-mockup.css` are gone, and the `northstar_marketing_page` shortcode with them. The site is
now an ordinary WordPress site — a child theme plus pages built from core blocks — which is what
makes "the site is fine, the plugin broke it" a statement the report can actually support.

**The focus behaviour is worth demonstrating by hand.** `hidden` vs `aria-hidden` is the only part
of the modal fix axe can see. Focus moving into the dialog, staying inside it, and returning to the
opener on close are all invisible to the scanner — verified here by driving the keyboard directly,
not by the passing test.

## Existing machinery

`get_demo_issue_flags()` in `includes/class-wp-notice-signup-plugin.php` already gates the
deliberate failures behind named flags, filterable via `wp_notice_signup_demo_issue_flags`:

```php
'missing_labels'       => false,
'color_contrast'       => false,
'missing_alt_text'     => false,
'heading_order'        => false,
'button_name'          => false,
'aria_hidden_focus'    => false,
'duplicate_active_ids' => false,
'icon_banner_cta'      => false,
'empty_submit'         => false,
'select_name'          => false,
```

**All of these now default to `false`, and must stay that way.** The baseline has to be clean, or
the gate can never be shown blocking anything — a red build that was already red proves nothing. The
flags remain as the mechanism for turning individual failures on, one demo at a time.

Each flag is wired: turning one on reintroduces exactly that failure, in markup, CSS and (for the
modal) JavaScript. Verified one at a time against the real scanner — see the table below for which
ones actually produce a violation, because **not all of them do**.

### Switching one on

Edit the defaults in a pull request, or filter them:

```php
add_filter( 'wp_notice_signup_demo_issue_flags', function ( $flags ) {
	$flags['color_contrast'] = true;
	return $flags;
} );
```

Turn on **one at a time**. With several on together they mask each other: `aria_hidden_focus` puts
the modal behind `aria-hidden`, and axe then stops evaluating everything inside it, so the label and
contrast failures in the modal form silently vanish from the report. A demo that shows fewer
violations when you break more things is not a demo.

### What each flag actually produces

Verified individually against WordPress, not assumed:

| Flag | Result | Where |
| --- | --- | --- |
| `color_contrast` | `color-contrast` (serious) — 48 nodes across 13 pages | Front end + admin |
| `missing_alt_text` | `image-alt` (critical) — 13 nodes across 13 pages | Front end + admin |
| `missing_labels` | `label` (critical) | Contact page, admin |
| `button_name` | `button-name` (critical) | Admin settings screen |
| `aria_hidden_focus` | `aria-hidden-focus` (serious) | Every front-end page |
| `icon_banner_cta` | `button-name` (critical) | Front-end banner CTA |
| `empty_submit` | `button-name` (critical) | Front-end + modal submit |
| `select_name` | `select-name` (serious) | Front-end + modal frequency dropdown |
| `heading_order` | **Nothing under the default ruleset.** `heading-order` (moderate) only with `AXE_TAGS=wcag2a,wcag2aa,best-practice` | Admin settings screen |
| `duplicate_active_ids` | **Nothing.** Real bug, undetectable — see below | Contact page |

## What the ruleset does and does not include

This came out of wiring the flags up, and it is worth a slide of its own.

The scan runs `wcag2a` + `wcag2aa` tags by default — what most teams gate on. Two of the failure
modes in this plugin are invisible to it:

**`heading-order` is tagged `best-practice`, not WCAG.** A WCAG-only scan will never tell you a
plugin jumped from `h1` to `h4`. Override with `AXE_TAGS=wcag2a,wcag2aa,best-practice` to include it.

**Duplicate IDs are no longer a WCAG failure.** `duplicate-id-active` is deprecated in axe-core and
tagged `wcag2a-obsolete`, because **WCAG 2.2 removed SC 4.1.1 (Parsing)**. Only `duplicate-id-aria`
still fails, and only when the duplicate breaks an ARIA reference between two elements that are both
exposed — with one of them hidden, axe returns "needs review" rather than a violation.

So the duplicate-ID bug in this plugin is real (clicking the inline form's label focuses the modal's
field instead) and a modern WCAG-tagged scan reports nothing at all. That belongs next to the
placeholder case in the honesty beat: **the standard moved, and the tool followed it.**

### One our own scan caught

Running with `best-practice` tags surfaced `landmark-unique` on the contact page: the sitewide banner
and the inline signup form both took their landmark name from the same setting, so a screen reader's
landmark list showed two identically named regions. Fixed, even though the default gate does not
catch it. Everything still failing under `best-practice` is WordPress core's own markup — the login
screen (`region`, `landmark-one-main`) and the admin dashboard (`aria-allowed-role`).

---

## Front-end failure modes

### F1. The sitewide bleed — `wp_footer` with no page check

**Rule:** varies — whatever the banner markup contains
**Impact:** multiplied across every page
**Realism:** very high. This is the single most common way a plugin damages a site it did not write.

`render_frontend_experience()` is hooked to `wp_footer` with no conditional. The notice banner and
modal therefore render on *every* front-end page — home, features, pricing, status, about, contact,
blog, single post, category archive, search, 404. One broken component becomes eleven broken pages.

**Why it is the best beat in the talk:** the report goes from 0 violations to the same handful
repeated across every page in the site. The lesson lands as a live finding rather than a caveat:
*a footer hook touches everything, so scanning only your own plugin's page would have missed this
entirely.*

**Use it for:** the PR-merge demo. It is visual, immediate, and the count on screen does the arguing.

### F2. Contrast on the banner

**Rule:** `color-contrast` · **Impact:** serious

The announcement bar's text colour is set for the plugin author's own dark demo theme and fails
against Corveto's light background. Contrast around 2:1.

**Realism:** high, and specifically a *plugin* problem — the plugin cannot see the host theme's
colours. Good for making the point that "it looked fine on my site" is not a defence.

### F3. The modal's focus trap and `aria-hidden`

**Rules:** `aria-hidden-focus`, plus keyboard behaviour axe cannot see · **Impact:** serious

The signup modal is hidden with `aria-hidden="true"` while its focusable children keep their tab
stops. Keyboard users tab into a dialog that is not visible; screen reader users are told nothing is
there while focus lands inside it.

**Realism:** very high. Home-grown modals get this wrong constantly.

**Why it is worth showing even though it is partly automated:** axe catches the `aria-hidden`
conflict, but it cannot tell you focus is never returned to the trigger on close. Use this one to be
honest about the boundary — automation caught half of it, and a person found the rest.

### F4. Placeholder instead of label

**Rule:** `label` — *and it does not fire* · **Impact:** none reported

The email field uses `placeholder="Email address"` with no `<label>`.

**This is the most valuable failure in the set, because the scan passes.** The placeholder satisfies
the accessible-name computation, so axe reports nothing — yet the label vanishes the moment someone
types, and anyone who relies on persistent labelling is left guessing.

**Use it for:** the honesty beat. A green check means "no violations were detected", not "this is
accessible". Any talk that only shows the tool winning is selling something.

### F5. Icon button with no accessible name

**Rule:** `button-name` · **Impact:** critical

The banner's dismiss control is a `<button>` containing only an icon glyph or an SVG marked
`aria-hidden`, so its accessible name is empty. Screen readers announce "button".

**Realism:** very high. Dismiss and close controls are the classic case.

### F6. Non-semantic control

**Rule:** varies · **Impact:** serious

A `<div onclick>` acting as the banner's call to action: not focusable, not announced as a control,
not operable by keyboard or by Enter/Space.

### F7. Icon-only call to action

**Rule:** `button-name` · **Impact:** critical

The banner's primary CTA becomes a bare `<button>` containing only an arrow glyph marked
`aria-hidden`. Screen readers announce "button" with no hint of what it does.

**Realism:** high. "Clean up" of a button to a single glyph happens constantly in marketing banners.

### F8. Empty submit button

**Rule:** `button-name` · **Impact:** critical

The signup form's submit button contains only a decorative arrow. The accessible name is empty, so
assistive tech announces "button" — and a keyboard user cannot tell what submitting will do.

**Realism:** high. Icon-only buttons with no text or visually-hidden label are a classic.

### F9. Unlabelled select

**Rule:** `select-name` · **Impact:** serious

A "How often" frequency dropdown is labelled with a `<span>` that looks like a label but is not
one. The select has no accessible name and cannot be focused by clicking its caption.

**Realism:** very high. Same caption/label confusion as A1, on a `<select>` — a control axe checks
with its own `select-name` rule.

---

## Admin-screen failure modes

All of these are on `wp-admin/admin.php?page=wp-notice-signup`, which requires authentication — the
scan target carries `auth: true`.

### A1. Fields labelled with a `<span>`

**Rule:** `label` · **Impact:** critical

Two fields on the settings form already do this today:

```php
<span class="wpns-field-caption">Button text</span>
<input id="wpns_button_text" type="text" ...>
```

It *looks* labelled. It is not: there is no `<label for>`, so the input has no accessible name and
clicking the caption does not focus the field.

**Realism:** very high, and it is a caption/label confusion that survives code review easily because
it renders identically.

### A2. Heading order jump

**Rule:** `heading-order` · **Impact:** moderate

The admin page goes `<h1>` → `<h3>` (and the preview card uses `<h5>`). Levels are being chosen for
their font size rather than for document structure.

**Realism:** very high in admin screens, where people style by picking whichever heading looks right.

### A3. Preview image with no alt

**Rule:** `image-alt` · **Impact:** critical

```php
<img src="<?php echo esc_url( $settings['image_url'] ); ?>">
```

No `alt` attribute at all.

**Extra teaching value:** the correct fix is not "add alt text". This is a *preview* of a
user-supplied image, so the right answer depends on what the image means in context — and if it is
purely decorative, `alt=""` is correct and an empty alt is not a failure. Good moment to show that
the tool tells you where to look, not what to write.

### A4. Icon-only settings button

**Rule:** `button-name` · **Impact:** critical

```php
<button type="button" class="button button-secondary wpns-icon-button">
    <span aria-hidden="true">⚙</span>
</button>
```

The only content is a glyph hidden from assistive tech. Accessible name: empty.

### A5. Duplicate IDs

**Rule:** `duplicate-id-active` · **Impact:** minor, consequences not minor

The preview panel reuses the IDs from the settings form, so `<label for>` associations resolve to
whichever element comes first. Clicking a label focuses the wrong field.

**Realism:** high wherever a "live preview" duplicates a form.

---

## Which failure goes in which demo

The two gate patterns want different failures. Using the same one twice wastes the second demo.

| Demo | Failure | Why it fits |
| --- | --- | --- |
| **Pattern 1 — block on PR merge** | F1 sitewide bleed + F2 contrast | Visual, instant, and the violation count across eleven pages makes the argument by itself. A reviewer would not have caught it by reading the diff. |
| **Pattern 2 — block on release** | A1 span-labelled field + A4 icon button | Admin-only, invisible from the front end, and exactly the kind of thing that ships in a plugin zip to thousands of sites. Reinforces that the release artifact is what needs gating, not just the source. |
| **Kitchen sink** | Every flag on at once | Front-end damage (contrast, alt, labels, icon-only controls, aria-hidden modal) *and* admin damage (contrast, labels, gear button) in one PR. The report shows the full breadth of what a WCAG-tagged scan can catch across both surfaces. |
| **Neither — narrate only** | F4 placeholder-instead-of-label | The scan passes. Use it for the honesty beat, not as a gate demo. |

Splitting them this way also means the second demo is not "the same red check again": the first
blocks a merge on front-end damage, the second blocks a release on damage only an administrator
would ever see.

## Implementation notes

- Every failure stays behind its flag in `get_demo_issue_flags()`, all defaulting to `false`.
- The demo PR and the demo release each flip exactly the flags they need — the diff is small and
  readable on a projector, which matters when the audience is being asked to believe it is realistic.
- After each demo, the flag goes back to `false` and the baseline is clean again.
- `tests/a11y.targets.ts` already carries `states` for the modal-open case, and `auth: true` for
  admin targets. Both stay: a clean modal and a clean admin screen are still worth scanning, and
  they are what proves the baseline is genuinely green rather than merely unscanned.

## What none of this proves

Worth saying plainly, on a slide:

- axe-core finds roughly a third of WCAG issues in practice. Everything above is in the automatable
  third *except* F3's focus-return and F4's placeholder problem, which are in there deliberately.
- A green gate means no *detected* violations on the *scanned* states of the *scanned* pages.
- Nothing here tests with an actual screen reader, and nothing here asks a disabled person whether
  the result is usable. The gate is a floor, not a ceiling.
