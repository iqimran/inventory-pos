import { MasterDataPage, type MasterRecord } from '@/features/catalog/master-data-page';
import { type Paginator } from '@/types';

interface UnitRecord extends MasterRecord {
    short_name: string;
    products_count: number;
}

export default function Units({ records, filters }: { records: Paginator<UnitRecord>; filters: { search: string } }) {
    return (
        <MasterDataPage
            title="Units"
            singular="Unit"
            description="Units of measure used for stock quantities, e.g. Piece (pcs) or Box."
            routeName="units"
            records={records}
            search={filters.search}
            columns={[
                { label: 'Short name', render: (record) => record.short_name },
                { label: 'Products', render: (record) => record.products_count },
            ]}
            fields={[
                { name: 'name', label: 'Name', type: 'text', required: true },
                { name: 'short_name', label: 'Short name', type: 'text', required: true },
            ]}
        />
    );
}
