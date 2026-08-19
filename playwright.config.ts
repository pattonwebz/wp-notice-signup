import { defineConfig } from "@playwright/test";
import { loadEnv, resolveBaseUrl } from "./tests/load-env";

// Local runs read .env; CI injects the same variables as real environment
// variables, which always take precedence. See tests/load-env.ts.
loadEnv();

// Note there is no fallback to localhost. This used to default silently, which
// meant a missing staging URL produced a green scan of whatever happened to be
// on port 8080 — a pass that proves nothing about the site you meant to check.
const baseURL = resolveBaseUrl();

export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
  timeout: 60_000,
  expect: {
    timeout: 10_000
  },
  reporter: [
    ["list"],
    ["json", { outputFile: "artifacts/playwright-results.json" }],
    ["html", { outputFolder: "artifacts/playwright-report", open: "never" }]
  ],
  use: {
    baseURL,
    // CI uses the runner's preinstalled Google Chrome (channel: "chrome") so
    // no browser download or apt OS-deps install is needed — the GitHub-hosted
    // runner's apt mirrors are too slow/flaky. Local runs keep the bundled
    // Chromium unless PLAYWRIGHT_USE_SYSTEM_CHROME is set.
    browserName: "chromium",
    channel: process.env.PLAYWRIGHT_USE_SYSTEM_CHROME ? "chrome" : undefined,
    headless: true,
    // Video and trace need Playwright's bundled ffmpeg, which we don't install
    // when using the system Chrome — turn them off in that mode. Screenshots
    // still capture failures, and the axe report is the real artifact.
    trace: process.env.PLAYWRIGHT_USE_SYSTEM_CHROME ? "off" : "on-first-retry",
    screenshot: "only-on-failure",
    video: process.env.PLAYWRIGHT_USE_SYSTEM_CHROME ? "off" : "retain-on-failure"
  }
});
