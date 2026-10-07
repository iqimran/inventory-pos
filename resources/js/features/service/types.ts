export interface Device {
    id: number;
    party_id: number;
    brand: string;
    model: string;
    name: string;
    imei1: string | null;
    imei2: string | null;
    serial_no: string | null;
    color: string | null;
    notes: string | null;
    party?: { id: number; name: string; phone: string | null };
    service_jobs_count?: number;
}

export type ServiceJobStatus = 'RECEIVED' | 'DIAGNOSING' | 'WAITING_FOR_APPROVAL' | 'IN_PROGRESS' | 'READY' | 'DELIVERED' | 'CANCELLED';

export interface Technician {
    id: number;
    name: string;
}

export interface ServiceJobPart {
    id: number;
    product_id: number;
    product: { name: string; sku: string } | null;
    stock: number | null;
    quantity: number;
    list_price: string;
    unit_price: string;
    price_overridden: boolean;
    line_total: string;
    consumed_at: string | null;
}

export interface ServiceJobCharge {
    id: number;
    description: string;
    amount: string;
    /** Placeholder billing the job estimate; replaced when real parts or charges are added. */
    is_estimate: boolean;
}

export interface ServiceJob {
    id: number;
    job_no: string;
    status: ServiceJobStatus;
    status_label: string;
    next_statuses: { value: ServiceJobStatus; label: string }[];
    complaint: string;
    diagnosis: string | null;
    estimated_amount: string;
    approved_amount: string | null;
    service_charge: string;
    received_at: string;
    promised_at: string | null;
    approved_at: string | null;
    delivered_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    notes: string | null;
    technician_id: number | null;
    technician?: string | null;
    party?: { id: number; name: string; phone: string | null; balance: string };
    device?: Device;
    parts?: ServiceJobPart[];
    charges?: ServiceJobCharge[];
    parts_total?: string;
    invoice?: {
        id: number;
        invoice_no: string;
        total: string;
        due_amount: string;
        payment_status: 'PAID' | 'PARTIAL' | 'DUE';
        payment_status_label: string;
    } | null;
    status_logs?: {
        id: number;
        from_status_label: string | null;
        to_status: ServiceJobStatus;
        to_status_label: string;
        notes: string | null;
        created_at: string | null;
        created_by: string | null;
    }[];
    created_by?: string | null;
}

export interface ServiceInvoiceLine {
    id: number;
    line_type: 'PRODUCT' | 'SERVICE';
    line_type_label: string;
    product_id: number | null;
    sku: string | null;
    description: string;
    quantity: number;
    unit_price: string;
    line_subtotal: string;
    discount_share: string;
    line_total: string;
    unit_cost?: string | null;
    cost_total?: string;
}

export interface ServiceInvoice {
    id: number;
    invoice_no: string;
    status: string;
    invoiced_at: string;
    subtotal: string;
    discount: string;
    total: string;
    product_total: string;
    service_total: string;
    paid_amount: string;
    due_amount: string;
    payment_status: 'PAID' | 'PARTIAL' | 'DUE';
    payment_status_label: string;
    payment_method_label: string | null;
    notes: string | null;
    cost_total?: string;
    party?: { id: number; name: string; phone: string | null; address: string | null };
    job?: {
        id: number;
        job_no: string;
        status: ServiceJobStatus;
        status_label: string;
        complaint: string;
        diagnosis: string | null;
        received_at: string;
        device: Device | null;
    };
    items?: ServiceInvoiceLine[];
    allocations?: { id: number; amount: string; payment: { id: number; payment_no: string; method_label: string; paid_at: string } }[];
    created_by?: string | null;
}
