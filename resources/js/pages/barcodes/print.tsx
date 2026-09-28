import { Button } from '@/components/ui/button';
import { PrintPage } from '@/features/printing/print-page';
import { formatMoney } from '@/lib/format';
import { Link } from '@inertiajs/react';
import { CSSProperties, Fragment } from 'react';

interface LabelData {
    product_id: number;
    name: string;
    sku: string;
    barcode: string;
    price: string | null;
    image: string;
    quantity: number;
}

interface Layout {
    value: string;
    page_width: number;
    page_height: number;
    label_width: number;
    label_height: number;
    columns: number;
    rows: number;
    margin_top: number;
    margin_left: number;
    gap_x: number;
    gap_y: number;
}

interface PrintLabelsProps {
    labels: LabelData[];
    layout: Layout;
    options: { show_sku: boolean; show_shop: boolean };
    shopName: string;
    total: number;
}

const mm = (value: number) => `${value}mm`;

/**
 * One product label. Sizes scale with the label height so every label stock stays legible.
 */
function ProductLabel({
    label,
    layout,
    options,
    shopName,
}: {
    label: LabelData;
    layout: Layout;
    options: PrintLabelsProps['options'];
    shopName: string;
}) {
    const h = layout.label_height;
    const pt = (factor: number) => `${Math.round(h * factor * 10) / 10}pt`;

    return (
        <div
            className="flex flex-col items-stretch justify-between overflow-hidden bg-white text-center leading-tight text-black"
            style={{ width: mm(layout.label_width), height: mm(h), padding: '1.2mm 1.5mm' }}
        >
            {options.show_shop && (
                <div className="flex-none truncate font-semibold" style={{ fontSize: pt(0.2) }}>
                    {shopName}
                </div>
            )}
            <div className="line-clamp-2 flex-none font-semibold" style={{ fontSize: pt(0.27) }}>
                {label.name}
            </div>
            {/* Side padding keeps the quiet zone scanners need; the bars take whatever height the text leaves. */}
            <div className="min-h-0 flex-1" style={{ padding: '0.5mm 2.5mm' }}>
                <img src={label.image} alt={`Barcode ${label.barcode}`} className="block h-full w-full" style={{ maxHeight: mm(h * 0.45) }} />
            </div>
            <div className="flex-none font-mono tracking-wider" style={{ fontSize: pt(0.25) }}>
                {label.barcode}
            </div>
            {(options.show_sku || label.price !== null) && (
                <div className="flex flex-none items-baseline justify-between gap-1">
                    <span className="truncate font-mono" style={{ fontSize: pt(0.22) }}>
                        {options.show_sku ? label.sku : ''}
                    </span>
                    {label.price !== null && (
                        <span className="font-bold whitespace-nowrap" style={{ fontSize: pt(0.34) }}>
                            {formatMoney(label.price)}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Printable barcode labels. Roll stock prints one label per page; A4 prints sheets of labels.
 */
export default function PrintLabels({ labels, layout, options, shopName, total }: PrintLabelsProps) {
    const expanded = labels.flatMap((label) => Array.from({ length: label.quantity }, () => label));
    const perPage = layout.columns * layout.rows;
    const pages = Array.from({ length: Math.ceil(expanded.length / perPage) }, (_, index) => expanded.slice(index * perPage, (index + 1) * perPage));
    const roll = perPage === 1;

    const pageStyle: CSSProperties = {
        width: mm(layout.page_width),
        height: mm(layout.page_height),
        paddingTop: mm(layout.margin_top),
        paddingLeft: mm(layout.margin_left),
        display: 'grid',
        gridTemplateColumns: `repeat(${layout.columns}, ${mm(layout.label_width)})`,
        gridAutoRows: mm(layout.label_height),
        columnGap: mm(layout.gap_x),
        rowGap: mm(layout.gap_y),
        alignContent: 'start',
    };

    return (
        <PrintPage
            title="Barcode labels"
            paper={roll ? `${mm(layout.page_width)} ${mm(layout.page_height)}` : 'A4'}
            actions={
                <Button variant="outline" asChild>
                    <Link href={route('barcodes.labels', { products: labels.map((label) => label.product_id) })}>Back</Link>
                </Button>
            }
            hint={`${total} label(s) on ${pages.length} page(s). In the print dialog, set scale to 100% and margins to none.`}
        >
            <div className="flex flex-col items-center gap-4 print:block print:gap-0">
                {pages.map((page, index) => (
                    <Fragment key={index}>
                        <div
                            className="bg-white shadow print:shadow-none"
                            style={{ ...pageStyle, breakAfter: index < pages.length - 1 ? 'page' : 'auto', overflow: 'hidden' }}
                        >
                            {page.map((label, position) => (
                                <ProductLabel key={position} label={label} layout={layout} options={options} shopName={shopName} />
                            ))}
                        </div>
                    </Fragment>
                ))}
            </div>
        </PrintPage>
    );
}
