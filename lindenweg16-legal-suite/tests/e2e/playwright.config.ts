import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.',
  timeout: 60_000,
  use: { baseURL: process.env.LL16_BASE_URL, ignoreHTTPSErrors: false, locale: 'de-DE' },
});
