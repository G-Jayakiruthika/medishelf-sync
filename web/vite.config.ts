import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The site is served from https://<user>.github.io/medishelf-sync/ — BASE_PATH lets a custom domain use "/".
export default defineConfig({
  base: process.env.BASE_PATH ?? '/medishelf-sync/',
  plugins: [react()],
  build: { sourcemap: false, chunkSizeWarningLimit: 700 },
  test: { include: ['tests/unit/**/*.test.ts'] },
});
