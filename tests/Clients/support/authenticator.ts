import { createHmac } from 'node:crypto';

const STEP_MS = 30_000;

/**
 * An authenticator app: TOTP codes (RFC 6238) with Bitwarden's parameters,
 * SHA-1, six digits and 30-second steps.
 */
export class Authenticator {
    private lastStep = 0;

    constructor(private readonly secret: string) {}

    /**
     * A code the server takes: it accepts one step of drift either way, and
     * every step only once (validate_totp_code in Vaultwarden).
     */
    async nextCode(): Promise<string> {
        const now = Math.floor(Date.now() / STEP_MS);
        const step = Math.max(this.lastStep + 1, now);
        if (step > now + 1) {
            await new Promise((resolve) => setTimeout(resolve, (step - 1) * STEP_MS - Date.now() + 1000));
        }
        this.lastStep = step;

        return this.code(step);
    }

    code(step: number): string {
        const counter = Buffer.alloc(8);
        counter.writeBigUInt64BE(BigInt(step));
        const hash = createHmac('sha1', base32(this.secret)).update(counter).digest();
        const offset = hash[hash.length - 1] & 0x0f;

        return String((hash.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
    }
}

function base32(text: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const character of text.replace(/[\s=]/g, '').toUpperCase()) {
        const value = alphabet.indexOf(character);
        if (value < 0) {
            throw new Error(`Not base32: ${text}`);
        }
        bits += value.toString(2).padStart(5, '0');
    }
    const bytes: number[] = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}
