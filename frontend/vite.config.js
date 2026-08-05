import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = fileURLToPath(new URL('.', import.meta.url))

// https://vitejs.dev/config/
export default defineConfig({
  root: __dirname,
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    // Vite default port (5173). Override via `npm run dev -- --port=3000` if needed.
    // The backend is the XAMPP-served PHP API at /hrdemo/api.php. We forward
    // /api/* straight to the XAMPP Apache server and let the project's
    // .htaccess rewrite rule route /api/* -> api.php. This way api.php
    // receives a normal REQUEST_URI of /hrdemo/api/auth/login and its
    // own router can match /auth/login correctly.
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
        secure: false,
        rewrite: (path) => `/hrdemo${path}`,
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['**/*.{test,spec}.{js,ts,jsx,tsx}'],
  },
})
