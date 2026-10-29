import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { chmodSync, existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { delimiter, join } from 'node:path';
import { Cli, named, type Item } from './support/cli';
import {
    clearTheWay, createAccount, expectVault, logOut, newContext, newItem, openItem, unlock, type Account,
} from './support/web-vault';

// Accounts that log in with SSO, at the server of VW_CLIENTS_SSO_URL and its OpenID provider (the fake one
// of fake-services.php), each in a browser of its own: a new account sets its master password at the first
// login and unlocks with it at the later ones, in the web vault and with the CLI, which hands the login to
// the browser; an account with a master password is linked to its identity at the provider.
test.describe.configure({ mode: 'serial' });

const url = process.env.VW_CLIENTS_SSO_URL!;
const account: Account = {
    email: `sso.${Date.now()}@example.com`,
    name: 'SSO Example',
    password: 'SSO master password of the clients test 7a3f',
};
const existing: Account = {
    email: `existing.${Date.now()}@example.com`,
    name: 'Existing Example',
    password: 'Existing master password of the clients test 25e9',
};
const cli = new Cli();

let context: BrowserContext;
let existingContext: BrowserContext;
let page: Page;
let existingPage: Page;

/**
 * A browser in which the provider logs in the account. The fake provider logs in whoever its authorization
 * URL names, so the browser adds the account to the URL the server sends it to.
 */
async function ssoContext(browser: Browser, who: Account): Promise<BrowserContext> {
    const created = await newContext(browser, url);
    await created.route(`${url}/identity/connect/authorize?**`, async (route) => {
        const response = await route.fetch({ maxRedirects: 0 });
        const location = new URL(response.headers()['location']);
        location.searchParams.set('email', who.email);
        location.searchParams.set('name', who.name);
        await route.fulfill({ response, headers: { ...response.headers(), location: location.href } });
    });

    return created;
}

/** The login page, which SSO changes: the email address leads to SSO, or with "Other" to the master password. */
async function startLogIn(page: Page, who: Account, button: 'Use single sign-on' | 'Other'): Promise<void> {
    await page.goto('/#/login');
    await page.locator('input.vw-email-sso').fill(who.email);
    await page.getByRole('button', { name: button, exact: true }).click();
}

test.beforeAll(async ({ browser }) => {
    context = await ssoContext(browser, account);
    existingContext = await ssoContext(browser, existing);
    for (const each of [context, existingContext]) {
        await each.tracing.start({ screenshots: true, snapshots: true });
    }
    page = await context.newPage();
    existingPage = await existingContext.newPage();
    await clearTheWay(page);
    await clearTheWay(existingPage);
});

test.afterAll(async () => {
    await context.tracing.stop({ path: join('test-results', 'sso-trace.zip') });
    await existingContext.tracing.stop({ path: join('test-results', 'sso-existing-trace.zip') });
    await context.close();
    await existingContext.close();
});

test('a new account logs in with SSO and sets its master password', async () => {
    await startLogIn(page, account, 'Use single sign-on');

    // Vaultwarden makes SSO users members of an organization of its own, which needs no members.
    await expect(page.getByRole('heading', { name: 'Join organization' })).toBeVisible();
    await page.getByRole('textbox', { name: 'Master password * (required)', exact: true }).fill(account.password);
    await page.getByRole('textbox', { name: /Confirm master password/ }).fill(account.password);
    // The check asks api.pwnedpasswords.com.
    await page.getByRole('checkbox', { name: 'Check known data breaches for this password' }).uncheck();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expectVault(page);

    const dialog = await newItem(page, 'Login');
    await dialog.getByRole('textbox', { name: /Item name/ }).fill('SSO login');
    await dialog.getByRole('textbox', { name: 'Username' }).fill('sso@example.com');
    await dialog.getByRole('textbox', { name: 'Password' }).fill('sso password');
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(dialog.getByRole('heading', { name: 'View Login' })).toBeVisible();
    await dialog.getByRole('button', { name: 'Close' }).first().click();
});

test('logs in with SSO again and unlocks with the master password', async () => {
    await logOut(page, account);
    await startLogIn(page, account, 'Use single sign-on');
    await unlock(page, account);

    const dialog = await openItem(page, 'SSO login');
    await expect(dialog.getByRole('textbox', { name: 'Username' })).toHaveValue('sso@example.com');
    await dialog.getByRole('button', { name: 'Close' }).first().click();
});

test('the CLI logs in with SSO in the browser and unlocks with the master password', async ({ browser }) => {
    // The CLI waits for the code on localhost and opens the web vault's SSO page with xdg-open on Linux and
    // open elsewhere. These keep the address, and a browser of the tests opens it.
    const bin = mkdtempSync(join(tmpdir(), 'bitwarden-cli-open-'));
    const opened = join(bin, 'opened');
    for (const opener of ['open', 'xdg-open']) {
        writeFileSync(join(bin, opener), `#!/bin/sh\nfor argument in "$@"; do last="$argument"; done\nprintf '%s' "$last" > '${opened}'\n`);
        chmodSync(join(bin, opener), 0o755);
    }

    await cli.run(['config', 'server', url]);
    const login = cli.run(['login', '--sso'], { BW_NOINTERACTION: 'false', PATH: `${bin}${delimiter}${process.env.PATH}` });
    await expect.poll(() => existsSync(opened), { timeout: 30_000 }).toBe(true);

    const cliContext = await ssoContext(browser, account);
    const cliPage = await cliContext.newPage();
    await cliPage.goto(readFileSync(opened, 'utf8'));
    // A browser that has not logged in with SSO before asks for the organization's identifier, which
    // Vaultwarden ignores.
    await cliPage.getByRole('textbox', { name: /SSO identifier/ }).fill('vaultwarden');
    await cliPage.getByRole('button', { name: 'Continue' }).click();
    await expect(cliPage.getByRole('heading', { name: 'Successfully authenticated with the Bitwarden CLI' })).toBeVisible();
    await cliContext.close();
    expect(await login).toContain('You are logged in!');

    cli.session = (await cli.run(['unlock', '--passwordenv', 'BW_PASSWORD', '--raw'], { BW_PASSWORD: account.password })).trim();
    const items = await cli.json<Item[]>(['list', 'items']);
    expect(named(items, 'SSO login').login).toMatchObject({ username: 'sso@example.com', password: 'sso password' });
    await cli.run(['logout']);
});

test('an account with a master password logs in with it, and with SSO, which links it', async () => {
    await createAccount(existingPage, existing);
    await logOut(existingPage, existing);

    await startLogIn(existingPage, existing, 'Other');
    await existingPage.getByRole('textbox', { name: 'Master password * (required)', exact: true }).fill(existing.password);
    await existingPage.getByRole('button', { name: 'Log in', exact: true }).click();
    await expectVault(existingPage);
    await logOut(existingPage, existing);

    // The provider vouches for the address, so the server links the account and keeps its keys.
    await startLogIn(existingPage, existing, 'Use single sign-on');
    await unlock(existingPage, existing);
});
