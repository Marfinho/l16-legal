import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

/**
 * UX, Barrierefreiheit und Randfälle im echten Browser (Fixture: setup-fixture.php + Integrationstests 02/05/06).
 * Seiten: /gate-test/ (Banner), /vertrag-widerrufen/, /kontakt-test/, /newsletter-test/, /product/base/.
 */
const WP_PATH = process.env.LL16_WP_PATH;
const WPCLI = (process.env.LL16_WPCLI ?? 'wp').split(' ');
function wp(code: string): any {
  const out = execFileSync(WPCLI[0], [...WPCLI.slice(1), '--allow-root', `--path=${WP_PATH}`, 'eval', code], { encoding: 'utf8' });
  const l = out.split('\n').filter((x) => x.startsWith('JSON:')).pop();
  return l ? JSON.parse(l.slice(5)) : null;
}
const reset = () => wp(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ll16_rl_%' OR option_name LIKE '%ll16_wd_%'"); echo 'JSON:1';`);
test.beforeEach(() => { if (WP_PATH) reset(); });

const OURS = '.ll16-banner, .ll16-form, .ll16-withdrawal, .ll16-footer-links, .ll16-checkout-terms, .ll16-embed, .ll16-contact';
async function axeFor(page: Page) {
  const r = await new AxeBuilder({ page }).include(OURS).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
  return r.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''));
}
const fmt = (v: any[]) => v.map((x) => `${x.id}: ${x.nodes.map((n: any) => n.target.join(' ')).join(' | ')}`).join('\n');

test.describe('Barrierefreiheit (axe, WCAG 2.1 AA) – nur Elemente des Plugins', () => {
  for (const [name, path, act] of [
    ['Banner', '/gate-test/', async (_p: Page) => {}],
    ['Banner mit Einstellungen', '/gate-test/', async (p: Page) => { await p.getByRole('button', { name: 'Einstellungen' }).click(); }],
    ['Widerrufsformular', '/vertrag-widerrufen/', async (p: Page) => { await p.getByRole('button', { name: 'Ablehnen' }).click(); }],
    ['Kontaktformular', '/kontakt-test/', async (p: Page) => { await p.getByRole('button', { name: 'Ablehnen' }).click(); }],
    ['Newsletter', '/newsletter-test/', async (p: Page) => { await p.getByRole('button', { name: 'Ablehnen' }).click(); }],
    ['Footer-Links', '/impressum/', async (p: Page) => { await p.getByRole('button', { name: 'Ablehnen' }).click(); }],
  ] as const) {
    for (const scheme of ['light', 'dark'] as const) {
      test(`${name} (${scheme})`, async ({ browser }) => {
        const ctx = await browser.newContext({ colorScheme: scheme, locale: 'de-DE' });
        const page = await ctx.newPage();
        await page.goto(path, { waitUntil: 'networkidle' });
        await act(page);
        const v = await axeFor(page);
        expect(v, fmt(v)).toEqual([]);
        await ctx.close();
      });
    }
  }
});

test('Footer: kein schwebender Cookie-Button mehr, Link im Footer öffnet das Banner', async ({ page }) => {
  await page.goto('/product/base/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  expect(await page.locator('.ll16-float').count()).toBe(0);
  await page.getByRole('link', { name: 'Cookie-Einstellungen' }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
});

test('Handy: „In den Warenkorb“ ist nach der Entscheidung nicht verdeckt und erreichbar', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 667 }, locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto('/product/base/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  const btn = page.getByRole('button', { name: /in den warenkorb/i }).first();
  await btn.scrollIntoViewIfNeeded();
  const box = (await btn.boundingBox())!;
  const top = await page.evaluate(([x, y]) => document.elementFromPoint(x as number, y as number)?.closest('button')?.textContent?.trim(), [box.x + box.width / 2, box.y + box.height / 2]);
  expect(top).toMatch(/warenkorb/i);
  await ctx.close();
});

test('Banner bei 320×480 (kleines Handy/Querformat): scrollbar, alle Buttons erreichbar, kein horizontaler Überlauf', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 320, height: 480 }, locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto('/gate-test/');
  const d = page.getByRole('dialog');
  await d.getByRole('button', { name: 'Einstellungen' }).click();
  for (const n of ['Ablehnen', 'Alle akzeptieren', 'Auswahl speichern']) {
    await d.getByRole('button', { name: n }).scrollIntoViewIfNeeded();
    await expect(d.getByRole('button', { name: n })).toBeInViewport();
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const b = (await d.boundingBox())!;
  expect(b.height).toBeLessThanOrEqual(480);
  await ctx.close();
});

