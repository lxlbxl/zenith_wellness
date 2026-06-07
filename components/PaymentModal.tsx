import React, { useState, useEffect } from 'react';
import { Program } from '../types';
import { api } from '../services/api';
import { StripeCheckout } from './payment/StripeCheckout';
import { PaystackCheckout } from './payment/PaystackCheckout';
import { FlutterwaveCheckout } from './payment/FlutterwaveCheckout';
import CountdownTimer from './ui/CountdownTimer';
import TrustBadges from './ui/TrustBadges';

interface PaymentModalProps {
    userId: string;
    userEmail?: string;
    userName?: string;
    program: Program;
    onClose: () => void;
    onSuccess: () => void;
    price: number;
    discount?: number;
}

interface PaymentConfig {
    gateway: 'stripe' | 'paystack' | 'flutterwave';
    publicKey: string;
    currencies: string[];
    rates: Record<string, number>;
    flutterwaveCurrencies: string[];
}

const CURRENCY_SYMBOLS: Record<string, string> = {
    USD: '$', EUR: '€', GBP: '£', NGN: '₦', GHS: 'GH₵',
    KES: 'KSh', UGX: 'USh', TZS: 'TSh', RWF: 'RF', ZAR: 'R',
    EGP: 'E£', MAD: 'MAD', INR: '₹', JPY: '¥', KRW: '₩',
    CNY: '¥', CAD: 'C$', AUD: 'A$', AED: 'AED', BHD: 'BHD',
    CFA: 'CFA', XOF: 'CFA', XAF: 'FCFA', SAR: 'SAR', QAR: 'QAR',
    OMR: 'OMR', KWD: 'KWD', ETB: 'Br', GMD: 'D', GNF: 'FG',
    LRD: 'L$', LSL: 'L', MGA: 'Ar', MWK: 'MK', MUR: '₨',
    MZN: 'MT', NAD: 'N$', SCR: '₨', SLL: 'Le', SOS: 'S',
    SZL: 'E', TND: 'DT', ZMW: 'ZK', BIF: 'FBu', CDF: 'FC'
};

