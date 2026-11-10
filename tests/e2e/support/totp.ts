// SPDX-License-Identifier: AGPL-3.0-only

import { createHmac } from 'node:crypto';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(text: string): Buffer {
  let bits = '';
  for (const char of text.replace(/[\s=]/g, '').toUpperCase()) {
    bits += ALPHABET.indexOf(char).toString(2).padStart(5, '0');
  }
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) {
    bytes.push(parseInt(bits.slice(i, i + 8), 2));
  }

  return Buffer.from(bytes);
}

// The code an authenticator app shows: RFC 6238, SHA-1, 6 digits, 30 seconds. The key is the
// manual key of the setup screen, base32 in groups of four.
export function totpCode(key: string, atMs: number = Date.now()): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(atMs / 1000 / 30)));
  const hmac = createHmac('sha1', base32Decode(key)).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const value = hmac.readUInt32BE(offset) & 0x7fffffff;

  return String(value % 1_000_000).padStart(6, '0');
}
