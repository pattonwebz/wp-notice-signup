import { mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const artifactsDir = join(process.cwd(), "artifacts");
const rawDir = join(artifactsDir, "a11y", "raw");
const outputFile = join(artifactsDir, "axe-results.json");

// Combine the per-page/per-state raw axe-core scans (tests/a11y.spec.ts writes one
// file per target, including the same URL scanned in more than one DOM state — e.g.
// a modal open vs closed) into the single UrlResult[] array axe-report-action expects
// (the same shape axe-scan-action produces), so both demos in this talk hand their
// results to the same report generator.
mkdirSync(rawDir, { recursive: true });

const pages = readdirSync(rawDir)
	.filter((file) => file.endsWith(".json"))
	.sort()
	.flatMap((file) => {
		try {
			return [JSON.parse(readFileSync(join(rawDir, file), "utf8"))];
		} catch (error) {
			console.warn(`Skipping ${file}: could not parse raw axe-core output (${error.message}).`);
			return [];
		}
	});

const results = pages.map((page) => ({
	// axe-report-action renders this string as the row/heading label. Playwright
	// scans the same URL in different states (e.g. "frontend (default state)" vs
	// "frontend (modal open)"), so the descriptive target name — not the raw URL —
	// is what actually distinguishes rows in the report.
	url: `[${page.category ?? "uncategorized"}] ${page.name}`,
	results: { violations: page.violations ?? [] },
}));

writeFileSync(outputFile, `${JSON.stringify(results, null, 2)}\n`);
console.log(`Wrote ${outputFile} (${results.length} page result(s))`);
