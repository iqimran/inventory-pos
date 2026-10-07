import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// A type alias (not an interface) so it satisfies Inertia's form data constraint.
export type DeviceDraft = {
    brand: string;
    model: string;
    imei1: string;
    imei2: string;
    serial_no: string;
    color: string;
    notes: string;
};

export const emptyDevice: DeviceDraft = { brand: '', model: '', imei1: '', imei2: '', serial_no: '', color: '', notes: '' };

interface DeviceFieldsProps {
    value: DeviceDraft;
    onChange: (value: DeviceDraft) => void;
    errors: Record<string, string | undefined>;
    /** Error key prefix, e.g. 'device.' on the job intake form. */
    errorPrefix?: string;
    idPrefix?: string;
}

/**
 * Brand / model / IMEI / serial inputs shared by the device dialog and job intake.
 */
export function DeviceFields({ value, onChange, errors, errorPrefix = '', idPrefix = 'device' }: DeviceFieldsProps) {
    const field = (name: keyof DeviceDraft, label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <div className="grid gap-2">
            <Label htmlFor={`${idPrefix}-${name}`} required={Boolean(props.required)}>
                {label}
            </Label>
            <Input id={`${idPrefix}-${name}`} value={value[name]} onChange={(e) => onChange({ ...value, [name]: e.target.value })} {...props} />
            <InputError message={errors[`${errorPrefix}${name}`]} />
        </div>
    );

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {field('brand', 'Brand', { required: true, placeholder: 'Samsung' })}
            {field('model', 'Model', { required: true, placeholder: 'Galaxy A52' })}
            {field('imei1', 'IMEI 1', { inputMode: 'numeric', placeholder: '15 digits (dial *#06#)' })}
            {field('imei2', 'IMEI 2', { inputMode: 'numeric', placeholder: 'Dual-SIM phones' })}
            {field('serial_no', 'Serial no.')}
            {field('color', 'Colour')}
            <div className="sm:col-span-2">{field('notes', 'Device notes', { placeholder: 'Condition, scratches, accessories received…' })}</div>
        </div>
    );
}
