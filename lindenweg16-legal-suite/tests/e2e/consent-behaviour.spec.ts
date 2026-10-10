import { test, expect, Page } from '@playwright/test';

/** Verhalten des Banners im echten Browser gegen die Test-Instanz (Fixture: setup-fixture.php). */
const GATE = '/gate-test/';

async function track(page: Page) {
  const reqs: string[] = [];
  page.on('request', (r) => reqs.push(r.url()));
  return reqs;
}
const googleReqs = (reqs: string[]) => reqs.filter((u) => /google\.com/.test(u));
const cookieNames = async (page: Page) => (await page.context().cookies()).map((c) => c.name);
async function consentCookie(page: Page) {
  const c = (await page.context().cookies()).find((x) => x.name === 'll16_consent');
  return c ? JSON.parse(decodeURIComponent(c.value)) : null;
}

test('Erstbesuch: Banner sichtbar, vor Entscheidung nichts geladen', async ({ page }) => {
  const reqs = await track(page);
  await page.goto(GATE, { waitUntil: 'networkidle' });
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('aria-modal', 'true');
  await expect(dialog).not.toContainText('{');
  expect(await page.evaluate(() => (window as any).__ll16_test_loaded)).toBeFalsy();
  expect(await cookieNames(page)).not.toContain('tst_cookie');
  expect(googleReqs(reqs)).toEqual([]);
  expect(await page.locator('iframe[src*="google"]').count()).toBe(0);
  await expect(page.locator('[data-ll16-embed="google-maps"]')).toBeVisible();
  expect(await consentCookie(page)).toBeNull();
});

test('Ablehnen: Test-Dienst und Maps bleiben aus, Cookie gesetzt, Banner weg, Wiederbesuch ohne Banner', async ({ page }) => {
  const reqs = await track(page);
  await page.goto(GATE, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  await expect(page.getByRole('dialog')).toBeHidden();
  const c = await consentCookie(page);
  expect(c.c).toEqual([]);
  expect(c.id).toMatch(/^[0-9a-f-]{36}$/);
  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.getByRole('dialog')).toBeHidden();
  expect(await page.evaluate(() => (window as any).__ll16_test_loaded)).toBeFalsy();
  expect(await cookieNames(page)).not.toContain('tst_cookie');
  expect(googleReqs(reqs)).toEqual([]);
});

test('Alle akzeptieren: Dienst läuft sofort ohne Reload, Cookie wird gesetzt, Maps lädt', async ({ page }) => {
  const reqs = await track(page);
  await page.goto(GATE, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Alle akzeptieren' }).click();
  await expect.poll(() => page.evaluate(() => (window as any).__ll16_test_loaded)).toBeTruthy();
  expect(await cookieNames(page)).toContain('tst_cookie');
  await expect.poll(() => googleReqs(reqs).length).toBeGreaterThan(0);
  const c = await consentCookie(page);
  expect(c.c.sort()).toEqual(['preferences', 'statistics']);
  // Nach Reload bleibt die Einwilligung gültig, kein Banner
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('dialog')).toBeHidden();
  await expect.poll(() => page.evaluate(() => (window as any).__ll16_test_loaded)).toBeTruthy();
});

test('Widerruf: nach Akzeptieren über „Cookie-Einstellungen“ ablehnen entfernt Cookie', async ({ page }) => {
  await page.goto(GATE, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Alle akzeptieren' }).click();
  await expect.poll(() => cookieNames(page)).toContain('tst_cookie');
  await page.getByRole('button', { name: 'Cookie-Einstellungen' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel(/Statistik/)).toBeChecked();
  await dialog.getByLabel(/Statistik/).uncheck();
  await dialog.getByRole('button', { name: 'Auswahl speichern' }).click();
  await expect.poll(() => cookieNames(page)).not.toContain('tst_cookie');
  const c = await consentCookie(page);
  expect(c.c).toEqual(['preferences']);
});

test('Einstellungen: Notwendig ist fest, Statistik einzeln wählbar', async ({ page }) => {
  await page.goto(GATE, { waitUntil: 'networkidle' });
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: 'Einstellungen' }).click();
  await expect(dialog.getByLabel(/Notwendig/)).toBeDisabled();
  await expect(dialog.getByLabel(/Notwendig/)).toBeChecked();
  await dialog.getByLabel(/Statistik/).check();
  await dialog.getByRole('button', { name: 'Auswahl speichern' }).click();
  const c = await consentCookie(page);
  expect(c.c).toEqual(['statistics']);
  await expect.poll(() => page.evaluate(() => (window as any).__ll16_test_loaded)).toBeTruthy();
  expect(await page.locator('iframe[src*="google"]').count()).toBe(0); // Präferenzen nicht erlaubt → Karte bleibt zu
});

