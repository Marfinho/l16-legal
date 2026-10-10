import { test, expect, Page } from '@playwright/test';

/**
 * Consent-Abnahme (M1): ausgeloggter Besucher.
 * (a) kein Request an Domains außerhalb der Allowlist
 * (b) kein Cookie außerhalb der "notwendig"-Liste
 * Läuft vor Einwilligung, nach "Ablehnen" und nach "Alle akzeptieren".
 */
const BASE_HOST = new URL(process.env.LL16_BASE_URL ?? 'http://localhost').hostname;
const NECESSARY_COOKIES = [/^woocommerce_/, /^wp_woocommerce_session_/, /^ll16_consent$/];
// Zahlungsdienste: nur auf Warenkorb/Kasse erlaubt.
const PAYMENT_HOSTS = [/(^|\.)stripe\.com$/, /(^|\.)stripe\.network$/, /(^|\.)paypal\.com$/, /(^|\.)paypalobjects\.com$/];
const PAYMENT_PATHS = [/\/(warenkorb|cart|checkout|kasse|abschluss)\//];
const PAGES = (process.env.LL16_PAGES ?? '/,/shop/,/contact-us/,/impressum/,/datenschutz/,/agb/,/widerruf/,/vertrag-widerrufen/,/versand-zahlung/,/cookies/,/warenkorb/').split(',');
// Nach "Alle akzeptieren" zusätzlich erlaubt (nur wenn im Register aktiv):
const ACCEPTED_EXTRA_HOSTS = (process.env.LL16_ACCEPTED_HOSTS ?? 'www.google.com,maps.gstatic.com').split(',');

async function visit(page: Page, path: string) {
  const hosts = new Set<string>();
  page.on('request', (r) => { try { hosts.add(new URL(r.url()).hostname); } catch {} });
  await page.goto(path, { waitUntil: 'networkidle' });
  return hosts;
}

function violations(hosts: Set<string>, path: string, extra: string[] = []) {
  const onPaymentPage = PAYMENT_PATHS.some((p) => p.test(path));
  return [...hosts].filter((h) =>
    h && h !== BASE_HOST && !extra.includes(h) &&
    !(onPaymentPage && PAYMENT_HOSTS.some((re) => re.test(h)))
  );
}

async function badCookies(page: Page) {
  return (await page.context().cookies()).map((c) => c.name).filter((n) => !NECESSARY_COOKIES.some((re) => re.test(n)));
}

for (const mode of ['vorher', 'ablehnen', 'akzeptieren'] as const) {
  test.describe(`Consent: ${mode}`, () => {
    for (const path of PAGES) {
      test(path, async ({ page }) => {
        if (mode !== 'vorher') {
          await page.goto('/', { waitUntil: 'domcontentloaded' });
          await page.getByRole('button', { name: mode === 'ablehnen' ? 'Ablehnen' : 'Alle akzeptieren' }).click();
        }
        const hosts = await visit(page, path);
        const extra = mode === 'akzeptieren' ? ACCEPTED_EXTRA_HOSTS : [];
        expect(violations(hosts, path, extra), 'unerlaubte Domains').toEqual([]);
        if (mode !== 'akzeptieren') {
          expect(await badCookies(page), 'unerlaubte Cookies').toEqual([]);
        }
      });
    }
  });
}

test('Banner: gleichwertige Buttons, ESC lehnt ab, Widerruf über Footer', async ({ page }) => {
  await page.goto('/');
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  for (const n of ['Alle akzeptieren', 'Ablehnen', 'Einstellungen']) {
    await expect(dialog.getByRole('button', { name: n })).toBeVisible();
  }
  const a = await dialog.getByRole('button', { name: 'Alle akzeptieren' }).boundingBox();
  const b = await dialog.getByRole('button', { name: 'Ablehnen' }).boundingBox();
  expect(Math.abs((a?.height ?? 0) - (b?.height ?? 1))).toBeLessThan(2);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await page.getByRole('button', { name: 'Cookie-Einstellungen' }).first().click();
  await expect(page.getByRole('dialog')).toBeVisible();
});

test('GPC wird als Ablehnung gewertet', async ({ browser }) => {
  const ctx = await browser.newContext({ extraHTTPHeaders: { 'Sec-GPC': '1' } });
  const page = await ctx.newPage();
  await page.addInitScript(() => Object.defineProperty(navigator, 'globalPrivacyControl', { value: true }));
  await page.goto('/');
  await expect(page.getByRole('dialog')).toBeHidden();
  await ctx.close();
});
