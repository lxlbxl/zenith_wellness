import React, { useState, useEffect } from 'react';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { api } from '../../services/api';

const processStripePayment = async (stripe: any, elements: any, clientSecret: string) => {
    const result = await stripe.confirmPayment({
        elements,
        redirect: 'if_required',
    });

    if (result.error) {
        throw new Error(result.error.message);
    }

    return result.paymentIntent;
};

const CheckoutForm = ({ clientSecret, onSuccess, onError }: any) => {
    const stripe = useStripe();
    const elements = useElements();
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();

        if (!stripe || !elements) return;

        setLoading(true);
        try {
            const result = await processStripePayment(stripe, elements, clientSecret);
            if (result.status === 'succeeded') {
                // Confirm with backend to grant access
                // Note: Better to let webhook handle it, but for UI feedback we can call confirm
                // Or just trust the success state and redirect
                // Let's call confirm endpoint to ensure db consistency before success UI
                await api.post('/payment/confirm', {
                    payment_id: null,
                    gateway_reference: result.id
                });
                onSuccess();
            }
        } catch (err: any) {
            onError(err.message || 'Payment failed');
        } finally {
            setLoading(false);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <PaymentElement />
            <button
                type="submit"
                disabled={!stripe || loading}
                className="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold shadow-lg hover:bg-indigo-700 disabled:opacity-50"
            >
                {loading ? 'Processing...' : 'Pay Now'}
            </button>
        </form>
    );
};

export const StripeCheckout = ({ amount, currency, email, onSuccess, onError, config, programId }: any) => {
    const [clientSecret, setClientSecret] = useState('');
    const [stripePromise, setStripePromise] = useState<any>(null);

    useEffect(() => {
        if (config?.publicKey) {
            setStripePromise(loadStripe(config.publicKey));
        }
    }, [config]);

    useEffect(() => {
        const createIntent = async () => {
            try {
                const res = await api.post<any>('/payment/create-intent', {
                    gateway: 'stripe',
                    amount,
                    currency,
                    cohort_id: programId,
                    description: `Purchase: ${programId}`
                });
                // Backend returns { client_secret, gateway_id, txRef, status }
                if (res.client_secret) {
                    setClientSecret(res.client_secret);
                } else if (res.data?.client_secret) {
                    setClientSecret(res.data.client_secret);
                } else {
                    onError('Failed to get payment secret');
                }
            } catch (err) {
                onError('Failed to initialize payment');
            }
        };
        createIntent();
    }, [amount, currency, programId]);

    if (!clientSecret || !stripePromise) return <div className="p-4 text-center">Loading Stripe...</div>;

    return (
        <Elements stripe={stripePromise} options={{ clientSecret, appearance: { theme: 'stripe' } }}>
            <CheckoutForm clientSecret={clientSecret} onSuccess={onSuccess} onError={onError} />
        </Elements>
    );
};
