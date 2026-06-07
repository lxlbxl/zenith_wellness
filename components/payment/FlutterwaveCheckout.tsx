import React from 'react';
import { useFlutterwave, closePaymentModal } from 'flutterwave-react-v3';
import { api } from '../../services/api';

export const FlutterwaveCheckout = ({ amount, currency, email, name, onSuccess, onError, config, programId }: any) => {

    const flwConfig = {
        public_key: config.publicKey,
        tx_ref: Date.now().toString(),
        amount: amount / 100, // Flutterwave takes main currency unit
        currency: currency,
        payment_options: 'card,mobilemoney,ussd',
        customer: {
            email: email,
            phone_number: '',
            name: name,
        },
        customizations: {
            title: 'Zenith Wellness',
            description: `Payment for program`,
            logo: 'https://st2.depositphotos.com/4403291/7418/v/450/depositphotos_74189661-stock-illustration-online-shop-log.jpg',
        },
    };

    const handleFlutterwavePayment = useFlutterwave(flwConfig);

    return (
        <div className="mt-4">
            <button
                onClick={() => {
                    handleFlutterwavePayment({
                        callback: async (response) => {
                            if (response.status === 'successful') {
                                try {
                                    await api.post('/payment/confirm', {
                                        gateway_reference: response.tx_ref
                                    });
                                    onSuccess();
                                } catch (err) {
                                    onError('Payment verification failed');
                                }
                                closePaymentModal(); // this will close the modal programmatically
                            } else {
                                closePaymentModal();
                            }
                        },
                        onClose: () => { },
                    });
                }}
                className="w-full py-4 bg-amber-500 text-white rounded-xl font-bold shadow-lg hover:bg-amber-600"
            >
                Pay with Flutterwave
            </button>
        </div>
    );
};