test('Zwei-Klick-Embed: lädt nur nach Klick, ohne Dauer-Einwilligung', async ({ page }) => {
  const reqs = await track(page);
  await page.goto(GATE, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Ablehnen' }).click();
  expect(googleReqs(reqs)).toEqual([]);
  await page.getByRole('button', { name: 'Inhalt laden' }).click();
  await expect(page.locator('iframe[src*="google.com/maps"]')).toHaveCount(1);
  await expect.poll(() => googleReqs(reqs).length).toBeGreaterThan(0);
  const c = await consentCookie(page);
  expect(c.c).toEqual([]);        // Einmalige Freigabe, keine gespeicherte Einwilligung
});

test('Tastatur: Fokus im Dialog, Tab-Falle, ESC = Ablehnen', async ({ page }) => {
  await page.goto(GATE, { waitUntil: 'networkidle' });
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  expect(await page.evaluate(() => !!document.activeElement?.closest('[role=dialog]'))).toBe(true);
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => !!document.activeElement?.closest('[role=dialog]'))).toBe(true);
  }
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  expect((await consentCookie(page)).c).toEqual([]);
});

test('Mobil (375 px): Banner passt in den Viewport, kein horizontales Scrollen, Buttons ≥ 44 px', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 667 }, locale: 'de-DE' });
  const page = await ctx.newPage();
  await page.goto(GATE);
  const box = await page.getByRole('dialog').boundingBox();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(375);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  for (const n of ['Ablehnen', 'Alle akzeptieren', 'Einstellungen']) {
    const b = await page.getByRole('dialog').getByRole('button', { name: n }).boundingBox();
    expect(b!.height).toBeGreaterThanOrEqual(44);
  }
  await ctx.close();
});

test('Barrierefreiheit: Dialog hat Titel/Beschreibung, Fokus kehrt zurück', async ({ page }) => {
  await page.goto(GATE);
  const d = page.getByRole('dialog', { name: 'Deine Privatsphäre' });
  await expect(d).toBeVisible();
  await expect(d).toHaveAttribute('aria-describedby', 'll16-desc');
  await d.getByRole('button', { name: 'Ablehnen' }).click();
  const opener = page.getByRole('button', { name: 'Cookie-Einstellungen' });
  await opener.focus();
  await opener.press('Enter');
  await expect(page.getByRole('dialog')).toBeVisible();
});

test('GPC-Signal: kein Banner, als Ablehnung gespeichert, nichts geladen', async ({ browser }) => {
  const ctx = await browser.newContext({ extraHTTPHeaders: { 'Sec-GPC': '1' } });
  const page = await ctx.newPage();
  await page.addInitScript(() => Object.defineProperty(navigator, 'globalPrivacyControl', { value: true }));
  await page.goto(GATE, { waitUntil: 'networkidle' });
  await expect(page.getByRole('dialog')).toBeHidden();
  expect((await (async () => { const c = (await ctx.cookies()).find((x) => x.name === 'll16_consent'); return JSON.parse(decodeURIComponent(c!.value)); })()).c).toEqual([]);
  expect(await page.evaluate(() => (window as any).__ll16_test_loaded)).toBeFalsy();
  await ctx.close();
});

test('Re-Consent: veraltete Registerversion im Cookie → Banner erscheint erneut', async ({ page, context }) => {
  await page.goto(GATE, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Alle akzeptieren' }).click();
  const c = (await context.cookies()).find((x) => x.name === 'll16_consent')!;
  const val = JSON.parse(decodeURIComponent(c.value));
  val.v = 'alteversion';
  await context.addCookies([{ ...c, value: encodeURIComponent(JSON.stringify(val)) }]);
  await page.reload();
  await expect(page.getByRole('dialog')).toBeVisible();
});

test('Re-Consent: Einwilligung älter als 12 Monate → Banner erscheint erneut', async ({ page, context }) => {
  await page.goto(GATE, { waitUntil: 'networkidle' });
  await page.getByRole('button', { name: 'Alle akzeptieren' }).click();
  const c = (await context.cookies()).find((x) => x.name === 'll16_consent')!;
  const val = JSON.parse(decodeURIComponent(c.value));
  val.t = Date.now() - 400 * 864e5;
  await context.addCookies([{ ...c, value: encodeURIComponent(JSON.stringify(val)) }]);
  await page.reload();
  await expect(page.getByRole('dialog')).toBeVisible();
});

test('Nachweis: Entscheidung landet serverseitig im Consent-Log', async ({ page, request }) => {
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/ll16/v1/consent') && r.request().method() === 'POST'),
    (async () => { await page.goto(GATE, { waitUntil: 'networkidle' }); await page.getByRole('button', { name: 'Ablehnen' }).click(); })(),
  ]);
  expect(resp.status()).toBe(200);
  expect(await resp.json()).toEqual({ ok: true });
});
