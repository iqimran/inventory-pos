import { MasterDataPage, type MasterRecord } from '@/features/catalog/master-data-page';
import { type Paginator } from '@/types';
import { useState } from 'react';

interface SubcategoryRecord extends MasterRecord {
    description: string | null;
    category_id: number;
    category: string;
    products_count: number;
}

interface SubcategoriesProps {
    records: Paginator<SubcategoryRecord>;
    categories: { id: number; name: string; is_active: boolean }[];
    filters: { search: string; category_id: number | null };
}

export default function Subcategories({ records, categories, filters }: SubcategoriesProps) {
    const [categoryId, setCategoryId] = useState(filters.category_id ? String(filters.category_id) : '');

    return (
        <MasterDataPage
            title="Subcategories"
            singular="Subcategory"
            description="Groups within a category, e.g. Chargers or Cases under Accessories."
            routeName="subcategories"
            records={records}
            search={filters.search}
            extraQuery={{ category_id: categoryId || undefined }}
            extraFilters={
                <select
                    value={categoryId}
                    onChange={(e) => setCategoryId(e.target.value)}
                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                    aria-label="Category"
                >
                    <option value="">All categories</option>
                    {categories.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </select>
            }
            columns={[
                { label: 'Category', render: (record) => record.category },
                { label: 'Products', render: (record) => record.products_count },
            ]}
            fields={[
                {
                    name: 'category_id',
                    label: 'Category',
                    type: 'select',
                    required: true,
                    options: categories.filter((category) => category.is_active),
                },
                { name: 'name', label: 'Name', type: 'text', required: true },
                { name: 'description', label: 'Description', type: 'textarea' },
            ]}
        />
    );
}
