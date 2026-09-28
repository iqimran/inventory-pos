export interface ExpenseAudit {
    id: number;
    action: 'CREATED' | 'UPDATED' | 'VOIDED';
    action_label: string;
    changes: Record<string, { from: string | null; to: string | null }> | null;
    reason: string | null;
    created_at: string | null;
    created_by: string | null;
}

export interface Expense {
    id: number;
    expense_no: string;
    expense_type_id: number;
    type?: { id: number; name: string };
    amount: string;
    expense_date: string;
    payment_method: string;
    payment_method_label: string;
    reference: string | null;
    notes: string | null;
    status: 'RECORDED' | 'VOID';
    status_label: string;
    voided_at: string | null;
    void_reason: string | null;
    voided_by?: string | null;
    created_at: string | null;
    created_by?: string | null;
    audits?: ExpenseAudit[];
    can: { update: boolean; void: boolean };
}
