/**
 * Remove stale Vite build artefacts from backend/public/assets.
 *
 * WHY THIS EXISTS
 *   The build writes fingerprinted bundles (index-<hash>.js). Because the hash
 *   changes on every build, old files are never overwritten - they just pile
 *   up. backend/public/assets/.htaccess serves them with a one-year immutable
 *   lifetime, so a stale bundle left behind is not merely wasted disk: it stays
 *   publicly fetchable at a URL that looks current.
 *
 *   Vite's own emptyOutDir cannot do this job. It would delete the entire
 *   outDir, including .htaccess, index.php, robots.txt and uploads/.
 *
 * WHAT IT DELETES
 *   Only files matching the Vite fingerprint pattern. Deliberately NOT:
 *     - .htaccess and any dotfile
 *     - uploads/ (user documents, profile images)
 *     - anything without a content hash
 *
 * Safe to run repeatedly; a missing directory is not an error.
 */

import { readdir, unlink } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const ASSETS_DIR = path.resolve(__dirname, '../../backend/public/assets');

// Vite emits <name>-<8+ char hash>.<ext> (e.g. index-CYRJXlOq.js).
// The hash is base64-ish and always contains at least one digit, which is what
// separates a fingerprinted bundle from a hand-placed static file.
const FINGERPRINTED = /-[A-Za-z0-9_-]{8,}\.[a-z0-9]+$/i;

const isProtected = (name) =>
  name.startsWith('.') || // .htaccess, .gitkeep
  name === 'uploads' ||
  !FINGERPRINTED.test(name);

async function main() {
  if (!existsSync(ASSETS_DIR)) {
    console.log('[clean-assets] no assets directory; nothing to do');
    return;
  }

  const entries = await readdir(ASSETS_DIR, { withFileTypes: true });
  let removed = 0;

  for (const entry of entries) {
    if (!entry.isFile() || isProtected(entry.name)) continue;
    await unlink(path.join(ASSETS_DIR, entry.name));
    removed += 1;
  }

  console.log(`[clean-assets] removed ${removed} stale bundle(s) from ${ASSETS_DIR}`);
}

main().catch((error) => {
  // A failed clean must fail the build loudly. Silently shipping stale
  // bundles is exactly the problem this script exists to prevent.
  console.error('[clean-assets] FAILED:', error.message);
  process.exit(1);
});