const ZERO_DECIMAL_CURRENCIES = ['JPY', 'KRW', 'BIF', 'CLP', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

const PaymentModal: React.FC<PaymentModalProps> = ({ userId, userEmail = '', userName = '', program, onClose, onSuccess, price, discount = 0 }) => {
    const [config, setConfig] = useState<PaymentConfig | null>(null);
    const [loadingConfig, setLoadingConfig] = useState(true);
    const [selectedCurrency, setSelectedCurrency] = useState('USD');
    const [convertedPrice, setConvertedPrice] = useState(price);
    const [step, setStep] = useState<'details' | 'payment' | 'success'>('details');
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const loadConfig = async () => {
            try {
                const res = await api.get<PaymentConfig>('/payment/config');
                if (res && res.gateway) {
                    setConfig(res);
                    const savedCurrency = localStorage.getItem('zenith_currency') || 'USD';
                    const available = res.currencies || [];
                    if (available.includes(savedCurrency)) {
                        setSelectedCurrency(savedCurrency);
                    } else if (available.length > 0) {
                        setSelectedCurrency(available[0]);
                    }
                }
            } catch (err) {
                console.warn('Payment config load failed:', err);
                setError("Failed to load payment configuration.");
            } finally {
                setLoadingConfig(false);
            }
        };
        loadConfig();
    }, []);

    useEffect(() => {
        if (config && config.rates) {
            const rate = config.rates[selectedCurrency] || 1;
            const isZeroDecimal = ZERO_DECIMAL_CURRENCIES.includes(selectedCurrency);
            let converted = price * rate;
            if (!isZeroDecimal) {
                converted = Math.round(converted);
            }
            setConvertedPrice(converted);
        }
    }, [selectedCurrency, config, price]);

    const handleCurrencyChange = (currency: string) => {
        setSelectedCurrency(currency);
        localStorage.setItem('zenith_currency', currency);
    };

    const currency = config?.gateway === 'stripe' ? selectedCurrency :
        config?.gateway === 'paystack' ? (['NGN', 'GHS', 'USD', 'ZAR', 'KES'].includes(selectedCurrency) ? selectedCurrency : 'NGN') :
            selectedCurrency;

    const finalPrice = Math.floor(convertedPrice * (1 - discount / 100));

    const handleSuccess = () => {
        setStep('success');
        setTimeout(onSuccess, 3000);
    };

    const formatPrice = (amount: number, curr: string) => {
        const symbol = CURRENCY_SYMBOLS[curr] || curr + ' ';
        const isZeroDecimal = ZERO_DECIMAL_CURRENCIES.includes(curr);
        if (isZeroDecimal) {
            return `${symbol}${amount.toLocaleString()}`;
        }
        return `${symbol}${(amount / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    if (step === 'success') {
        return (
            <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/90 backdrop-blur-md">
                <div className="bg-white p-12 rounded-[3rem] text-center max-w-sm animate-in zoom-in duration-300">
                    <div className="w-24 h-24 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-6 text-4xl shadow-lg shadow-emerald-100">
                        <i className="fa-solid fa-check"></i>
                    </div>
                    <h3 className="text-2xl font-black text-slate-900 mb-2">Access Granted</h3>
                    <p className="text-slate-500 font-medium">Welcome to the protocol.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-md animate-in fade-in duration-300">
            <div className="bg-white w-full max-w-4xl rounded-[3rem] shadow-2xl overflow-hidden flex flex-col md:flex-row max-h-[90vh]">
                {/* Left Side: Summary */}
                <div className="w-full md:w-2/5 bg-slate-50 p-10 border-r border-slate-100 flex flex-col">
                    <div className="mb-8">
                        <span className="text-[10px] font-black uppercase text-indigo-500 tracking-widest block mb-2">{program.category} Protocol</span>
                        <h2 className="text-2xl font-black text-slate-900 leading-tight mb-4">{program.title}</h2>

                        <div className="bg-rose-50 border border-rose-100 rounded-xl p-3">
                            <p className="text-[10px] font-bold text-rose-600 uppercase tracking-widest mb-1 text-center">Offer Expires In</p>
                            <div className="flex justify-center">
                                <CountdownTimer targetDate={new Date(new Date().getTime() + 15 * 60 * 1000)} size="md" variant="box" />
                            </div>
                        </div>
                    </div>

                    <div className="flex-1 space-y-4">
                        <div className="flex items-center gap-4 p-4 bg-white rounded-2xl border border-slate-100">
                            <img src={program.image} className="w-16 h-16 rounded-xl object-cover" />
                            <div>
                                <p className="text-xs font-bold text-slate-900">Full Access Pass</p>
                                <p className="text-[10px] text-slate-500">Video + Community + Smart coaching</p>
                            </div>
                        </div>

                        <div className="bg-indigo-50/50 p-4 rounded-xl border border-indigo-50 italic text-[10px] text-slate-600">
                            "This protocol completely changed how I approach my cycle. Worth every penny."
                            <div className="mt-1 font-bold not-italic text-slate-900">— Sarah J.</div>
                        </div>

                        {/* Currency Selector */}
                        {config && config.currencies && config.currencies.length > 1 && (
                            <div className="space-y-2 py-2">
                                <label className="text-[10px] font-bold text-slate-500 uppercase">Currency</label>
                                <select
                                    value={currency}
                                    onChange={(e) => handleCurrencyChange(e.target.value)}
                                    className="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl bg-white focus:ring-2 focus:ring-indigo-200 outline-none"
                                >
                                    {config.currencies
                                        .filter(c => config.gateway === 'paystack' ? ['NGN', 'GHS', 'USD', 'ZAR', 'KES'].includes(c) : true)
                                        .map(c => (
                                            <option key={c} value={c}>
                                                {CURRENCY_SYMBOLS[c] || c} - {c}
                                            </option>
                                        ))}
                                </select>
                            </div>
                        )}

                        <div className="space-y-2 py-4 border-t border-b border-slate-100">
                            <div className="flex justify-between text-sm">
                                <span className="text-slate-500">Subtotal</span>
                                <span className="font-bold text-slate-900">{formatPrice(finalPrice, currency)}</span>
                            </div>
                            {discount > 0 && (
                                <div className="flex justify-between text-sm text-emerald-600">
                                    <span>Discount ({discount}%)</span>
                                    <span>-{formatPrice(Math.floor(convertedPrice * discount / 100), currency)}</span>
                                </div>
                            )}
                            <div className="flex justify-between text-lg font-black pt-2">
                                <span className="text-slate-900">Total</span>
                                <span className="text-indigo-600">{formatPrice(finalPrice, currency)}</span>
                            </div>
                        </div>

                        <div className="mt-4 p-4 bg-emerald-50 rounded-2xl border border-emerald-100 flex items-center gap-3">
                            <div className="w-10 h-10 bg-emerald-500 rounded-full flex items-center justify-center text-white text-lg shadow-sm">
                                <i className="fa-solid fa-shield-halved"></i>
                            </div>
                            <div>
                                <p className="text-[10px] font-black uppercase text-emerald-800 tracking-widest">Ironclad Protection</p>
                                <p className="text-[9px] text-emerald-600 font-bold leading-tight">100% Refund if you don't see results in 30 days.</p>
                            </div>
                        </div>
                    </div>

                    {error && <div className="mt-4 p-3 bg-red-50 text-red-600 text-xs rounded-lg">{error}</div>}

                    <div className="mt-8">
                        <TrustBadges />
                        <p className="text-[10px] text-slate-400 mt-2 text-center">By purchasing, you agree to the Terms of Service.</p>
                    </div>
                </div>

                {/* Right Side: Payment Form */}
                <div className="w-full md:w-3/5 p-10 flex flex-col overflow-y-auto">
                    <div className="flex justify-between items-center mb-8">
                        <h3 className="text-xl font-black text-slate-900">Checkout</h3>
                        <button onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-slate-50 rounded-full hover:bg-slate-100">
                            <i className="fa-solid fa-xmark text-slate-400"></i>
                        </button>
                    </div>

                    {loadingConfig ? (
                        <div className="flex items-center justify-center h-40">
                            <i className="fa-solid fa-circle-notch fa-spin text-slate-300 text-2xl"></i>
                        </div>
                    ) : (
                        <div>
                            {config?.gateway === 'stripe' && (
                                <StripeCheckout
                                    amount={finalPrice}
                                    currency={currency}
                                    config={config}
                                    onSuccess={handleSuccess}
                                    onError={setError}
                                    programId={program.id}
                                />
                            )}
                            {config?.gateway === 'paystack' && (
                                <PaystackCheckout
                                    amount={finalPrice}
                                    email={userEmail}
                                    config={config}
                                    onSuccess={handleSuccess}
                                    onError={setError}
                                    programId={program.id}
                                />
                            )}
                            {config?.gateway === 'flutterwave' && (
                                <FlutterwaveCheckout
                                    amount={finalPrice}
                                    currency={currency}
                                    email={userEmail}
                                    name={userName}
                                    config={config}
                                    onSuccess={handleSuccess}
                                    onError={setError}
                                    programId={program.id}
                                />
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default PaymentModal;
