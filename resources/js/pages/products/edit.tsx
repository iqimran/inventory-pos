import Heading from '@/components/heading';
import { ProductForm } from '@/features/products/product-form';
import { type Product, type ProductFormOptions } from '@/features/products/types';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function EditProduct({ product: { data: product }, options }: { product: { data: Product }; options: ProductFormOptions }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Products', href: route('products.index') },
                { title: product.name, href: route('products.show', product.id) },
                { title: 'Edit', href: route('products.edit', product.id) },
            ]}
        >
            <Head title={`Edit ${product.name}`} />
            <div className="p-4 md:p-6">
                <Heading title={`Edit ${product.name}`} description="Stock is not edited here; use a stock adjustment instead." />
                <ProductForm product={product} options={options} />
            </div>
        </AppLayout>
    );
}
