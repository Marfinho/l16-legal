import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

/**
 * Kompletter Bestellvorgang im Block-Checkout (echter Browser, echtes WooCommerce).
 * Benötigt LL16_WP_PATH und LL16_WPCLI (z. B. "php wp-cli.phar") für Datenbankprüfungen.
 */
const WP_PATH = process.env.LL16_WP_PATH;
const WPCLI = (process.env.LL16_WPCLI ?? 'wp').split(' ');
test.skip(!WP_PATH, 'LL16_WP_PATH nicht gesetzt');

function wp(code: string): any {
  const out = execFileSync(WPCLI[0], [...WPCLI.slice(1), '--allow-root', `--path=${WP_PATH}`, 'eval', code], { encoding: 'utf8' });
  const line = out.split('\n').filter((l) => l.startsWith('JSON:')).pop();
  return line ? JSON.parse(line.slice(5)) : null;
}
const lastOrder = () => wp(`$o=wc_get_orders(['limit'=>1,'orderby'=>'id','order'=>'DESC','status'=>array_values(array_diff(array_keys(wc_get_order_statuses()),['wc-checkout-draft']))])[0]; echo 'JSON:'.wp_json_encode(['id'=>$o->get_id(),'status'=>$o->get_status(),'email'=>$o->get_billing_email(),'country'=>$o->get_billing_country(),'ship'=>$o->get_shipping_total(),'terms'=>$o->get_meta('_wc_other/lindenweg16/terms'),'ack'=>$o->get_meta('_wc_other/lindenweg16/individual_ack'),'snap'=>json_decode($o->get_meta('_ll16_legal_snapshot'),true)['texts']?array_keys(json_decode($o->get_meta('_ll16_legal_snapshot'),true)['texts']):[],'items'=>array_map(fn($i)=>$i->get_name(),array_values($o->get_items()))]);`);
const orderCount = () => wp(`echo 'JSON:'.wc_get_orders(['limit'=>-1,'return'=>'ids','paginate'=>true,'status'=>array_values(array_diff(array_keys(wc_get_order_statuses()),['wc-checkout-draft']))])->total;`);

async function addToCart(page: Page, path: string) {
  await page.goto(path);
  const reject = page.getByRole('button', { name: 'Ablehnen' });
  if (await reject.isVisible().catch(() => false)) await reject.click();
  await page.getByRole('button', { name: /add to cart|in den warenkorb/i }).first().click();
  await page.waitForTimeout(1200);
}

/**
 * Bestellen. WooCommerce speichert Felder beim Blur: Der erste Klick direkt nach dem Setzen einer
 * Checkbox schickt oft nur das Feld ab (auch ohne dieses Plugin). Deshalb bei Bedarf ein zweiter Klick.
 */
async function placeOrder(page: Page) {
  const b = page.getByRole('button', { name: 'Zahlungspflichtig bestellen' });
  let posted = false;
  page.on('request', (r) => { if (r.method() === 'POST' && /\/wc\/store\/v1\/checkout\?_locale/.test(r.url())) posted = true; });
  for (let i = 0; i < 3 && !posted; i++) {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
    await expect(b).toBeEnabled();
    await b.click();
    await page.waitForTimeout(1500);
  }
}

async function fillAddress(page: Page) {
  await page.goto('/checkout/', { waitUntil: 'networkidle' });
  await page.getByLabel(/^(E-Mail-Adresse|Email address|E-?mail)$/i).first().fill('kaeufer@example.com');
  await page.getByLabel(/^(first name|vorname)$/i).first().fill('Erika');
  await page.getByLabel(/^(last name|nachname)$/i).first().fill('Muster');
  await page.getByLabel(/^(address|adresse)$/i).first().fill('Musterstraße 1');
  await page.getByLabel(/^(postal code|postcode|zip code|postleitzahl)$/i).first().fill('31559');
  await page.getByLabel(/^(city|stadt)$/i).first().fill('Haste');
}

test.describe.configure({ mode: 'serial' });

