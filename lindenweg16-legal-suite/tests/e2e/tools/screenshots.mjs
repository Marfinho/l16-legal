// Erzeugt die Screenshot-Strecke (Vorher/Nachher und alle Meldungen) gegen eine laufende Testinstanz.
//   LL16_BASE_URL, LL16_WP_PATH, LL16_WPCLI, LL16_CHROMIUM, OUT (Zielordner)
import { chromium } from '../node_modules/playwright-core/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.LL16_BASE_URL, WP_PATH = process.env.LL16_WP_PATH;
const WPCLI = (process.env.LL16_WPCLI ?? 'wp').split(' ');
const OUT = process.env.OUT ?? 'screenshots';
mkdirSync(OUT, { recursive: true });
const wp = (...a) => execFileSync(WPCLI[0], [...WPCLI.slice(1), '--allow-root', `--path=${WP_PATH}`, ...a], { encoding: 'utf8' });
const modules = (on) => wp('option', 'update', 'll16_modules', JSON.stringify(Object.fromEntries(['consent', 'gate', 'legal', 'withdrawal', 'checkout', 'product', 'forms', 'security', 'monitor'].map((k) => [k, on]))), '--format=json');
const resetRL = () => wp('eval', `global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ll16_rl_%' OR option_name LIKE '%ll16_wd_%'"); echo 1;`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await chromium.launch({ executablePath: process.env.LL16_CHROMIUM ?? '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
const D = { width: 1280, height: 900 }, M = { width: 390, height: 844 };
async function ctx(vp = D, opts = {}) { return browser.newContext({ viewport: vp, locale: 'de-DE', deviceScaleFactor: 1, ...opts }); }
async function decide(p, what = 'Ablehnen') { const b = p.getByRole('button', { name: what }); if (await b.isVisible().catch(() => false)) await b.click(); }
const file = (n) => `${OUT}/${n}.png`;
async function shot(p, name, o = {}) { await sleep(300); await p.screenshot({ path: file(name), fullPage: o.full ?? false, clip: o.clip }); console.log('✓', name); }

/** Zwei Aufnahmen nebeneinander (Vorher/Nachher) */
async function pair(name, beforePath, afterPath, titleL, titleR) {
  const p = await (await ctx({ width: 2200, height: 1200 })).newPage();
  const b64 = (f) => 'data:image/png;base64,' + readFileSync(f).toString('base64');
  await p.setContent(`<body style="margin:0;font:16px system-ui;background:#eee"><div style="display:flex;gap:24px;padding:24px;align-items:flex-start">
    <figure style="margin:0;flex:1"><figcaption style="font-weight:700;padding:8px 0;color:#b00020">${titleL}</figcaption><img src="${b64(beforePath)}" style="width:100%;border:1px solid #bbb"></figure>
    <figure style="margin:0;flex:1"><figcaption style="font-weight:700;padding:8px 0;color:#1a7f37">${titleR}</figcaption><img src="${b64(afterPath)}" style="width:100%;border:1px solid #bbb"></figure></div></body>`);
  await sleep(300);
  await p.screenshot({ path: file(name), fullPage: true });
  await p.context().close();
  console.log('✓', name);
}

async function checkoutFill(p, email = 'erika@example.com') {
  await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
  await p.getByLabel(/^(E-Mail-Adresse|Email address)$/i).first().fill(email);
  await p.getByLabel(/^(vorname|first name)$/i).first().fill('Erika');
  await p.getByLabel(/^(nachname|last name)$/i).first().fill('Muster');
  await p.getByLabel(/^(adresse|address)$/i).first().fill('Musterstraße 1');
  await p.getByLabel(/^(postleitzahl|postal code)$/i).first().fill('31559');
  await p.getByLabel(/^(stadt|city)$/i).first().fill('Haste');
  await p.getByRole('radio', { name: /Überweisung/ }).check({ timeout: 20000 }).catch(() => {});
  await p.waitForLoadState('networkidle'); await sleep(800);
}
async function addToCart(p, slug) {
  await p.goto(BASE + '/product/' + slug + '/', { waitUntil: 'networkidle' });
  await decide(p);
  await p.getByRole('button', { name: /in den warenkorb|add to cart/i }).first().click();
  await sleep(1500);
}

/* ============ A) VORHER (Module aus) ============ */
if (!process.env.ONLY_PAIRS) {
modules(false);
{
  const c = await ctx(); const p = await c.newPage();
  await p.goto(BASE + '/product/base/', { waitUntil: 'networkidle' }); await shot(p, '_before_produkt', { full: false });
  await addToCart(p, 'base');
  await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' }); await shot(p, '_before_warenkorb', { full: true });
  await checkoutFill(p); await shot(p, '_before_checkout', { full: true });
  await p.goto(BASE + '/gate-test/', { waitUntil: 'networkidle' }); await shot(p, '_before_karte', { full: false });
  await c.close();
  const cm = await ctx(M); const pm = await cm.newPage();
  await pm.goto(BASE + '/product/base/', { waitUntil: 'networkidle' }); await shot(pm, '_before_produkt_mobil');
  await cm.close();
}

/* ============ B) NACHHER (Module an) ============ */
modules(true); resetRL();
{
  // 1) Banner
  let c = await ctx(); let p = await c.newPage();
  await p.goto(BASE + '/product/base/', { waitUntil: 'networkidle' }); await shot(p, '01-cookie-banner-desktop');
  await p.getByRole('button', { name: 'Einstellungen' }).click(); await shot(p, '02-cookie-banner-einstellungen');
  await c.close();
  c = await ctx(M); p = await c.newPage();
  await p.goto(BASE + '/product/base/', { waitUntil: 'networkidle' }); await shot(p, '03-cookie-banner-handy');
  await p.getByRole('button', { name: 'Einstellungen' }).click(); await shot(p, '04-cookie-banner-handy-einstellungen');
  await c.close();
  c = await ctx(M, { colorScheme: 'dark' }); p = await c.newPage();
  await p.goto(BASE + '/product/base/', { waitUntil: 'networkidle' }); await shot(p, '05-cookie-banner-handy-dunkel');
  await c.close();

  // 2) Karte: Zwei-Klick
  c = await ctx(); p = await c.newPage();
  await p.goto(BASE + '/gate-test/', { waitUntil: 'networkidle' }); await decide(p); await shot(p, '_after_karte');
  await c.close();

  // 3) Produktseite, Warenkorb, Checkout
  c = await ctx(); p = await c.newPage();
  await p.goto(BASE + '/product/base/', { waitUntil: 'networkidle' }); await decide(p); await shot(p, '_after_produkt');
  await addToCart(p, 'base');
  await p.goto(BASE + '/cart/', { waitUntil: 'networkidle' }); await shot(p, '_after_warenkorb', { full: true });
  await checkoutFill(p); await shot(p, '_after_checkout', { full: true });

  // Checkout-Fehler: Pflicht-Checkbox fehlt
  const btn = p.getByRole('button', { name: 'Zahlungspflichtig bestellen' });
  await btn.click(); await sleep(1500); await btn.click().catch(() => {}); await sleep(1800);
  await p.evaluate(() => document.querySelector('.wc-block-components-validation-error, .has-error')?.scrollIntoView({ block: 'center' }));
  await shot(p, '09-checkout-fehler-pflicht-checkbox');
  await c.close();

  // Individuelle Ware: Bestätigung fehlt
  c = await ctx(); p = await c.newPage();
  await addToCart(p, 'sonderanfertigung');
  await checkoutFill(p, 'individuell@example.com');
  await p.getByLabel(/Ich habe AGB, Widerrufsbelehrung/).first().check();
  await p.waitForLoadState('networkidle'); await sleep(800);
  for (let i = 0; i < 3; i++) { await p.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click(); await sleep(1800); if (await p.getByText(/Ausschluss des Widerrufsrechts/).first().isVisible().catch(() => false)) break; }
  await p.evaluate(() => window.scrollTo(0, 0)); await shot(p, '10-checkout-fehler-individuelle-ware');
  await p.getByLabel(/Nur bei individuell angefertigten Waren/).first().check(); await sleep(800);
  for (let i = 0; i < 3; i++) { await p.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click(); await sleep(2500); if (/order-received/.test(p.url())) break; }
  await p.waitForURL(/order-received/, { timeout: 30000 }).catch(() => {});
  await shot(p, '11-bestellung-bestaetigt', { full: true });
  await c.close();

  // 4) Widerruf
  c = await ctx(); p = await c.newPage();
  await p.goto(BASE + '/vertrag-widerrufen/', { waitUntil: 'networkidle' }); await decide(p);
  await shot(p, '12-widerruf-formular', { full: true });
  await p.getByLabel(/Dein Name/).fill('Erika Muster');
  await p.getByLabel(/Bestellnummer/).fill('abc');
  await p.getByLabel(/E-Mail-Adresse/).evaluate((e) => { e.type = 'text'; e.value = 'erika@beispiel'; });
  await sleep(2500);
  await p.getByRole('button', { name: 'Weiter' }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '13-widerruf-fehler', { full: true });
  const orderNo = wp('eval', `$o=wc_get_orders(['limit'=>1,'orderby'=>'id','order'=>'DESC','status'=>['wc-on-hold','wc-processing','wc-completed']])[0]; echo $o->get_order_number().'|'.$o->get_billing_email();`).trim().split('|');
  await p.getByLabel(/Bestellnummer/).fill(orderNo[0]);
  await p.getByLabel(/E-Mail-Adresse/).evaluate((e, v) => { e.type = 'email'; e.value = v; }, orderNo[1]);
  await sleep(2500);
  await p.getByRole('button', { name: 'Weiter' }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '14-widerruf-pruefen', { full: true }); await sleep(1500);
  wp('eval', `@unlink(WP_CONTENT_DIR.'/mail.log'); echo 1;`);
  await p.getByRole('button', { name: 'Widerruf bestätigen' }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '15-widerruf-bestaetigt', { full: true });
  await c.close();
  c = await ctx(M); p = await c.newPage();
  await p.goto(BASE + '/vertrag-widerrufen/', { waitUntil: 'networkidle' }); await decide(p);
  await shot(p, '16-widerruf-formular-handy', { full: true });
  await c.close();

  // Mail: Eingangsbestätigung als Bild
  const mails = readFileSync(`${WP_PATH}/wp-content/mail.log`, 'utf8').trim().split('\n').map((l) => JSON.parse(l));
  const m = mails.find((x) => /Eingangsbest/.test(x.subject)), s = mails.find((x) => /^Widerruf eingegangen/.test(x.subject));
  c = await ctx({ width: 900, height: 700 }); p = await c.newPage();
  const esc = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  await p.setContent(`<body style="margin:0;background:#f0f0f0;font:15px/1.5 system-ui"><div style="display:flex;gap:20px;padding:20px">
   ${[['E-Mail an den Verbraucher', m], ['E-Mail an dich (Shop)', s]].map(([t, x]) => `<div style="flex:1;background:#fff;border:1px solid #ccc;border-radius:6px;padding:14px"><div style="color:#666;font-size:13px">${t}</div><div style="color:#666;font-size:13px">An: ${esc(x.to)}</div><div style="font-weight:700;margin:6px 0 10px">Betreff: ${esc(x.subject)}</div><pre style="white-space:pre-wrap;font:inherit;margin:0">${esc(x.message)}</pre></div>`).join('')}</div></body>`);
  await shot(p, '17-widerruf-mails', { full: true });
  await c.close();

  // 5) Kontakt / Newsletter
  resetRL();
  c = await ctx(M); p = await c.newPage();
  await p.goto(BASE + '/kontakt-test/', { waitUntil: 'networkidle' }); await decide(p); await shot(p, '18-kontakt-handy', { full: true });
  await c.close();
  c = await ctx(); p = await c.newPage();
  await p.goto(BASE + '/kontakt-test/', { waitUntil: 'networkidle' }); await decide(p);
  await p.getByLabel('Name *').fill('Anna Beispiel');
  await p.getByLabel('E-Mail *').fill('anna@beispiel');
  await p.getByLabel('Nachricht *').fill('Hallo, ich habe eine Frage zum Wandsystem.\nKönnt ihr mir die Tragfähigkeit nennen?');
  await p.getByLabel(/Datenschutzerklärung gelesen/).check();
  await p.evaluate(() => document.querySelectorAll('[required]').forEach((e) => e.removeAttribute('required')));
  await p.evaluate(() => { const e = document.querySelector('#ll16_email'); e.type = 'text'; });
  await sleep(3800);
  await p.getByRole('button', { name: 'Absenden' }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '19-kontakt-fehler-werte-bleiben', { full: true });
  await p.getByLabel('E-Mail *').fill('anna@example.com'); await p.getByLabel(/Datenschutzerklärung gelesen/).check();
  await sleep(3500);
  await p.getByRole('button', { name: 'Absenden' }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '20-kontakt-erfolg', { full: true });
  await p.goto(BASE + '/newsletter-test/', { waitUntil: 'networkidle' });
  await p.getByLabel('E-Mail *').fill('news@example.com'); await p.getByLabel(/Newsletter erhalten/).check(); await sleep(3800);
  await p.getByRole('button', { name: 'Anmelden' }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '21-newsletter-bestaetigungsmail-hinweis', { full: true });
  await c.close();

  // 6) Rechtstexte + Footer
  c = await ctx(); p = await c.newPage();
  await p.goto(BASE + '/impressum/', { waitUntil: 'networkidle' }); await decide(p); await shot(p, '22-impressum', { full: true });
  await p.goto(BASE + '/widerruf/', { waitUntil: 'networkidle' }); await shot(p, '23-widerrufsbelehrung', { full: false });
  await p.goto(BASE + '/impressum/', { waitUntil: 'networkidle' });
  await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await shot(p, '24-footer-pflichtlinks');
  await c.close();

  // 7) Admin
  c = await ctx({ width: 1360, height: 900 }); p = await c.newPage();
  await p.goto(BASE + '/wp-login.php'); await p.fill('#user_login', 'admin'); await p.fill('#user_pass', 'admin'); await p.click('#wp-submit'); await p.waitForLoadState('networkidle');
  wp('eval', 'delete_option("ll16_last_check"); echo 1;');
  await p.goto(BASE + '/wp-admin/admin.php?page=ll16-legal&tab=monitor'); await p.getByRole('button', { name: 'Prüfung jetzt starten' }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '30-admin-monitor', { full: true });
  await p.goto(BASE + '/wp-admin/admin.php?page=ll16-legal&tab=status'); await shot(p, '25-admin-status', { full: true });
  await p.goto(BASE + '/wp-admin/admin.php?page=ll16-legal&tab=master');
  await p.fill('#m_email', 'sven@lindenweg16'); await p.fill('#m_zip', '3155'); await p.fill('#m_shipping_cost', '6.99');
  await p.getByRole('button', { name: /Änderungen speichern|Save Changes/ }).click(); await p.waitForLoadState('networkidle');
  await shot(p, '26-admin-stammdaten-fehlerhinweis', { full: true });
  await p.goto(BASE + '/wp-admin/admin.php?page=ll16-legal&tab=modules'); await shot(p, '27-admin-module', { full: true });
  await p.goto(BASE + '/wp-admin/admin.php?page=ll16-legal&tab=services'); await shot(p, '28-admin-dienste', { full: true });
  await p.goto(BASE + '/wp-admin/admin.php?page=ll16-legal&tab=texts'); await shot(p, '29-admin-rechtstexte', { full: true });
  await c.close();
}
}

/* ============ C) Paare ============ */
await pair('A1-vorher-nachher-produktseite', file('_before_produkt'), file('_after_produkt'), 'VORHER: Produktseite ohne Plugin-Module', 'NACHHER: mit Preisangaben, Lieferzeit, Gewährleistung');
await pair('A2-vorher-nachher-warenkorb', file('_before_warenkorb'), file('_after_warenkorb'), 'VORHER: Warenkorb ohne Plugin-Module', 'NACHHER: Lieferzeit je Position, Versand-/Länderhinweis');
await pair('A3-vorher-nachher-checkout', file('_before_checkout'), file('_after_checkout'), 'VORHER: Checkout ohne Plugin-Module', 'NACHHER: Button-Text, Rechtshinweis, Pflicht-Checkbox, nur Deutschland');
await pair('A4-vorher-nachher-karte', file('_before_karte'), file('_after_karte'), 'VORHER: Google-Karte lädt sofort (Daten an Google)', 'NACHHER: Zwei-Klick-Platzhalter, nichts lädt vorab');
await pair('A5-vorher-nachher-produktseite-handy', file('_before_produkt_mobil'), file('03-cookie-banner-handy'), 'VORHER: Handy ohne Plugin', 'NACHHER: Handy mit Cookie-Banner (erster Besuch)');
await browser.close();
