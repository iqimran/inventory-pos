/**
 * Current balances split by who owes whom (App\Domain\Reporting\PartyLedgerReport::outstanding).
 */
export interface Outstanding {
    customer_receivable: string;
    customer_receivable_parties: number;
    customer_credit: string;
    customer_credit_parties: number;
    supplier_payable: string;
    supplier_payable_parties: number;
    supplier_advance: string;
    supplier_advance_parties: number;
}
