import { MasterDataPage, type MasterRecord } from '@/features/catalog/master-data-page';
import { type Paginator } from '@/types';

interface CategoryRecord extends MasterRecord {
    description: string | null;
    subcategories_count: number;
    products_count: number;
}

export default function Categories({ records, filters }: { records: Paginator<CategoryRecord>; filters: { search: string } }) {
    return (
        <MasterDataPage
            title="Categories"
            singular="Category"
            description="Top-level product groups, e.g. Mobile Phones, Accessories, Spare Parts."
            routeName="categories"
            records={records}
            search={filters.search}
            columns={[
                { label: 'Description', render: (record) => record.description ?? '—' },
                { label: 'Subcategories', render: (record) => record.subcategories_count },
                { label: 'Products', render: (record) => record.products_count },
            ]}
            fields={[
                { name: 'name', label: 'Name', type: 'text', required: true },
                { name: 'description', label: 'Description', type: 'textarea' },
            ]}
        />
    );
}
