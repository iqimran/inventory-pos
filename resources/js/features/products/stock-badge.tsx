import { Badge } from '@/components/ui/badge';

export function StockBadge({ stock, reorderLevel, unit }: { stock: number; reorderLevel: number; unit?: string }) {
    const label = `${stock}${unit ? ` ${unit}` : ''}`;

    if (stock <= 0) {
        return <Badge variant="destructive">{label}</Badge>;
    }

    if (reorderLevel > 0 && stock <= reorderLevel) {
        return <Badge className="border-transparent bg-amber-500 text-white hover:bg-amber-500/80">{label}</Badge>;
    }

    return <Badge variant="secondary">{label}</Badge>;
}
