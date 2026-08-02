import { spawnSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

// Runs the *actual* pattonwebz/axe-report-action code (installed as a normal git
// devDependency, dist/ already built and committed there) the same way the GitHub
// Actions runner would: as a plain Node script driven by INPUT_* env vars, writing
// to a GITHUB_STEP_SUMMARY file. No GitHub Actions runtime, no CI minutes — same
// code path as CI, running for free on your machine.
const root = dirname( dirname( fileURLToPath( import.meta.url ) ) );

const resultsFile = process.env.RESULTS_FILE ?? "artifacts/axe-results.json";
const failOn = process.env.FAIL_ON ?? "serious";
const showPersonas = process.env.SHOW_PERSONAS ?? "true";
const summaryFile = join( root, "artifacts", "a11y-summary.md" );

mkdirSync( dirname( summaryFile ), { recursive: true } );
writeFileSync( summaryFile, "" );

const actionEntry = join( root, "node_modules", "axe-report-action", "dist", "index.js" );

const env = {
	...process.env,
	"INPUT_RESULTS-FILE": resultsFile,
	"INPUT_FAIL-ON": failOn,
	"INPUT_SHOW-PERSONAS": showPersonas,
	GITHUB_STEP_SUMMARY: summaryFile,
};

const result = spawnSync( "node", [ actionEntry ], { cwd: root, env, stdio: "inherit" } );

console.log( `\nWrote ${ summaryFile }` );
process.exit( result.status ?? 0 );