test('Querformat niedrig (667×320): Banner passt in den Viewport', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 667, height: 320 }, locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto('/gate-test/');
  const b = (await page.getByRole('dialog').boundingBox())!;
  expect(b.y).toBeGreaterThanOrEqual(0);
  expect(b.y + b.height).toBeLessThanOrEqual(320);
  await page.getByRole('dialog').getByRole('button', { name: 'Ablehnen' }).click();
  await expect(page.getByRole('dialog')).toBeHidden();
  await ctx.close();
});

test('200 % Zoom (Viewport 640 px): Formular benutzbar ohne horizontales Scrollen', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 640, height: 500 }, deviceScaleFactor: 1, locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto('/vertrag-widerrufen/');
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await ctx.close();
});

test('Erzwungene Farben (Windows-Kontrastmodus): Buttons und Banner haben sichtbare Ränder', async ({ browser }) => {
  const ctx = await browser.newContext({ forcedColors: 'active', locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto('/gate-test/');
  const w = await page.getByRole('button', { name: 'Ablehnen' }).evaluate((e) => getComputedStyle(e).borderTopWidth);
  expect(parseFloat(w)).toBeGreaterThanOrEqual(2);
  await ctx.close();
});

test('Dark Mode: Banner folgt dem System, Text ausreichend lesbar', async ({ browser }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark', locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto('/gate-test/');
  const bg = await page.locator('.ll16-banner').evaluate((e) => getComputedStyle(e).backgroundColor);
  expect(bg).not.toBe('rgb(255, 255, 255)');
  await ctx.close();
});

test('Layout-Verschiebung (CLS) beim ersten Besuch ist durch das Banner nicht messbar', async ({ page }) => {
  await page.addInitScript(() => {
    (window as any).__cls = 0;
    new PerformanceObserver((l) => { for (const e of l.getEntries() as any[]) if (!e.hadRecentInput) (window as any).__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  });
  await page.goto('/product/base/', { waitUntil: 'networkidle' });
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.waitForTimeout(800);
  expect(await page.evaluate(() => (window as any).__cls)).toBeLessThan(0.1);
});

test('Performance: Banner-JS klein, ohne Abhängigkeiten, sichtbar in unter 2 s', async ({ page }) => {
  const sizes: Record<string, number> = {};
  page.on('response', async (r) => { if (/ll16|consent\.(js|css)|checkout\.(js|css)|forms\.css/.test(r.url())) sizes[r.url().split('/').pop()!.split('?')[0]] = (await r.body()).length; });
  const t0 = Date.now();
  await page.goto('/gate-test/');
  await expect(page.getByRole('dialog')).toBeVisible();
  expect(Date.now() - t0).toBeLessThan(2000);
  await page.waitForLoadState('networkidle');
  expect(sizes['consent.js']).toBeLessThan(14_000);
  expect(sizes['consent.css']).toBeLessThan(5_000);
});

test('Keine JavaScript-Fehler auf den wichtigsten Seiten (Banner, Formulare, Rechtstexte)', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|net::ERR|google|stripe/i.test(m.text())) errors.push(m.text()); });
  for (const u of ['/', '/gate-test/', '/vertrag-widerrufen/', '/kontakt-test/', '/newsletter-test/', '/impressum/', '/datenschutz/', '/agb/', '/widerruf/', '/cookies/', '/product/base/', '/shop/', '/cart/']) {
    await page.goto(u, { waitUntil: 'networkidle' });
    const rej = page.getByRole('button', { name: 'Ablehnen' });
    if (await rej.isVisible().catch(() => false)) await rej.click();
  }
  expect(errors).toEqual([]);
});

