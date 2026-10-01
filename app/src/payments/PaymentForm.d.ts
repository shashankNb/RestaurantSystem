// Resolved by TypeScript only. At run time Metro picks PaymentForm.native.tsx (iOS,
// Android) or PaymentForm.web.tsx, and each declares it implements this interface.
import type { PaymentFormComponent } from './types';

export declare const PaymentForm: PaymentFormComponent;
