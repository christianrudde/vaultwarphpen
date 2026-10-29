import { expect } from '@playwright/test';
import { execFile } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const bw = require.resolve('@bitwarden/cli/build/bw.js');

/** An item as `bw list items` prints it, decrypted. */
export interface Item {
    id: string;
    type: number;
    name: string;
    notes: string | null;
    folderId: string | null;
    organizationId: string | null;
    collectionIds: string[];
    login?: { username: string | null; password: string | null; uris: { uri: string }[] };
    secureNote?: { type: number };
    card?: { cardholderName: string; brand: string; number: string; expMonth: string; expYear: string; code: string };
    passwordHistory: { password: string }[] | null;
    attachments?: { fileName: string }[];
}

export interface Named {
    id: string;
    name: string;
}

/** The entry of the list with the name. */
export function named<T extends Named>(list: T[], name: string): T {
    const found = list.find((entry) => entry.name === name);
    expect(found, `${name} in ${JSON.stringify(list.map((entry) => entry.name))}`).toBeDefined();

    return found!;
}

/**
 * The official Bitwarden CLI (@bitwarden/cli), with a data folder of its own.
 * It trusts the server's certificate through NODE_EXTRA_CA_CERTS.
 */
export class Cli {
    readonly appData = mkdtempSync(join(tmpdir(), 'bitwarden-cli-'));
    session = '';

    run(args: string[], env: Record<string, string> = {}): Promise<string> {
        return new Promise((resolve, reject) => {
            execFile(process.execPath, [bw, ...args], {
                env: {
                    ...process.env,
                    BITWARDENCLI_APPDATA_DIR: this.appData,
                    BW_NOINTERACTION: 'true',
                    BW_SESSION: this.session,
                    ...env,
                },
                maxBuffer: 64 * 1024 * 1024,
                timeout: 120_000,
            }, (error, stdout, stderr) => {
                if (error) {
                    reject(new Error(`bw ${args[0]} failed (${error.code ?? error.signal}): ${stderr || stdout}`));
                } else {
                    resolve(stdout);
                }
            });
        });
    }

    async json<T>(args: string[]): Promise<T> {
        return JSON.parse(await this.run(args)) as T;
    }

    /** What `bw create` and `bw edit` take: the object as base64-encoded JSON. */
    static encode(value: unknown): string {
        return Buffer.from(JSON.stringify(value)).toString('base64');
    }
}
