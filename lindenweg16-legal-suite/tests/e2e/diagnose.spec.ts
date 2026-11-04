import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Wenn ein anderes Plugin beim Speichern einer Seite abstürzt oder hängt, darf der Nutzer nicht vor einer
 * leeren Fehlerseite stehen: Fehlertext auf der Seite, gespeichert unter Status → Diagnose.
 */
const WP_PATH = process.env.LL16_WP_PATH;
const WPCLI = (process.env.LL16_WPCLI ?? 'wp').split(' ');
test.skip(!WP_PATH, 'LL16_WP_PATH fehlt');
const wp = (...a: string[]) => execFileSync(WPCLI[0], [...WPCLI.slice(1), '--allow-root', `--path=${WP_PATH}`, ...a], { encoding: 'utf8' });

test.afterAll(() => { wp('option', 'delete', 'll16_test_hang_agb'); wp('eval', 'delete_option("ll16_last_error");'); });

test('Hängendes Fremdplugin beim Erzeugen: Fehlertext auf der Seite, Diagnose gespeichert, Rest bedienbar', async ({ page }) => {
  wp('option', 'delete', 'll16_test_hang_agb');
  // Stammdaten vollständig, damit die Seiten wirklich veröffentlicht (also gespeichert) werden
  wp('eval', '$m = \\Lindenweg16\\Legal\\Core\\Masterdata::all(); $m["payment_methods"] = ["PayPal", "Kreditkarte"]; $m["shipping_carrier"] = "DHL"; update_option(\\Lindenweg16\\Legal\\Core\\Masterdata::OPTION, $m);');
  // Die AGB-Seite muss neu angelegt werden, sonst wird sie als unverändert übersprungen und nichts gespeichert
  wp('eval', '$p = get_page_by_path("agb"); if ($p) { wp_delete_post($p->ID, true); }');
  wp('option', 'update', 'll16_test_hang_agb', '1');
  wp('eval', 'delete_option("ll16_last_error");');
  page.on('dialog', (d) => d.accept());
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin'); await page.click('#wp-submit');
  await page.goto('/wp-admin/admin.php?page=ll16-legal&tab=texts');
  await page.getByLabel(/direkt veröffentlichen/).check();
  await page.getByRole('button', { name: /Seiten erzeugen/ }).click();
  await page.waitForLoadState('load');

  // Die Fehlerseite nennt den Fehler (kein leerer Kasten)
  const body = page.locator('body');
  await expect(body).toContainText('Lindenweg16 Legal Suite – Fehler bei „Seiten erzeugen“');
  await expect(body).toContainText('Maximum execution time');
  await expect(body).toContainText('Status → Diagnose');
  await page.screenshot({ path: '/tmp/diag-fehlerseite.png' });

  // Nach dem Aufheben der Ursache: Diagnose zeigt den Fehler und den kopierbaren Bericht
  wp('option', 'delete', 'll16_test_hang_agb');
  await page.goto('/wp-admin/admin.php?page=ll16-legal&tab=status');
  await expect(page.getByText('Letzter aufgezeichneter Fehler')).toBeVisible();
  await expect(page.locator('body')).toContainText('Maximum execution time');
  const report = await page.locator('textarea[readonly]').inputValue();
  expect(report).toContain('Lindenweg16 Legal Suite');
  expect(report).toContain('Maximum execution time');
  await page.screenshot({ path: '/tmp/diag-status.png', fullPage: true });

  // Erneut erzeugen funktioniert (Ursache weg) und die Rechtstexte-Seite verweist nicht mehr auf Probleme
  await page.goto('/wp-admin/admin.php?page=ll16-legal&tab=texts');
  await page.getByRole('button', { name: /Seiten erzeugen/ }).click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('body')).toContainText('/agb/: ok');
});
