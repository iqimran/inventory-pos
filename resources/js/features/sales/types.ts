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
    returned_quantity: number;
    returnable_quantity: number;
}

export interface SaleReturn {
    id: number;
    return_no: string;
    status: string;
    returned_at: string;
    subtotal: string;
    adjustment_amount: string;
    refund_amount: string;
    credit_amount: string;
    refund_method_label: string | null;
    reason: string;
    sale?: { id: number; invoice_no: string };
    party?: { id: number; name: string; phone: string | null } | null;
    items?: { id: number; product: { name: string; sku: string } | null; quantity: number; unit_price: string; amount: string; unit_cost?: string }[];
    created_by?: string | null;
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
    returned_amount: string;
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
    returns?: {
        id: number;
        return_no: string;
        returned_at: string;
        subtotal: string;
        refund_amount: string;
        credit_amount: string;
        reason: string;
    }[];
    allocations?: { id: number; amount: string; payment: { id: number; payment_no: string; method_label: string; paid_at: string } }[];
    created_by?: string | null;
}
