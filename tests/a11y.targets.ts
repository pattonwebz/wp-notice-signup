/**
 * Scan target catalogue for the accessibility CI demo.
 *
 * This is the piece you copy-paste-and-edit for your own plugin: add or remove
 * entries here, no need to touch a11y.spec.ts. Three categories are tracked so
 * reporting can distinguish "did the site break" from "did the plugin's admin
 * screen break":
 *
 * - "core":  Stock WordPress screens (login, dashboard) with no plugin markup.
 *            Useful as a baseline / to catch host-theme regressions.
 * - "site":  Front-of-site pages a visitor reaches - both WordPress-native
 *            content (home, single post, archive, search, 404) and pages
 *            where the plugin injects markup. Includes multiple DOM states
 *            for the same page where a static load wouldn't reveal the
 *            surface that actually matters (e.g. a closed modal never shows
 *            its own violations).
 * - "admin": Authenticated wp-admin screens. `wp-notice-signup` is the worked
 *            example; point additional entries at your own plugin's admin
 *            page(s) by path + capability using the same shape.
 */

export type TargetCategory = "core" | "site" | "admin";

export interface ScanTarget {
  /** Human-readable label used in test names and reports. */
  name: string;
  category: TargetCategory;
  /** Path relative to WORDPRESS_BASE_URL. */
  path: string;
  /** Whether the scan must sign in as an admin user first. */
  auth?: boolean;
  /** CSS selectors to scope the axe-core scan to (defaults to full page). */
  includes?: string[];
  /**
   * CSS selectors to exclude from the scan.
   *
   * Use sparingly, and only for markup you neither own nor control — a widget
   * that renders remote content, say. Excluding your own markup because it
   * fails is how a gate stops meaning anything.
   */
  excludes?: string[];
  /** HTTP status the navigation should return before scanning. Defaults to 200. */
  expectedStatus?: number;
  /**
   * Optional interaction sequence to run after navigation and before scanning,
   * keyed into the registry in a11y.interactions.ts. Use this for states axe
   * can't see on first paint: opened modals, submitted forms, dismissed
   * notices. Interactions run in listed order.
   */
  states?: string[];
}

const scanPlugin = process.env.SCAN_PLUGIN === "1";

/**
 * Pages belonging to the HOST SITE, supplied as a comma-separated list of paths.
 *
 * This plugin does not own the site it runs on, so it cannot know what pages
 * exist there. Locally that might be a bare WordPress with nothing but a home
 * page; on staging it is somebody's real site with a real page structure.
 *
 *   SITE_PAGES=/features/,/pricing/,/status/
 *
 * Unset means "scan only what the plugin can rely on existing", which is the
 * right default: a target that 404s is a broken test, not a finding.
 *
 * Why bother at all? Because the interesting failures are the ones a plugin
 * causes on pages it did not write. A component hooked to wp_footer renders
 * everywhere, so scanning only the plugin's own screens would miss it entirely.
 */
const sitePages = ( process.env.SITE_PAGES ?? "" )
  .split( "," )
  .map( ( path ) => path.trim() )
  .filter( ( path ) => path.length > 0 );

const hostPageTargets: ScanTarget[] = sitePages.map( ( path ) => ( {
  name: `Host page ${ path }`,
  category: "site",
  path,
} ) );

/**
 * Targets that only exist while this plugin is active.
 *
 * Kept separate and off by default so a scan of the host site alone does not
 * fail merely because the plugin is deactivated. Set SCAN_PLUGIN=1 to include.
 */
const pluginTargets: ScanTarget[] = [
  {
    name: "WP Notice Signup frontend (default state)",
    category: "site",
    path: "/",
    includes: [ ".wpns-banner", ".wpns-modal-shell" ],
  },
  {
    name: "WP Notice Signup frontend (modal open)",
    category: "site",
    path: "/",
    includes: [ ".wpns-modal-shell" ],
    states: [ "openBannerModal" ],
  },
  {
    name: "WP Notice Signup settings",
    category: "admin",
    path: "/wp-admin/admin.php?page=wp-notice-signup",
    auth: true,
    includes: [ ".wpns-admin-page" ],
  },
];

export const scanTargets: ScanTarget[] = [
  // --- Stock WordPress screens ---
  // No plugin markup. A baseline, and proof the harness can reach an
  // authenticated screen at all.
  { name: "WordPress login screen", category: "core", path: "/wp-login.php" },
  {
    name: "WordPress admin dashboard",
    category: "admin",
    path: "/wp-admin/index.php",
    auth: true,
    // The "Events and News" widget renders content fetched live from
    // wordpress.org, so what it contains — and whether it passes — changes
    // between runs. Core's markup around somebody else's content, and neither
    // is ours to fix. Left in, this target is flaky, and a gate that fails at
    // random gets ignored.
    excludes: [ "#dashboard_primary" ],
  },

  // --- The one page every WordPress site has ---
  { name: "Home", category: "site", path: "/" },

  // --- Whatever the host site actually has, via SITE_PAGES ---
  ...hostPageTargets,

  // --- This plugin's own surfaces, via SCAN_PLUGIN=1 ---
  ...( scanPlugin ? pluginTargets : [] ),
];
