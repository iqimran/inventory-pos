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
