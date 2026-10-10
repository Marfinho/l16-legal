import { test, expect } from '@playwright/test';

test('Checkout: Button, Hinweise, nur Deutschland, kein Testkarten-Hinweis', async ({ page }) => {
  test.skip(!process.env.LL16_PRODUCT_PATH, 'LL16_PRODUCT_PATH (Produkt mit Standardauswahl) nicht gesetzt');
  await page.goto(process.env.LL16_PRODUCT_PATH!);
  await page.getByRole('button', { name: /in den warenkorb|add to cart/i }).first().click();
  await page.goto(process.env.LL16_CHECKOUT_PATH ?? '/checkout/');
  await expect(page.getByRole('button', { name: 'Zahlungspflichtig bestellen' })).toHaveCount(1);
  await expect(page.getByText(/Widerrufsbelehrung/).first()).toBeVisible();
  await expect(page.getByText(/Testkarte|Testmodus/i)).toHaveCount(0);
  const countries = await page.locator('select[id$="country"] option').allTextContents();
  expect(countries.map((c) => c.trim()).filter((c) => c && !/^select|^bitte/i.test(c))).toEqual([expect.stringMatching(/^(Germany|Deutschland)$/)]);
});

test('Widerruf: zweistufig, ohne Login', async ({ page }) => {
  await page.goto('/vertrag-widerrufen/');
  await page.getByLabel(/Dein Name/).fill('Test Person');
  await page.getByLabel(/Bestellnummer/).fill('999999');
  await page.getByLabel(/E-Mail-Adresse/).fill('test@example.com');
  await page.waitForTimeout(3500); // Zeitprüfung
  await page.getByRole('button', { name: 'Weiter' }).click();
  await expect(page.getByRole('button', { name: 'Widerruf bestätigen' })).toBeVisible();
  await page.waitForTimeout(1500); // Zeitprüfung: Bestätigung erst nach ≥ 1 s
  await page.getByRole('button', { name: 'Widerruf bestätigen' }).click();
  await expect(page.getByText(/Widerruf eingegangen/)).toBeVisible();
});
