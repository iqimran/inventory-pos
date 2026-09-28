export interface RevenuePeriod {
    period: string;
    pos_sales: string;
    service_parts: string;
    returns: string;
    product: string;
    product_quantity: number;
    service: string;
    combined: string;
}

/**
 * A deduction (e.g. returns): "—" when zero, otherwise "−1,234.00".
 */
export const deduction = (value: string, format: (value: string) => string) => (/^-?0(\.0+)?$/.test(value) ? '—' : `−${format(value)}`);
