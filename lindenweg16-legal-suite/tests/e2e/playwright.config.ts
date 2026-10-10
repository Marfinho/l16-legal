import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.',
  timeout: 60_000,
  workers: 1,
  reporter: [['list']],
  use: {
    screenshot: 'only-on-failure',
    baseURL: process.env.LL16_BASE_URL,
    locale: 'de-DE',
    // Vorinstalliertes Chromium nutzen, falls gesetzt (z. B. /opt/pw-browsers/chromium)
    launchOptions: process.env.LL16_CHROMIUM ? { executablePath: process.env.LL16_CHROMIUM, args: ['--no-sandbox'] } : {},
  },
});
