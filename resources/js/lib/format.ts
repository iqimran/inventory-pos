/**
 * Formats a decimal money string for display without converting through floating point arithmetic.
 */
export function formatMoney(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const [whole, fraction = ''] = value.split('.');
    const negative = whole.startsWith('-');
    const digits = negative ? whole.slice(1) : whole;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${negative ? '-' : ''}${grouped}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

export function formatSignedQuantity(quantity: number): string {
    return quantity > 0 ? `+${quantity}` : `${quantity}`;
}

export function formatDateTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString() : '—';
}

/**
 * Parses a decimal money string into integer cents (display maths only; the server is authoritative).
 */
export function toCents(value: string | number | null | undefined): number {
    const text = String(value ?? '').trim();

    if (!/^-?\d*(\.\d*)?$/.test(text) || text === '' || text === '-' || text === '.') {
        return 0;
    }

    const negative = text.startsWith('-');
    const [whole = '0', fraction = ''] = (negative ? text.slice(1) : text).split('.');
    const cents = Number.parseInt(whole || '0', 10) * 100 + Number.parseInt((fraction + '00').slice(0, 2), 10);

    return negative ? -cents : cents;
}

export function fromCents(cents: number): string {
    const negative = cents < 0;
    const absolute = Math.abs(cents);

    return `${negative ? '-' : ''}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}
