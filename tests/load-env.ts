import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

/**
 * Minimal .env loader.
 *
 * Deliberately not `dotenv`: this needs to work in CI, offline, and on a
 * machine short of disk space, and it is about twenty lines. Adding a
 * dependency to read key=value pairs is not a good trade.
 *
 * Precedence is the important part. Real environment variables always win over
 * the file, because CI injects its target and secrets that way — a stray .env
 * committed or left on a runner must never be able to redirect a CI scan at
 * somebody's laptop.
 *
 * Local:  .env supplies WORDPRESS_BASE_URL and admin credentials
 * CI:     repository secrets are injected as env vars; no .env exists
 */
export function loadEnv(file = ".env"): void {
  const path = resolve(process.cwd(), file);

  if (!existsSync(path)) {
    return;
  }

  for (const rawLine of readFileSync(path, "utf8").split("\n")) {
    const line = rawLine.trim();

    if (line === "" || line.startsWith("#")) {
      continue;
    }

    const eq = line.indexOf("=");
    if (eq === -1) {
      continue;
    }

    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();

    // Strip one layer of matching quotes, so values containing '#' or spaces
    // can be quoted in the file.
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    // Never override something already set in the real environment.
    if (process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}

/**
 * Resolve the site to scan, and fail loudly rather than silently defaulting.
 *
 * A scan that quietly falls back to localhost when the staging URL is missing
 * is worse than a scan that fails: it reports a green run against the wrong
 * site, and a green run against the wrong site is how a gate stops meaning
 * anything.
 */
export function resolveBaseUrl(): string {
  const url = process.env.WORDPRESS_BASE_URL;

  if (!url) {
    throw new Error(
      "WORDPRESS_BASE_URL is not set.\n" +
        "  Local: copy .env.example to .env and set it.\n" +
        "  CI:    inject it from repository secrets.\n" +
        "Refusing to guess — a scan against the wrong site is worse than no scan."
    );
  }

  return url.replace(/\/$/, "");
}