test('Bestellung: Button, Hinweis direkt über dem Button, Pflicht-Checkbox, Versandkosten, Snapshot', async ({ page }) => {
  await addToCart(page, '/product/base/');
  await fillAddress(page);

  const button = page.getByRole('button', { name: 'Zahlungspflichtig bestellen' });
  await expect(button).toHaveCount(1);
  const notice = page.locator('.ll16-checkout-terms');
  await expect(notice).toHaveCount(1);
  // Hinweis liegt im DOM unmittelbar vor dem Button-Bereich
  expect(await page.evaluate(() => {
    const n = document.querySelector('.ll16-checkout-terms'); const a = document.querySelector('.wc-block-checkout__actions');
    return !!n && !!a && n.nextElementSibling === a;
  })).toBe(true);
  for (const href of ['/agb/', '/widerruf/', '/datenschutz/']) await expect(notice.locator(`a[href$="${href}"]`)).toHaveCount(1);
  await expect(page.getByText(/Lieferung nur nach Deutschland/)).toBeVisible();
  await expect(page.getByText(/Testkarte|Testmodus/i)).toHaveCount(0);

  // Land: nur Deutschland
  const countries = await page.locator('select[id$="country"] option').evaluateAll((o) => o.map((x) => (x as HTMLOptionElement).value).filter(Boolean));
  expect([...new Set(countries)]).toEqual(['DE']);   // Platzhalter-Option (leerer Wert) ignorieren, Sprache egal

  await page.getByRole('radio', { name: /Überweisung/ }).check({ timeout: 20000 });

  // 1) Ohne Pflicht-Checkbox keine Bestellung
  const before = orderCount();
  const terms = page.getByLabel(/Ich habe AGB, Widerrufsbelehrung/).first();
  await expect(terms).not.toBeChecked();
  await button.click();
  await page.waitForTimeout(2000);
  expect(orderCount()).toBe(before);
  await expect(page).toHaveURL(/checkout/);

  // 2) Mit Checkbox → Bestellung
  await terms.check();
  await placeOrder(page);
  await page.waitForURL(/order-received/, { timeout: 45000 });
  const o = lastOrder();
  expect(orderCount()).toBe(before + 1);
  expect(o.email).toBe('kaeufer@example.com');
  expect(o.country).toBe('DE');
  expect(Number(o.ship)).toBeCloseTo(6.99, 2);
  expect(['1', 1, true]).toContain(o.terms);
  expect(o.snap.sort()).toEqual(['agb', 'datenschutz', 'widerruf']);

  // Bestellbestätigung an den Kunden enthält den Vertragstext-Snapshot als Anhang (Textform)
  const mails = readFileSync(`${WP_PATH}/wp-content/mail.log`, 'utf8').trim().split('\n').map((l) => JSON.parse(l));
  const confirm = mails.filter((m: any) => m.to === 'kaeufer@example.com').pop();
  expect(confirm, 'Bestellbestätigung an den Kunden').toBeTruthy();
  const att = (confirm.attachments as string[]).find((f) => f.includes('vertragsbedingungen-'));
  expect(att, 'HTML-Anhang mit Vertragsbedingungen').toBeTruthy();
  const html = readFileSync(att!, 'utf8');
  expect(html).toContain('Allgemeine Geschäftsbedingungen');
  expect(html).toContain('Widerrufsbelehrung');
  expect(html).toContain('Datenschutzerklärung');
});

test('Bestellung: individuell angefertigte Ware verlangt zusätzliche Bestätigung', async ({ page }) => {
  if (process.env.LL16_DEBUG) page.on('response', async (r) => { if (r.url().includes('/wc/store') && r.request().method() === 'POST') console.log('API', r.status(), r.url().split('/wp-json')[1], (await r.text()).slice(0, 200)); });
  await addToCart(page, '/product/sonderanfertigung/');
  await fillAddress(page);
  await page.getByRole('radio', { name: /Überweisung/ }).check({ timeout: 20000 });
  await page.getByLabel(/Ich habe AGB, Widerrufsbelehrung/).first().check();
  const before = orderCount();
  await placeOrder(page);
  await expect(page.locator('body')).toContainText(/Ausschluss des Widerrufsrechts/, { timeout: 15000 });
  expect(orderCount()).toBe(before);

  await page.getByLabel(/Nur bei individuell angefertigten Waren/).first().check();
  await placeOrder(page);
  await page.waitForURL(/order-received/, { timeout: 45000 });
  expect(orderCount()).toBe(before + 1);
  expect(['1', 1, true]).toContain(lastOrder().ack);
});

test('Warenkorb (Block): Lieferzeit je Position, Versandhinweis, kein „inkl. MwSt.“', async ({ page }) => {
  await addToCart(page, '/product/base/');
  await page.goto('/cart/', { waitUntil: 'networkidle' });
  await expect(page.getByText(/Lieferzeit/).first()).toBeVisible();
  await expect(page.getByText(/Lieferung nur nach Deutschland/).first()).toBeVisible();
  await expect(page.locator('body')).not.toContainText(/inkl\.?\s*MwSt/i);
});

test('Produktseite BSM: ohne Auswahl nicht kaufbar (klassischer add-to-cart-Link)', async ({ page }) => {
  const id = wp(`echo 'JSON:'.(get_posts(['post_type'=>'product','title'=>'BSM - Basismodul','numberposts'=>1])[0]->ID);`);
  await page.goto(`/?add-to-cart=${id}`, { waitUntil: 'networkidle' });
  const cart = await page.evaluate(async () => (await fetch('/wp-json/wc/store/v1/cart')).json());
  expect(cart.items).toHaveLength(0);                     // nichts im Warenkorb
});
