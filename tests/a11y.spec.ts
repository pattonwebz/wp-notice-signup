import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { scanTargets } from "./a11y.targets";
import { interactions } from "./a11y.interactions";

const rawOutputDir = join(process.cwd(), "artifacts", "a11y", "raw");
const adminUser = process.env.WP_ADMIN_USER ?? "admin";
const adminPassword = process.env.WP_ADMIN_PASSWORD ?? "password";
mkdirSync(rawOutputDir, { recursive: true });

function slugify(value: string) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

function normalizeViolations(violations: any[]) {
  return violations.map((violation) => ({
    id: violation.id,
    impact: violation.impact,
    description: violation.description,
    help: violation.help,
    helpUrl: violation.helpUrl,
    tags: violation.tags,
    nodes: violation.nodes.map((node: any) => ({
      target: node.target,
      html: node.html,
      failureSummary: node.failureSummary
    }))
  }));
}

function formatViolations(violations: ReturnType<typeof normalizeViolations>) {
  if (violations.length === 0) {
    return "No accessibility violations found.";
  }

  return violations
    .map((violation) => {
      const firstTarget = violation.nodes[0]?.target?.join(", ") ?? "unknown target";
      return `- ${violation.id} (${violation.impact ?? "unknown"}) on ${firstTarget}`;
    })
    .join("\n");
}

async function signInToWordPress(page: Page) {
  await page.goto("/wp-login.php", { waitUntil: "networkidle" });
  await page.getByLabel("Username or Email Address").fill(adminUser);
  await page.locator("#user_pass").fill(adminPassword);
  await page.getByRole("button", { name: "Log In" }).click();
  await page.waitForURL(/wp-admin/);
}

test.describe("WordPress accessibility smoke checks", () => {
  for (const target of scanTargets) {
    test(`[${target.category}] ${target.name} has no automated accessibility violations`, async ({ page }) => {
      if (target.auth) {
        await signInToWordPress(page);
      }

      const response = await page.goto(target.path, { waitUntil: "networkidle" });
      const expectedStatus = target.expectedStatus ?? 200;
      expect(
        response?.status(),
        `Expected ${target.path} to respond ${expectedStatus} before running axe-core.`
      ).toBe(expectedStatus);

      // Interactions run in listed order. A target's `states` array drives
      // which states axe sees — a modal that is never opened never has its
      // markup scanned, so an unlabelled field inside it would sail through.
      for (const state of target.states ?? []) {
        const runInteraction = interactions[state];
        if (!runInteraction) {
          throw new Error(`No interaction registered for state "${state}" (target: ${target.name}).`);
        }
        await runInteraction(page);
      }

      /*
       * Which rules run is a policy decision, not a detail.
       *
       * The default is WCAG 2 A + AA, which is what most teams gate on. It is
       * worth knowing what that excludes:
       *
       *   - `heading-order` is tagged `best-practice`, NOT WCAG. A WCAG-only
       *     scan will never tell you a plugin jumped from h1 to h4.
       *   - `duplicate-id-active` is deprecated and tagged `wcag2a-obsolete`,
       *     because WCAG 2.2 removed SC 4.1.1 (Parsing). Duplicate IDs only
       *     still fail via `duplicate-id-aria`, when they break an ARIA
       *     reference.
       *
       * Set AXE_TAGS to override, e.g. AXE_TAGS=wcag2a,wcag2aa,best-practice
       */
      const tags = (process.env.AXE_TAGS ?? "wcag2a,wcag2aa")
        .split(",")
        .map((tag) => tag.trim())
        .filter(Boolean);

      let builder = new AxeBuilder({ page }).withTags(tags);

      for (const selector of target.includes ?? []) {
        builder = builder.include(selector);
      }

      for (const selector of target.excludes ?? []) {
        builder = builder.exclude(selector);
      }

      const results = await builder.analyze();
      const violations = normalizeViolations(results.violations);
      const report = {
        name: target.name,
        category: target.category,
        path: target.path,
        url: page.url(),
        violationCount: violations.length,
        violations
      };

      writeFileSync(
        join(rawOutputDir, `${target.category}--${slugify(target.name)}.json`),
        JSON.stringify(report, null, 2)
      );

      expect(violations, formatViolations(violations)).toEqual([]);
    });
  }
});
