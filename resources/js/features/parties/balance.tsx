import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Ledger balance from the shop's perspective: positive = party owes the shop, negative = shop owes the party.
 */
export function PartyBalance({ balance, className }: { balance: string; className?: string }) {
    const negative = balance.startsWith('-');
    const zero = /^-?0(\.0+)?$/.test(balance);
    const amount = formatMoney(negative ? balance.slice(1) : balance);

    if (zero) {
        return <span className={cn('text-muted-foreground tabular-nums', className)}>Settled</span>;
    }

    return (
        <span className={cn('tabular-nums', negative ? 'text-destructive' : 'text-green-700 dark:text-green-400', className)}>
            {amount} <span className="text-xs font-normal">{negative ? 'payable' : 'receivable'}</span>
        </span>
    );
}
