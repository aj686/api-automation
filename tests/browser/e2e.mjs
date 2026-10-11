// Phase 14: the master prompt section 99 path, clicked in real Chromium.
// Run with scripts/browser-e2e.sh (throwaway Playwright container). /demo is the
// app's demo/ folder (read-only), /out receives screenshots.
import { chromium } from 'playwright';

const BASE = 'http://api-automation.local';
const PROJECT = 'Zz Browser E2E';
const SLUG = 'zz-browser-e2e';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const problems = [];
page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));

let n = 0;
const shot = async (name) => page.screenshot({ path: `/out/${String(++n).padStart(2, '0')}-${name}.png`, fullPage: true });
const step = async (name, fn) => {
  const t = Date.now();
  try {
    await fn();
    console.log(`PASS  ${name} (${Date.now() - t} ms)`);
  } catch (e) {
    console.log(`FAIL  ${name}: ${e.message.split('\n')[0]}`);
    await shot('FAILED-' + name.replace(/\W+/g, '-'));
    throw e;
  }
};
const expectText = (text, timeout = 15000) => page.getByText(text, { exact: false }).first().waitFor({ timeout });

try {
  await step('dashboard loads with Runner OK', async () => {
    await page.goto(BASE + '/');
    await expectText('Runner OK');
    await shot('dashboard');
  });

  await step('keyboard: first Tab focuses the skip link with a visible outline', async () => {
    await page.keyboard.press('Tab');
    const focus = await page.evaluate(() => {
      const el = document.activeElement;
      const cs = getComputedStyle(el);
      return { text: el.textContent.trim(), outline: cs.outlineStyle, width: cs.outlineWidth };
    });
    if (focus.text !== 'Skip to content' || focus.outline === 'none' || focus.width === '0px') {
      throw new Error('focus ' + JSON.stringify(focus));
    }
    await page.keyboard.press('Tab');
    const second = await page.evaluate(() => ({ text: document.activeElement.textContent.trim(), outline: getComputedStyle(document.activeElement).outlineStyle }));
    if (second.outline === 'none') throw new Error('second focus has no outline ' + JSON.stringify(second));
    await shot('focus-ring');
  });

  await step('create project', async () => {
    await page.goto(BASE + '/projects');
    await page.locator('#name').fill(PROJECT);
    await page.getByRole('button', { name: 'Create project' }).click();
    await page.waitForURL(`**/projects/${SLUG}`);
    await expectText('needs at least one');
  });

  await step('create environment and import the demo environment file', async () => {
    await page.getByRole('link', { name: 'Environments', exact: true }).click();
    await page.locator('#name').fill('Docker');
    await page.getByRole('button', { name: 'Create environment' }).click();
    await page.waitForURL(`**/projects/${SLUG}/environments/docker`);
    await page.locator('#importFile').setInputFiles('/demo/environments/webstack-docker.postman_environment.json');
    await page.waitForTimeout(1500); // upload finishes in the background
    await page.getByRole('button', { name: 'Import', exact: true }).click();
    await expectText('Imported: 2 added, 0 updated.');
    await expectText('http://nginx');
    await shot('environment');
  });

  await step('import the demo collection', async () => {
    await page.getByRole('link', { name: 'Collections', exact: true }).click();
    await page.locator('#collectionFile').setInputFiles('/demo/collections/api-automation-self-check.postman_collection.json');
    await page.waitForTimeout(1500);
    await page.locator('#kind').selectOption('smoke');
    await page.getByRole('button', { name: 'Import collection' }).click();
    await expectText('Imported API Automation self-check (3 requests).');
    await shot('collections');
  });

  await step('press Run and get PASS 6/6', async () => {
    await page.getByRole('link', { name: 'Overview', exact: true }).click();
    await page.getByRole('button', { name: 'Run', exact: true }).click();
    await expectText('QUEUED', 10000).catch(() => {}); // may already have moved on
    await expectText('6/6 passed', 90000);
    await shot('run-passed');
  });

  await step('open the run: 6 rows, filter, log', async () => {
    await page.getByRole('link', { name: /API Automation self-check → Docker/ }).first().click();
    await page.waitForURL('**/runs/*');
    await expectText('Results');
    const rows = await page.locator('tr[wire\\:key^="result-"]').count();
    if (rows !== 6) throw new Error(`expected 6 result rows, saw ${rows}`);
    // The filter is a group of radio labels; target that control, not the "Failed" summary box.
    await page.locator('label:has(input[name="resultFilter"])', { hasText: 'Failed' }).click();
    await expectText('No results match this filter.');
    await page.locator('label:has(input[name="resultFilter"])', { hasText: 'Passed' }).click();
    // Wait for Livewire to re-render rather than counting straight after the click.
    await page.waitForFunction(() => document.querySelectorAll('tr[wire\\:key^="result-"]').length === 6, null, { timeout: 10000 });
    await page.getByText('Execution log').click();
    await expectText('status is 200');
    await shot('run-detail');
  });

  await step('capture URL: create, enable, receive a call, credential header kept as [present]', async () => {
    await page.goto(`${BASE}/projects/${SLUG}/captures`);
    await page.locator('#name').fill('Hook');
    await page.getByRole('button', { name: 'Create capture URL' }).click();
    await expectText('shown only once');
    const url = (await page.locator('dt:has-text("From your PC") + dd code').innerText()).trim();
    if (!/\/capture\/\d+\/[A-Za-z0-9]{40}$/.test(url)) throw new Error('unexpected URL ' + url);

    // Disabled: refused.
    const refused = await fetch(url + '/orders/paid', { method: 'POST' });
    if (refused.status !== 404) throw new Error('disabled bucket answered ' + refused.status);

    await page.getByRole('button', { name: 'Enable', exact: true }).click();
    await expectText('Enabled');
    const res = await fetch(url + '/orders/paid', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer YOUR_ACCESS_TOKEN_e2e' },
      body: JSON.stringify({ order: 7, status: 'paid', amount: 25.5 }),
    });
    if (res.status !== 200) throw new Error('capture answered ' + res.status);

    await expectText('/orders/paid', 15000); // page polls every 5 s
    await page.locator('details summary').first().click();
    await expectText('[present]');
    const html = await page.content();
    if (html.includes('YOUR_ACCESS_TOKEN_e2e')) throw new Error('credential value rendered');
    await shot('captures');
  });

  await step('settings page shows the API state without the token', async () => {
    await page.goto(BASE + '/settings');
    await expectText('Automation API (n8n)');
    await shot('settings');
  });

  await step('delete the project by typing its name', async () => {
    await page.goto(`${BASE}/projects/${SLUG}`);
    await page.locator('#confirmName').fill(PROJECT);
    await page.getByRole('button', { name: 'Delete project' }).click();
    await page.waitForURL('**/projects');
    await expectText(`Deleted project ${PROJECT}. Its run history was kept.`);
  });
} finally {
  await browser.close();
  if (problems.length) {
    console.log('BROWSER ERRORS:\n  ' + problems.join('\n  '));
    process.exitCode = 1;
  } else {
    console.log('no browser console errors');
  }
}
