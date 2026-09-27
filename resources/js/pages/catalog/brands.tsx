import { MasterDataPage, type MasterRecord } from '@/features/catalog/master-data-page';
import { type Paginator } from '@/types';

interface BrandRecord extends MasterRecord {
    products_count: number;
}

export default function Brands({ records, filters }: { records: Paginator<BrandRecord>; filters: { search: string } }) {
    return (
        <MasterDataPage
            title="Brands"
            singular="Brand"
            description="Manufacturers such as Samsung, Apple or Xiaomi. Optional on products."
            routeName="brands"
            records={records}
            search={filters.search}
            columns={[{ label: 'Products', render: (record) => record.products_count }]}
            fields={[{ name: 'name', label: 'Name', type: 'text', required: true }]}
        />
    );
}
