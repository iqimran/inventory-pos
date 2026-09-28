export interface PartySummary {
    id: number;
    name: string;
    phone?: string | null;
}

export interface Party extends PartySummary {
    type: string;
    type_label: string;
    email: string | null;
    address: string | null;
    opening_balance: string;
    opening_balance_type: string | null;
    balance: string;
    notes: string | null;
    is_active: boolean;
}

export interface PurchaseItem {
    id: number;
    product_id: number;
    product: { name: string; sku: string } | null;
    quantity: number;
    unit_cost: string;
    line_subtotal: string;
    discount_share: string;
    line_total: string;
    returned_quantity: number;
    returnable_quantity: number;
}

export interface Purchase {
    id: number;
    purchase_no: string;
    purchase_date: string;
    supplier_invoice_no: string | null;
    subtotal: string;
    discount: string;
    total: string;
    paid_amount: string;
    returned_amount: string;
    due_amount: string;
    payment_status: 'PAID' | 'PARTIAL' | 'DUE';
    payment_status_label: string;
    notes: string | null;
    party?: PartySummary;
    items?: PurchaseItem[];
    returns?: { id: number; return_no: string; return_date: string; total: string; refund_amount: string; reason: string }[];
    allocations?: {
        id: number;
        amount: string;
        created_at: string | null;
        /** Null when settled from the supplier's opening-balance advance. */
        payment: { id: number; payment_no: string; purpose_label: string; method_label: string; paid_at: string } | null;
    }[];
    created_by?: string | null;
}

export interface Payment {
    id: number;
    payment_no: string;
    direction: 'IN' | 'OUT';
    purpose: string;
    purpose_label: string;
    method: string;
    method_label: string;
    amount: string;
    allocated_amount: string;
    unallocated_amount: string;
    reference_no: string | null;
    paid_at: string;
    notes: string | null;
    party?: PartySummary;
    allocations?: { id: number; amount: string; document: { type: string; id: number; number: string } | null; created_at: string | null }[];
    created_by?: string | null;
}

export interface SelectOption {
    value: string;
    label: string;
}
