export interface PrintShop {
    name: string;
    address: string | null;
    phone: string | null;
    receipt_footer?: string | null;
    logo_url?: string | null;
}

/**
 * Organization block centred at the top of printed documents (Settings → Organization).
 */
export function DocumentHeader({ shop, title }: { shop: PrintShop; title?: string }) {
    return (
        <header className="text-center">
            {shop.logo_url && <img src={shop.logo_url} alt="" className="mx-auto mb-1 max-h-16 max-w-[50mm] object-contain" />}
            <h1 className="text-base font-bold">{shop.name}</h1>
            {shop.address && <p className="whitespace-pre-line">{shop.address}</p>}
            {shop.phone && <p>Tel: {shop.phone}</p>}
            {title && <p className="mt-1 font-bold tracking-wide">{title}</p>}
        </header>
    );
}

export const Divider = () => <div className="my-2 border-t border-dashed border-black" />;
