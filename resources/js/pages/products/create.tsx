import Heading from '@/components/heading';
import { ProductForm } from '@/features/products/product-form';
import { type ProductFormOptions } from '@/features/products/types';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function CreateProduct({ options }: { options: ProductFormOptions }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Products', href: route('products.index') },
                { title: 'New product', href: route('products.create') },
            ]}
        >
            <Head title="New product" />
            <div className="p-4 md:p-6">
                <Heading title="New product" description="Define the product, its codes and prices." />
                <ProductForm options={options} />
            </div>
        </AppLayout>
    );
}
