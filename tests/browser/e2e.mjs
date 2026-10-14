// Phase 14: the master prompt section 99 path, clicked in real Chromium.
// Run with scripts/browser-e2e.sh (throwaway Playwright container). /demo is the
// app's demo/ folder (read-only), /out receives screenshots.
import { chromium } from 'playwright';

const BASE = 'http://api-automation.local';
const PROJECT = 'Zz Browser E2E';
const SLUG = 'zz-browser-e2e';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
// E2E_THEME=dark (or light) runs the same steps in that theme, for screenshots of both.
if (process.env.E2E_THEME) await page.addInitScript((theme) => { try { localStorage.setItem('theme', theme) } catch (e) {} }, process.env.E2E_THEME);
const problems = [];
// With E2E_ALLOW_HOT=1 a running `npm run dev` is tolerated: only errors for its own
// origin (unreachable from this container) are ignored; anything else still fails.
const hotOrigin = process.env.E2E_HOT_ORIGIN || '';
page.on('console', (m) => {
  if (m.type() !== 'error') return;
  if (hotOrigin && (m.location().url || '').startsWith(hotOrigin)) return;
  problems.push('console: ' + m.text() + (m.location().url ? ' (' + m.location().url + ')' : ''));
});
page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
// E2E_DEBUG=1: print every Livewire call (methods only, never payloads) and its HTTP status.
if (process.env.E2E_DEBUG) {
  page.on('request', (r) => { if (r.url().includes('/livewire') && r.method() === 'POST') { const m = (r.postData() || '').match(/"method":"[a-zA-Z]+"/g); console.log('   -> livewire', m ? m.join(',') : '(property updates)'); } });
  page.on('response', (r) => { if (r.url().includes('/livewire') && r.request().method() === 'POST') console.log('   <-', r.status()); });
}

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
const rail = () => page.getByRole('navigation', { name: 'Main' });
const sidePanel = () => page.getByRole('complementary', { name: 'Sidebar' });
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
    // Workspace shell (D-040): the rail switches the side panel; its "New" link opens the form.
    await rail().getByRole('button', { name: 'Environments' }).click();
    await sidePanel().getByRole('link', { name: 'New' }).click();
    await page.waitForURL(`**/projects/${SLUG}/environments**`);
    await page.locator('#name').fill('Docker');
    await page.getByRole('button', { name: 'Create environment' }).click();
    await page.waitForURL(`**/projects/${SLUG}/environments/docker`);
    await page.locator('#importFile').setInputFiles('/demo/environments/webstack-docker.postman_environment.json');
    await page.waitForTimeout(1500); // upload finishes in the background
    await page.getByRole('button', { name: 'Import', exact: true }).click();
    await expectText('Imported: 2 added, 0 updated.');
    await expectText('http://nginx');
    // The new environment is the active one, top right.
    if (! (await page.locator('#active-environment').innerText()).includes('Docker')) throw new Error('Docker is not the active environment');
    await shot('environment');
  });

  await step('import the demo collection', async () => {
    await rail().getByRole('button', { name: 'Collections' }).click();
    await sidePanel().getByRole('link', { name: /Import/ }).first().click();
    await page.waitForURL(`**/projects/${SLUG}/collections**`);
    await page.locator('#collectionFile').setInputFiles('/demo/collections/api-automation-self-check.postman_collection.json');
    await page.waitForTimeout(1500);
    // <x-dropdown> (D-040): pick by clicking the option.
    await page.locator('#kind').click();
    await page.getByRole('listbox').getByRole('option', { name: 'smoke' }).click();
    if (! (await page.locator('#kind').innerText()).includes('smoke')) throw new Error('kind dropdown did not take "smoke"');
    // Keyboard: ArrowDown opens it, Escape closes it and returns focus.
    await page.locator('#kind').focus();
    await page.keyboard.press('ArrowDown');
    await page.getByRole('listbox').waitFor();
    await page.keyboard.press('Escape');
    await page.getByRole('listbox').waitFor({ state: 'hidden' });
    await page.getByRole('button', { name: 'Import collection' }).click();
    await expectText('Imported API Automation self-check (3 requests).');
    await page.reload();
    await sidePanel().getByText('API Automation self-check').waitFor();
    await shot('collections');
  });

  await step('press Run and get PASS 6/6', async () => {
    await rail().getByRole('link', { name: 'Overview' }).click();
    await page.waitForURL(`**/projects/${SLUG}`);
    await page.getByRole('button', { name: 'Run', exact: true }).click();
    await expectText('QUEUED', 10000).catch(() => {}); // may already have moved on
    await expectText('6/6 passed', 90000);
    await shot('run-passed');
  });

  await step('open the run: request cards with payloads, filters, log (D-037)', async () => {
    await page.locator('#main').getByRole('link', { name: /API Automation self-check → Docker/ }).first().click();
    await page.waitForURL('**/runs/*');
    await expectText('Results');
    const cards = () => page.locator('article[wire\\:key^="request-"]');
    if ((await cards().count()) !== 3) throw new Error(`expected 3 request cards, saw ${await cards().count()}`);

    // The response body is readable without clicking anything.
    const refused = cards().filter({ hasText: 'API refuses a call without a token' });
    await refused.getByText('Missing or invalid API token.').waitFor({ timeout: 5000 });
    if (!(await refused.getByText('Missing or invalid API token.').isVisible())) throw new Error('response body not visible without a click');
    await refused.getByText('401 Unauthorized').waitFor();

    // Tabs switch in the browser.
    await refused.getByRole('tab', { name: 'Response headers' }).click();
    await refused.getByText('Content-Type', { exact: true }).waitFor({ timeout: 5000 });
    if (await refused.getByText('Missing or invalid API token.').isVisible()) throw new Error('body still visible on the headers tab');
    await refused.getByRole('tab', { name: 'Request' }).click();
    await refused.getByText('Request headers').waitFor();

    // Accordion: the header folds the card; "Expand all" opens every card again.
    await refused.getByRole('button', { name: /API refuses a call without a token/ }).click();
    await page.waitForFunction(() => [...document.querySelectorAll('article[wire\\:key^="request-"]')].some((a) => a.querySelector('[aria-expanded="false"]')), null, { timeout: 5000 });
    await refused.getByText('Request headers').waitFor({ state: 'hidden', timeout: 5000 }); // folded card hides its payload
    await page.getByRole('button', { name: 'Collapse all' }).click();
    await page.waitForFunction(() => document.querySelectorAll('article[wire\\:key^="request-"] [aria-expanded="true"]').length === 0, null, { timeout: 5000 });
    await page.getByRole('button', { name: 'Expand all' }).click();
    await page.waitForFunction(() => document.querySelectorAll('article[wire\\:key^="request-"] [aria-expanded="false"]').length === 0, null, { timeout: 5000 });
    await refused.getByText('Request headers').waitFor();

    await page.locator('label:has(input[name="resultFilter"])', { hasText: 'Failed' }).click();
    await expectText('No results match this filter.');
    await page.locator('label:has(input[name="resultFilter"])', { hasText: 'Passed' }).click();
    await page.waitForFunction(() => document.querySelectorAll('article[wire\\:key^="request-"]').length === 3, null, { timeout: 10000 });
    await page.getByText('Execution log').click();
    await expectText('status is 200');
    await shot('run-detail');
  });

  await step('collection workbench: tree, request tabs, Ctrl+S save, Send shows the response (D-038)', async () => {
    // Open the collection from the side panel's tree, then switch requests there (no page load).
    await rail().getByRole('button', { name: 'Collections' }).click();
    const tree = sidePanel();
    await tree.getByRole('link', { name: 'API Automation self-check', exact: true }).click();
    await page.waitForURL('**/collections/api-automation-self-check**');
    await tree.getByText('API refuses a call without a token').waitFor();
    await tree.getByText('Unknown API path is a clean 404').waitFor();
    await tree.getByRole('link', { name: /Health page/ }).click();
    await page.waitForFunction(() => document.querySelector('#form-name')?.value === 'Health page', null, { timeout: 10000 });
    const openTabs = page.getByRole('navigation', { name: 'Open tabs' });
    await openTabs.getByRole('link', { name: /Health page/ }).waitFor({ timeout: 5000 });

    for (const tab of ['Authorization', 'Headers', 'Body']) {
      await page.getByRole('tab', { name: new RegExp('^' + tab) }).click();
      await page.locator(`#panel-${{ Authorization: 'auth', Headers: 'headers', Body: 'body' }[tab]}`).waitFor({ state: 'visible', timeout: 5000 });
    }
    await page.getByRole('tab', { name: /^Scripts/ }).click();
    if (!(await page.locator('#script-test').inputValue()).includes('pm.test')) throw new Error('test script not shown');

    await page.locator('#form-name').fill('Health page (edited)');
    await page.locator('#form-name').blur();
    // In the page, not the tab bar (its hidden ", unsaved changes" label also matches).
    await page.locator('#main').getByText('Unsaved').first().waitFor({ timeout: 15000 });
    await page.keyboard.press('Control+s');
    await expectText('Saved Health page (edited).');

    await page.getByRole('button', { name: 'Send', exact: true }).click();
    await page.getByRole('heading', { name: 'Response' }).waitFor({ timeout: 10000 });
    await page.locator('article[wire\\:key^="request-"]').first().waitFor({ timeout: 60000 });
    await page.locator('article[wire\\:key^="request-"]').first().getByText('200 OK').waitFor({ timeout: 10000 });
    await shot('workbench-send');
  });

  await step('runner: drag, untick, move up, stop on error, run in the chosen order (D-038)', async () => {
    await page.getByRole('navigation', { name: 'Collection' }).getByRole('button', { name: /Runner/ }).click();
    await expectText('Run 3 of 3 requests');
    await page.getByRole('navigation', { name: 'Open tabs' }).getByRole('link', { name: /Runner/ }).waitFor({ timeout: 5000 });
    const rows = page.locator('ol[aria-label="Requests in run order"] > li');
    const order = async () => (await rows.allInnerTexts()).map((t) => (t.match(/(Health page \(edited\)|API refuses a call without a token|Unknown API path is a clean 404)/) || [t])[0]);

    // Drag by the handle (SortableJS via wire:sort), at human speed: faster moves
    // fall inside Sortable's 150 ms animation and only the first swap counts.
    // The first drag after load must work too (it did not before D-040).
    const drag = async (fromIdx, toIdx) => {
      const h = await rows.nth(fromIdx).locator('[wire\\:sort\\:handle]').boundingBox();
      const t = await rows.nth(toIdx).boundingBox();
      const x = h.x + h.width / 2, y0 = h.y + h.height / 2, y1 = t.y + t.height / 4;
      await page.mouse.move(x, y0);
      await page.mouse.down();
      for (let i = 1; i <= 20; i++) { await page.mouse.move(x, y0 + (y1 - y0) * (i / 20)); await page.waitForTimeout(40); }
      await page.mouse.up();
      await page.waitForTimeout(1000);
    };
    await drag(2, 0);
    let now = await order();
    if (now.join('|') !== 'Unknown API path is a clean 404|Health page (edited)|API refuses a call without a token') throw new Error('drag gave ' + now.join(' | '));

    await rows.filter({ hasText: 'Unknown API path' }).locator('input[type="checkbox"]').uncheck();
    await expectText('Run 2 of 3 requests');
    await page.getByRole('button', { name: 'Move API refuses a call without a token up' }).click();
    await page.waitForFunction(() => document.querySelectorAll('ol[aria-label="Requests in run order"] > li')[1]?.innerText.includes('API refuses'), null, { timeout: 5000 });
    await page.getByLabel(/Stop run if an error occurs/).check();
    await shot('runner');

    await page.getByRole('button', { name: /Run 2 of 3 requests/ }).click();
    await page.waitForURL('**/runs/*');
    await expectText('API refuses a call without a token → Health page (edited) · stop on error');
    await expectText('Results', 90000);
    const cards = page.locator('article[wire\\:key^="request-"]');
    await page.waitForFunction(() => document.querySelectorAll('article[wire\\:key^="request-"]').length === 2, null, { timeout: 90000 });
    if (!(await cards.nth(0).innerText()).includes('API refuses')) throw new Error('first card is not the first chosen request');
    if (!(await cards.nth(1).innerText()).includes('Health page (edited)')) throw new Error('second card is not the edited Health page');
    await shot('run-sequence');
  });

  await step('workspace: close a tab, drag the side panel and the request/response divider (D-040)', async () => {
    await page.goto(`${BASE}/projects/${SLUG}/collections/api-automation-self-check?pane=runner`);
    const openTabs = page.getByRole('navigation', { name: 'Open tabs' });
    await openTabs.getByRole('link', { name: /Runner/ }).waitFor({ timeout: 5000 });
    const before = await openTabs.getByRole('link').count();
    await page.getByRole('button', { name: /^Close Runner/ }).click();
    await page.waitForFunction((n) => document.querySelectorAll('nav[aria-label="Open tabs"] a').length === n - 1, before, { timeout: 10000 });

    const width = async () => (await page.locator('#sidebar-panel').boundingBox()).width;
    const start = await width();
    const sep = await page.getByRole('separator', { name: 'Resize the side panel' }).boundingBox();
    await page.mouse.move(sep.x + 3, sep.y + 200); await page.mouse.down();
    await page.mouse.move(sep.x + 90, sep.y + 200, { steps: 8 }); await page.mouse.up();
    if ((await width()) < start + 60) throw new Error(`side panel did not widen (${start} → ${await width()})`);

    await page.locator('nav[aria-label="Open tabs"] a', { hasText: 'Health page' }).first().click();
    const split = page.getByRole('separator', { name: 'Resize request and response' });
    await split.waitFor({ timeout: 10000 });
    // Response sits under the request; dragging the divider down makes the request taller.
    const body = page.locator('[data-stack-body]');
    const h0 = (await body.boundingBox()).height;
    const s = await split.boundingBox();
    const response = await page.locator('[aria-label="Resize request and response"] + div').boundingBox();
    if (response.y < s.y + s.height) throw new Error('response is not below the request');
    await page.mouse.move(s.x + s.width / 2, s.y + s.height / 2); await page.mouse.down();
    await page.mouse.move(s.x + s.width / 2, s.y + s.height / 2 + 100, { steps: 8 }); await page.mouse.up();
    const h1 = (await body.boundingBox()).height;
    if (h1 < h0 + 60) throw new Error(`request pane did not grow (${h0} → ${h1})`);
    await shot('workspace');
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
    await page.locator('#main details summary').first().click();
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
