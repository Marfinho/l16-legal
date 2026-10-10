import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Drittanbieter-Skripte, die ein Fremdplugin überall ausgibt:
 * Zahlungsdienste nur auf Warenkorb/Kasse, reCAPTCHA/Stats nie.
 * (Das Fremdplugin wird per mu-plugin simuliert; Option ll16_test_inject_third_party.)
 */
const WP_PATH = process.env.LL16_WP_PATH;
const WPCLI = (process.env.LL16_WPCLI ?? 'wp').split(' ');
test.skip(!WP_PATH, 'LL16_WP_PATH nicht gesetzt');
const opt = (v: string) => execFileSync(WPCLI[0], [...WPCLI.slice(1), '--allow-root', `--path=${WP_PATH}`, 'option', v === 'on' ? 'update' : 'delete', 'll16_test_inject_third_party', ...(v === 'on' ? ['1'] : [])], { encoding: 'utf8' });
test.beforeAll(() => opt('on'));
test.afterAll(() => opt('off'));

const scriptSrcs = (page: Page) => page.evaluate(() => [...document.scripts].map((s) => s.src).filter(Boolean));

async function hostsOn(page: Page, path: string) {
  const hosts = new Set<string>();
  page.on('request', (r) => { try { hosts.add(new URL(r.url()).hostname); } catch {} });
  await page.goto(path, { waitUntil: 'networkidle' });
  const html = await page.content();
  return { hosts, html, srcs: await scriptSrcs(page) };
}
const has = (h: Set<string>, re: RegExp) => [...h].some((x) => re.test(x));

for (const path of ['/', '/shop/', '/gate-test/', '/impressum/', '/product/base/']) {
  test(`${path}: keine Zahlungs-/Tracking-Skripte, auch nicht nach „Alle akzeptieren“`, async ({ page }) => {
    let r = await hostsOn(page, path);
    expect(has(r.hosts, /stripe/)).toBe(false);
    expect(has(r.hosts, /paypal/)).toBe(false);
    expect(has(r.hosts, /recaptcha|gstatic\.com$/)).toBe(false);
    expect(has(r.hosts, /stats\.wp\.com/)).toBe(false);
    expect(r.srcs.filter((u) => /stripe|paypal|recaptcha|stats\.wp\.com/.test(u))).toEqual([]);
    await page.getByRole('button', { name: 'Alle akzeptieren' }).click();
    await page.reload({ waitUntil: 'networkidle' });
    const after = await scriptSrcs(page);
    expect(after.filter((u) => /stripe|paypal|recaptcha|stats\.wp\.com/.test(u))).toEqual([]);
  });
}

for (const path of ['/cart/', '/checkout/']) {
  test(`${path}: Zahlungsskripte erlaubt (Stripe, PayPal), reCAPTCHA/Stats weiterhin nie`, async ({ page }) => {
    // Etwas im Warenkorb, sonst leitet /checkout/ um
    await page.goto('/product/base/');
    await page.getByRole('button', { name: /add to cart|in den warenkorb/i }).first().click().catch(() => {});
    await page.waitForTimeout(1000);
    const r = await hostsOn(page, path);
    expect(r.srcs.some((u) => u.includes('js.stripe.com'))).toBe(true);
    expect(r.srcs.some((u) => u.includes('paypal.com/sdk'))).toBe(true);
    expect(has(r.hosts, /stripe/)).toBe(true);
    expect(r.srcs.filter((u) => /recaptcha|stats\.wp\.com/.test(u))).toEqual([]);
    expect(has(r.hosts, /recaptcha|stats\.wp\.com/)).toBe(false);
  });
}
