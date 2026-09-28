import { MasterDataPage, type MasterRecord } from '@/features/catalog/master-data-page';
import { type Paginator } from '@/types';

interface ExpenseTypeRecord extends MasterRecord {
    description: string | null;
    expenses_count: number;
}

export default function ExpenseTypes({ records, filters }: { records: Paginator<ExpenseTypeRecord>; filters: { search: string } }) {
    return (
        <MasterDataPage
            title="Expense types"
            singular="Expense type"
            description="Categories for shop expenses, e.g. Rent, Electricity or Salary. Types in use can be deactivated but not deleted."
            routeName="expense-types"
            managePermission="expenses.manage"
            records={records}
            search={filters.search}
            columns={[
                { label: 'Description', render: (record) => record.description ?? '—' },
                { label: 'Expenses', render: (record) => record.expenses_count },
            ]}
            fields={[
                { name: 'name', label: 'Name', type: 'text', required: true },
                { name: 'description', label: 'Description', type: 'textarea' },
            ]}
        />
    );
}
