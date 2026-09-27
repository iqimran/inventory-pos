export interface Product {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    description: string | null;
    category_id: number;
    subcategory_id: number | null;
    brand_id: number | null;
    unit_id: number;
    category?: string;
    subcategory?: string | null;
    brand?: string | null;
    unit?: string;
    purchase_price: string;
    retail_price: string;
    wholesale_price: string;
    reorder_level: number;
    is_active: boolean;
    stock?: number;
    is_low_stock?: boolean;
}

export interface StockMovement {
    id: number;
    type: string;
    type_label: string;
    quantity: number;
    balance_after: number;
    unit_cost: string | null;
    reason: string | null;
    reason_label: string | null;
    notes: string | null;
    occurred_at: string;
    product?: { id: number; name: string; sku: string };
    created_by?: string | null;
}

export interface ProductFormOptions {
    categories: { id: number; name: string }[];
    subcategories: { id: number; category_id: number; name: string }[];
    brands: { id: number; name: string }[];
    units: { id: number; name: string; short_name: string }[];
}
