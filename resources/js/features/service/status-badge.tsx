import { Badge } from '@/components/ui/badge';
import { type ServiceJobStatus } from '@/features/service/types';
import { cn } from '@/lib/utils';

// Literal class names so Tailwind generates them.
const tones: Record<ServiceJobStatus, string> = {
    RECEIVED: 'bg-slate-500 hover:bg-slate-500',
    DIAGNOSING: 'bg-sky-600 hover:bg-sky-600',
    WAITING_FOR_APPROVAL: 'bg-amber-500 hover:bg-amber-500',
    IN_PROGRESS: 'bg-indigo-600 hover:bg-indigo-600',
    READY: 'bg-emerald-600 hover:bg-emerald-600',
    DELIVERED: 'bg-green-800 hover:bg-green-800',
    CANCELLED: 'bg-rose-700 hover:bg-rose-700',
};

export function ServiceStatusBadge({ status, label }: { status: ServiceJobStatus; label: string }) {
    return <Badge className={cn('border-transparent text-white', tones[status])}>{label}</Badge>;
}