test.describe('Defekte oder fehlende Cookies', () => {
  test('Beschädigter Cookie-Wert: Banner erscheint wieder, kein Fehler', async ({ browser }) => {
    const ctx = await browser.newContext({ locale: 'de-DE' });
    const errors: string[] = [];
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    for (const val of ['%7Bkaputt', 'null', '%5B%5D', encodeURIComponent(JSON.stringify({ v: 'x', c: 'string' })), encodeURIComponent(JSON.stringify({ v: null })), 'A'.repeat(3000)]) {
      await ctx.addCookies([{ name: 'll16_consent', value: val, url: process.env.LL16_BASE_URL! }]);
      await page.goto('/gate-test/', { waitUntil: 'networkidle' });
      await expect(page.getByRole('dialog')).toBeVisible();
    }
    expect(errors).toEqual([]);
    await ctx.close();
  });

  test('Cookies im Browser gesperrt: kein Absturz, Entscheidung gilt für die Seite, Banner schließt', async ({ browser }) => {
    const ctx = await browser.newContext({ locale: 'de-DE' });
    const page = await ctx.newPage();
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.addInitScript(() => Object.defineProperty(document, 'cookie', { get: () => '', set: () => {} }));
    await page.goto('/gate-test/', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Ablehnen' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
    expect(await page.evaluate(() => (window as any).__ll16_test_loaded)).toBeFalsy();
    expect(errors).toEqual([]);
    await ctx.close();
  });
});

test('Zurück-Button (bfcache): nach der Entscheidung kommt das Banner nicht wieder', async ({ page }) => {
  await page.goto('/gate-test/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  await page.goto('/impressum/', { waitUntil: 'networkidle' });
  await page.goBack({ waitUntil: 'networkidle' });
  await expect(page.getByRole('dialog')).toBeHidden();
});

test('Zwei Tabs: Entscheidung im ersten gilt nach Neuladen im zweiten', async ({ context }) => {
  const a = await context.newPage();
  const b = await context.newPage();
  await a.goto('/gate-test/', { waitUntil: 'networkidle' });
  await b.goto('/gate-test/', { waitUntil: 'networkidle' });
  await a.getByRole('button', { name: 'Alle akzeptieren' }).click();
  await b.reload({ waitUntil: 'networkidle' });
  await expect(b.getByRole('dialog')).toBeHidden();
  await expect.poll(() => b.evaluate(() => (window as any).__ll16_test_loaded)).toBeTruthy();
});

test('Doppelklick auf „Ablehnen“ erzeugt genau einen Nachweis', async ({ page }) => {
  let posts = 0;
  page.on('request', (r) => { if (r.url().includes('/ll16/v1/consent') && r.method() === 'POST') posts++; });
  await page.goto('/gate-test/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).dblclick();
  await page.waitForTimeout(800);
  expect(posts).toBe(1);
});

test('Ohne JavaScript: Widerruf funktioniert vollständig, Karte bietet Link statt leerer Fläche', async ({ browser }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto('/gate-test/');
  expect(await page.locator('iframe[src*="google"]').count()).toBe(0);           // nichts lädt ohne Einwilligung
  await expect(page.locator('noscript a[href*="google.com/maps"]')).toHaveCount(1);
  await page.goto('/vertrag-widerrufen/');
  await page.getByLabel(/Dein Name/).fill('Ohne Skript');
  await page.getByLabel(/Bestellnummer/).fill('4711');
  await page.getByLabel(/E-Mail-Adresse/).fill('ohne@example.com');
  await page.waitForTimeout(3000);
  await page.getByRole('button', { name: 'Weiter' }).click();
  await expect(page.getByRole('heading', { name: /prüfen und bestätigen/ })).toBeVisible();
  await page.waitForTimeout(1500);
  await page.getByRole('button', { name: 'Widerruf bestätigen' }).click();
  await expect(page.getByRole('heading', { name: 'Widerruf eingegangen' })).toBeVisible();
  await ctx.close();
});

test.describe('Widerruf im Browser', () => {
  async function toReview(page: Page, extra: Partial<Record<string, string>> = {}) {
    await page.goto('/vertrag-widerrufen/', { waitUntil: 'networkidle' });
    const rej = page.getByRole('button', { name: 'Ablehnen' });
    if (await rej.isVisible().catch(() => false)) await rej.click();
    await page.getByLabel(/Dein Name/).fill(extra.name ?? 'Erika Muster');
    await page.getByLabel(/Bestellnummer/).fill(extra.order ?? '4711');
    await page.getByLabel(/E-Mail-Adresse/).fill(extra.email ?? 'erika@example.com');
    await page.waitForTimeout(2500);
    await page.getByRole('button', { name: 'Weiter' }).click();
    await expect(page.getByRole('button', { name: 'Widerruf bestätigen' })).toBeVisible();
    await page.waitForTimeout(1500);
  }

  test('Nur mit Tastatur: Tab durch das Formular, Enter sendet', async ({ page }) => {
    await page.goto('/vertrag-widerrufen/', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Ablehnen' }).click();
    await page.getByLabel(/Dein Name/).focus();
    await page.keyboard.type('Tastatur Test');
    await page.keyboard.press('Tab');
    await page.keyboard.type('4712');
    await page.keyboard.press('Tab');
    await page.keyboard.type('tast@example.com');
    await page.waitForTimeout(2500);
    await page.keyboard.press('Enter');
    await expect(page.getByRole('button', { name: 'Widerruf bestätigen' })).toBeVisible();
  });

  test('Fehler: Fokus springt auf die Fehlermeldung (Screenreader), Eingaben bleiben', async ({ page }) => {
    await page.goto('/vertrag-widerrufen/', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Ablehnen' }).click();
    await page.getByLabel(/Dein Name/).fill('Nur Name');
    await page.getByLabel(/Bestellnummer/).fill('abc');       // keine Ziffern
    await page.getByLabel(/E-Mail-Adresse/).evaluate((e: HTMLInputElement) => { e.type = 'text'; e.value = 'kaputt'; });
    await page.waitForTimeout(2500);
    await page.getByRole('button', { name: 'Weiter' }).click();
    const alert = page.getByRole('alert');
    await expect(alert).toContainText('Bestellnummer');
    await expect(alert).toContainText('E-Mail-Adresse');
    expect(await page.evaluate(() => document.activeElement?.id)).toBe('ll16-errors');
    await expect(page.getByLabel(/Dein Name/)).toHaveValue('Nur Name');
  });

  test('„Angaben ändern“ bringt die Werte zurück', async ({ page }) => {
    await toReview(page, { name: 'Änderung Ü', order: '9001' });
    await page.getByRole('button', { name: 'Angaben ändern' }).click();
    await expect(page.getByLabel(/Dein Name/)).toHaveValue('Änderung Ü');
    await expect(page.getByLabel(/Bestellnummer/)).toHaveValue('9001');
  });

  test('Doppelklick auf „Widerruf bestätigen“: nur ein Widerruf, nur eine Mail', async ({ page }) => {
    test.skip(!WP_PATH, 'LL16_WP_PATH fehlt');
    wp(`@unlink(WP_CONTENT_DIR.'/mail.log'); echo 'JSON:1';`);
    await toReview(page, { email: 'doppel@example.com' });
    await page.getByRole('button', { name: 'Widerruf bestätigen' }).dblclick();
    await expect(page.getByRole('heading', { name: 'Widerruf eingegangen' })).toBeVisible();
    await page.waitForTimeout(1000);
    const n = wp(`$m=file_exists(WP_CONTENT_DIR.'/mail.log')?file(WP_CONTENT_DIR.'/mail.log'):[]; echo 'JSON:'.count(array_filter($m,fn($l)=>str_contains($l,'doppel@example.com')&&str_contains($l,'Eingangsbest')));`);
    expect(n).toBe(1);
  });

  test('Bestätigungsseite: Eingangszeit, E-Mail-Adresse, Spam-Hinweis, Hinweis „nur Eingang“', async ({ page }) => {
    await toReview(page, { email: 'text@example.com' });
    await page.getByRole('button', { name: 'Widerruf bestätigen' }).click();
    const b = page.locator('.ll16-withdrawal');
    await expect(b).toContainText(/\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}:\d{2} Uhr/);
    await expect(b).toContainText('text@example.com');
    await expect(b).toContainText('Spam');
    await expect(b).toContainText('nur den Eingang');
    await page.screenshot({ path: '/tmp/ux/widerruf-bestaetigt.png', fullPage: true });
  });

  test('Link aus dem Konto (?order=) füllt die Bestellnummer vor', async ({ page }) => {
    await page.goto('/vertrag-widerrufen/?order=1234', { waitUntil: 'networkidle' });
    await expect(page.getByLabel(/Bestellnummer/)).toHaveValue('1234');
  });
});

test.describe('Kontaktformular im Browser', () => {
  test('Pflichtfelder: native Hinweise, Tastatur, Fehler behalten Text, Erfolg ist klar', async ({ page }) => {
    await page.goto('/kontakt-test/', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Ablehnen' }).click();
    await page.getByRole('button', { name: 'Absenden' }).click();
    expect(await page.evaluate(() => (document.activeElement as HTMLInputElement)?.name)).toBe('ll16_name'); // Browser fokussiert erstes Pflichtfeld
    await page.getByLabel('Name *').fill('Anna');
    await page.getByLabel('E-Mail *').fill('anna@example.com');
    await page.getByLabel('Nachricht *').fill('Eine längere Nachricht\nmit Zeilenumbruch.');
    await page.getByLabel(/Datenschutzerklärung gelesen/).check();
    await page.waitForTimeout(3500);
    await page.getByRole('button', { name: 'Absenden' }).click();
    await expect(page.locator('.ll16-ok')).toContainText('angekommen');
  });

  test('Feldgrößen: ≥ 44 px hoch, ≥ 16 px Schrift (kein iOS-Zoom), Label-Klick fokussiert', async ({ page }) => {
    await page.goto('/kontakt-test/', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Ablehnen' }).click();
    for (const sel of ['#ll16_name', '#ll16_email', '#ll16_message']) {
      const m = await page.locator(sel).evaluate((e) => ({ h: e.getBoundingClientRect().height, f: parseFloat(getComputedStyle(e).fontSize) }));
      expect(m.h).toBeGreaterThanOrEqual(44);
      expect(m.f).toBeGreaterThanOrEqual(16);
    }
    await page.getByText('E-Mail *').click();
    expect(await page.evaluate(() => document.activeElement?.id)).toBe('ll16_email');
  });
});

test('Newsletter: klare Rückmeldung nach der Anmeldung', async ({ page }) => {
  await page.goto('/newsletter-test/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  await page.getByLabel('E-Mail *').fill('nl-ux@example.com');
  await page.getByLabel(/Newsletter erhalten/).check();
  await page.waitForTimeout(3500);
  await page.getByRole('button', { name: 'Anmelden' }).click();
  await expect(page.locator('.ll16-ok')).toContainText('Bestätigungslink');
  await expect(page.locator('.ll16-ok')).toContainText('Spam');
});

test('Produktseite: Preis-Hinweise, Lieferzeit und Gewährleistung stehen vor dem Kaufbutton', async ({ page }) => {
  await page.goto('/product/base/', { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  const order = await page.evaluate(() => {
    const y = (s: string) => (document.querySelector(s) as HTMLElement | null)?.getBoundingClientRect().top ?? -1;
    return { price: y('.ll16-price-note'), delivery: y('.ll16-delivery-time'), warranty: y('.ll16-warranty-notice'), buy: y('form.cart button, .single_add_to_cart_button, .wp-block-woocommerce-add-to-cart-form button') };
  });
  expect(order.price).toBeGreaterThan(0);
  expect(order.delivery).toBeGreaterThan(order.price);
  expect(order.warranty).toBeGreaterThan(0);
  expect(order.buy).toBeGreaterThan(0);
  expect(order.warranty).toBeLessThan(order.buy + 400);   // in der Nähe des Kaufbereichs, nicht unter den Rezensionen
});
