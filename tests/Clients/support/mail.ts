import { expect } from '@playwright/test';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * A mail the server sent, decoded: .github/scripts/clients.sh has it send to an
 * SMTP sink that keeps every message in VW_CLIENTS_MAIL.
 */
export interface Mail {
    to: string;
    subject: string;
    text: string;
    html: string;
}

/** Waits for the newest mail to the address with a matching subject. */
export async function waitForMail(to: string, subject: RegExp): Promise<Mail> {
    let found: Mail | undefined;
    await expect.poll(() => {
        found = mails().reverse().find((mail) => mail.to.toLowerCase() === to.toLowerCase() && subject.test(mail.subject));

        return found !== undefined;
    }, { message: `a mail to ${to} with the subject ${subject}` }).toBe(true);

    return found!;
}

/** The mail's link to the web vault, the one people would click. */
export function link(mail: Mail, path: string): string {
    const url = mail.text.match(/https?:\/\/\S+/g)?.find((candidate) => new URL(candidate).hash.startsWith(`#${path}`));
    expect(url, `a link to ${path} in ${mail.text}`).toBeDefined();

    return url!;
}

function mails(): Mail[] {
    const folder = process.env.VW_CLIENTS_MAIL;
    if (!folder) {
        throw new Error('VW_CLIENTS_MAIL is not set; run the tests with .github/scripts/clients.sh');
    }
    if (!existsSync(folder)) {
        return [];
    }

    return readdirSync(folder).filter((file) => file.endsWith('.eml')).sort()
        .map((file) => parse(readFileSync(join(folder, file), 'utf8')));
}

function parse(raw: string): Mail {
    const [headers, body] = split(raw);
    const parts = { plain: '', html: '' };
    collect(headers, body, parts);

    return {
        to: headers.get('to')?.match(/<([^>]+)>/)?.[1] ?? headers.get('to')?.trim() ?? '',
        subject: decodeWords(headers.get('subject') ?? ''),
        text: parts.plain,
        html: parts.html,
    };
}

/** Headers by lower-case name, and the body. */
function split(raw: string): [Map<string, string>, string] {
    raw = raw.replace(/\r\n/g, '\n');
    const end = raw.indexOf('\n\n');
    const head = end < 0 ? raw : raw.slice(0, end);
    const headers = new Map<string, string>();
    let name: string | undefined;
    for (const line of head.split('\n')) {
        if (name !== undefined && /^[ \t]/.test(line)) {
            // Folded header line.
            headers.set(name, `${headers.get(name)} ${line.trim()}`);
        } else if (line.includes(':')) {
            name = line.slice(0, line.indexOf(':')).trim().toLowerCase();
            headers.set(name, line.slice(line.indexOf(':') + 1).trim());
        }
    }

    return [headers, end < 0 ? '' : raw.slice(end + 2)];
}

function collect(headers: Map<string, string>, body: string, parts: { plain: string; html: string }): void {
    const type = headers.get('content-type') ?? 'text/plain';
    const boundary = type.match(/boundary="?([^";]+)"?/i)?.[1];
    if (/^multipart\//i.test(type) && boundary) {
        // Before the first boundary is the preamble, after the closing one the epilogue.
        for (const section of body.split(`--${boundary}`).slice(1)) {
            if (section.startsWith('--')) {
                break;
            }
            collect(...split(section.replace(/^\n+/, '')), parts);
        }

        return;
    }

    const subtype = /^text\/html/i.test(type) ? 'html' : /^text\/plain/i.test(type) ? 'plain' : undefined;
    if (subtype) {
        parts[subtype] = decode(body, headers.get('content-transfer-encoding') ?? '7bit').replace(/\n+$/, '');
    }
}

function decode(body: string, encoding: string): string {
    switch (encoding.toLowerCase()) {
        case 'base64':
            return Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8').replace(/\r\n/g, '\n');
        case 'quoted-printable':
            return quotedPrintable(body.replace(/=\n/g, '')).toString('utf8');
        default:
            return body;
    }
}

/** Encoded words of the subject (RFC 2047). */
function decodeWords(value: string): string {
    return value.replace(/=\?([^?]+)\?([BQ])\?([^?]*)\?=\s*/gi, (_, charset: string, kind: string, text: string) => {
        const bytes = kind.toUpperCase() === 'B' ? Buffer.from(text, 'base64') : quotedPrintable(text.replace(/_/g, ' '));

        return bytes.toString(charset.toLowerCase() === 'utf-8' ? 'utf8' : 'latin1');
    });
}

function quotedPrintable(text: string): Buffer {
    const bytes: number[] = [];
    for (let i = 0; i < text.length; i++) {
        if (text[i] === '=' && /^[0-9A-F]{2}$/i.test(text.slice(i + 1, i + 3))) {
            bytes.push(parseInt(text.slice(i + 1, i + 3), 16));
            i += 2;
        } else {
            bytes.push(...Buffer.from(text[i]));
        }
    }

    return Buffer.from(bytes);
}
