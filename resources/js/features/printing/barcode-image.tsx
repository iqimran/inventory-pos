import { cn } from '@/lib/utils';

/**
 * A server-rendered Code 128 barcode (SVG data URI), optionally with its human-readable value.
 */
export function BarcodeImage({ src, value, className, showValue = true }: { src: string; value: string; className?: string; showValue?: boolean }) {
    return (
        <figure className="flex flex-col items-center">
            {/* pixelated: keeps bar edges sharp when the SVG is scaled for thermal printers. */}
            <img src={src} alt={`Barcode ${value}`} className={cn('w-full [image-rendering:pixelated]', className)} />
            {showValue && <figcaption className="font-mono text-[10px] leading-tight tracking-wider">{value}</figcaption>}
        </figure>
    );
}
