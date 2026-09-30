import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

// https://vitejs.dev/config/
export default defineConfig({
  root: __dirname,
  // base defaults to '/', which is correct for BOTH deployments we support:
  //   - Apache DocumentRoot at the repository root (subdomain install)
  //   - Apache DocumentRoot at backend/public
  // A subdirectory install (e.g. http://host/hrdemo/) would need base: '/hrdemo/'
  // and matching RewriteRules, which is the dev-only XAMPP layout.
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    // Emit the production bundle into backend/public, which is the directory
    // that already contains the shell, the service worker and the .htaccess
    // implementing one-year immutable caching for fingerprinted assets.
    //
    // This is what makes a fresh build self-consistent: previously the build
    // went to frontend/dist while the tracked backend/public/index.html kept
    // referencing a bundle hash from an old build, so a deploy shipped a shell
    // pointing at assets that no longer existed.
    outDir: path.resolve(__dirname, '../backend/public'),
    // Only the generated bundle is cleared. emptyOutDir wipes outDir entirely,
    // which would delete backend/public/.htaccess, index.php, robots.txt and
    // the uploads/ tree - none of which are build artefacts.
    emptyOutDir: false,
    assetsDir: 'assets',
  },
  server: {
    // Vite default port (5173). Override via `npm run dev -- --port=3000` if needed.
    // The backend is the XAMPP-served PHP API at /hrdemo/api.php. We forward
    // /api/* to the XAMPP Apache server and rewrite the path to prefix
    // /hrdemo so that api.php receives a normal REQUEST_URI of
    // /hrdemo/api/auth/login and its own router can match /auth/login correctly.
    proxy: {
      '/api': {
        target: 'http://localhost', // XAMPP Apache on port 80
        changeOrigin: true,
        secure: false,
        rewrite: (path) => `/hrdemo${path}`, // Prefix with /hrdemo
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['**/*.{test,spec}.{js,ts,jsx,tsx}'],
    // Globals are required by src/__tests__/setup.ts (@testing-library/jest-dom
    // v6 extends the global expect). Explicit imports keep working too.
    globals: true,
    setupFiles: ['./src/__tests__/setup.ts'],
  },
});
