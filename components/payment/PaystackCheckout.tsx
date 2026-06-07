import React from 'react';
import { usePaystackPayment } from 'react-paystack';
import { api } from '../../services/api';

export const PaystackCheckout = ({ amount, email, onSuccess, onError, config, programId }: any) => {

    const paystackConfig = {
        reference: (new Date()).getTime().toString(),
        email: email,
        amount: amount, // in kobo
        publicKey: config.publicKey,
    };

    const initializePayment = usePaystackPayment(paystackConfig);

    const handleSuccess = async (reference: any) => {
        try {
            await api.post('/payment/confirm', {
                gateway_reference: reference.reference
            });
            onSuccess();
        } catch (err) {
            onError('Payment verification failed');
        }
    };

    const onClose = () => {
        // user helper
    };

    return (
        <div className="mt-4">
            <button
                onClick={() => {
                    initializePayment({ onSuccess: handleSuccess, onClose });
                }}
                className="w-full py-4 bg-sky-500 text-white rounded-xl font-bold shadow-lg hover:bg-sky-600"
            >
                Pay with Paystack
            </button>
        </div>
    );
};
