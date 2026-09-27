import { type StockMovement } from '@/features/products/types';
import { formatDateTime, formatSignedQuantity } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export function MovementTable({ movements, showProduct = false }: { movements: StockMovement[]; showProduct?: boolean }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-4 py-3 font-medium">Date</th>
                        {showProduct && <th className="px-4 py-3 font-medium">Product</th>}
                        <th className="px-4 py-3 font-medium">Type</th>
                        <th className="px-4 py-3 font-medium">Reason / notes</th>
                        <th className="px-4 py-3 text-right font-medium">Qty</th>
                        <th className="px-4 py-3 text-right font-medium">Balance</th>
                        <th className="px-4 py-3 font-medium">By</th>
                    </tr>
                </thead>
                <tbody>
                    {movements.length === 0 && (
                        <tr>
                            <td colSpan={showProduct ? 7 : 6} className="text-muted-foreground px-4 py-8 text-center">
                                No stock movements yet.
                            </td>
                        </tr>
                    )}
                    {movements.map((movement) => (
                        <tr key={movement.id} className="border-t">
                            <td className="px-4 py-3 whitespace-nowrap">{formatDateTime(movement.occurred_at)}</td>
                            {showProduct && (
                                <td className="px-4 py-3">
                                    {movement.product && (
                                        <Link href={route('products.show', movement.product.id)} className="hover:underline">
                                            {movement.product.name}
                                            <span className="text-muted-foreground ml-1 font-mono text-xs">{movement.product.sku}</span>
                                        </Link>
                                    )}
                                </td>
                            )}
                            <td className="px-4 py-3 whitespace-nowrap">{movement.type_label}</td>
                            <td className="px-4 py-3">
                                {movement.reason_label && <div>{movement.reason_label}</div>}
                                {movement.notes && <div className="text-muted-foreground text-xs">{movement.notes}</div>}
                            </td>
                            <td
                                className={cn(
                                    'px-4 py-3 text-right font-medium tabular-nums',
                                    movement.quantity > 0 ? 'text-green-700 dark:text-green-400' : 'text-destructive',
                                )}
                            >
                                {formatSignedQuantity(movement.quantity)}
                            </td>
                            <td className="px-4 py-3 text-right tabular-nums">{movement.balance_after}</td>
                            <td className="text-muted-foreground px-4 py-3">{movement.created_by ?? 'System'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
