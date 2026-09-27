export interface PosProduct {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    unit: string | null;
    retail_price: string;
    wholesale_price: string;
    stock: number;
}

export interface Customer {
    id: number;
    name: string;
    phone: string | null;
    balance: string;
}

export interface SaleItem {
    id: number;
    product_id: number;
    product: { name: string; sku: string } | null;
    quantity: number;
    list_price: string;
    unit_price: string;
    price_overridden: boolean;
    line_subtotal: string;
    line_discount: string;
    discount_share: string;
    line_total: string;
    unit_cost?: string;
}

export interface Sale {
    id: number;
    invoice_no: string;
    sale_type: 'RETAIL' | 'WHOLESALE';
    sale_type_label: string;
    status: string;
    sold_at: string;
    subtotal: string;
    items_discount: string;
    discount: string;
    total: string;
    paid_amount: string;
    due_amount: string;
    payment_status: 'PAID' | 'PARTIAL' | 'DUE';
    payment_status_label: string;
    payment_method: string | null;
    payment_method_label: string | null;
    tendered_amount: string | null;
    change_amount: string;
    notes: string | null;
    party?: { id: number; name: string; phone: string | null; address: string | null } | null;
    items?: SaleItem[];
    cost_total?: string;
    allocations?: { id: number; amount: string; payment: { id: number; payment_no: string; method_label: string; paid_at: string } }[];
    created_by?: string | null;
}
