import { Badge } from '@/components/ui/badge';

export function PaymentStatusBadge({ status, label }: { status: string; label: string }) {
    if (status === 'PAID') {
        return <Badge variant="secondary">{label}</Badge>;
    }

    if (status === 'PARTIAL') {
        return <Badge className="border-transparent bg-amber-500 text-white hover:bg-amber-500/80">{label}</Badge>;
    }

    return <Badge variant="destructive">{label}</Badge>;
}
