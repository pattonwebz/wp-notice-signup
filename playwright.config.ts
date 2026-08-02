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
    browserName: "chromium",
    headless: true,
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    video: "retain-on-failure"
  }
});
