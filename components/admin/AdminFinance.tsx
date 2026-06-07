import React, { useEffect, useState, useMemo } from 'react';
import { api } from '../../services/api';

interface Transaction {
    id: string;
    user_name?: string;
    user_email?: string;
    amount: number;
    currency: string;
    status: string;
    gateway: string;
    created_at: string;
    cohort_id?: string;
    description?: string;
}

interface PaginationInfo {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
    has_next: boolean;
    has_prev: boolean;
}

interface FilterState {
    status: string;
    gateway: string;
    dateFrom: string;
    dateTo: string;
    search: string;
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

const formatAmount = (amountCents: number, currency: string): string => {
    const symbol = CURRENCY_SYMBOLS[currency] || currency + ' ';
    const isZeroDecimal = ZERO_DECIMAL_CURRENCIES.includes(currency);
    const amount = isZeroDecimal ? amountCents : amountCents / 100;
    return `${symbol}${amount.toLocaleString(undefined, { minimumFractionDigits: isZeroDecimal ? 0 : 2, maximumFractionDigits: isZeroDecimal ? 0 : 2 })}`;
};

const AdminFinance: React.FC = () => {
    const [transactions, setTransactions] = useState<Transaction[]>([]);
    const [pagination, setPagination] = useState<PaginationInfo | null>(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [filters, setFilters] = useState<FilterState>({
        status: '',
        gateway: '',
        dateFrom: '',
        dateTo: '',
        search: ''
    });
    const [fxRatesLoading, setFxRatesLoading] = useState(false);
    const [fxRatesMessage, setFxRatesMessage] = useState<{type: 'success' | 'error', text: string} | null>(null);
    const [exchangeRateApiKey, setExchangeRateApiKey] = useState('');
    const [sortBy, setSortBy] = useState<'date' | 'amount'>('date');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

    const loadTransactions = (pageNum = 1) => {
        setLoading(true);
        const params = new URLSearchParams({
            page: String(pageNum),
            per_page: '50'
        });
        api.get<any>(`/payment/transactions?${params}`).then(data => {
            setTransactions(data?.data || []);
            setPagination(data?.pagination || null);
            setLoading(false);
        }).catch(() => setLoading(false));
    };

    const refreshFXRates = async (apiKey?: string) => {
        setFxRatesLoading(true);
        setFxRatesMessage(null);
        try {
            const response = await api.post('/admin/fx-rates/refresh', { api_key: apiKey || exchangeRateApiKey });
            setFxRatesMessage({ type: 'success', text: response.message || 'Rates refreshed successfully' });
            loadFXRates(); // Reload rates to show updated source
        } catch (e: any) {
            setFxRatesMessage({ type: 'error', text: e.response?.data?.message || 'Failed to refresh rates' });
        } finally {
            setFxRatesLoading(false);
        }
    };

    const loadFXRates = () => {
        api.get<any>('/admin/fx-rates/list').then(data => {
            // Could store this for display if needed
        }).catch(() => {});
    };

    const handleRefund = async (paymentId: string) => {
        if (!confirm('Are you sure you want to refund this payment? This action cannot be undone.')) return;
        try {
            await api.post('/payment/refund', { payment_id: paymentId, reason: 'Admin requested' });
            alert('Refund processed successfully');
            loadTransactions(page);
        } catch (e: any) {
            alert('Refund failed: ' + (e.response?.data?.message || e.message));
        }
    };

    useEffect(() => { loadTransactions(1); }, []);

    // Calculate summary stats per currency
    const currencyBreakdown = useMemo(() => {
        const breakdown: Record<string, { revenue: number; refunded: number; count: number; refundCount: number }> = {};
        const successful = transactions.filter(tx => tx.status === 'success');
        const refunded = transactions.filter(tx => tx.status === 'refunded');

        for (const tx of successful) {
            if (!breakdown[tx.currency]) {
                breakdown[tx.currency] = { revenue: 0, refunded: 0, count: 0, refundCount: 0 };
            }
            breakdown[tx.currency].revenue += tx.amount;
            breakdown[tx.currency].count += 1;
        }

        for (const tx of refunded) {
            if (!breakdown[tx.currency]) {
                breakdown[tx.currency] = { revenue: 0, refunded: 0, count: 0, refundCount: 0 };
            }
            breakdown[tx.currency].refunded += tx.amount;
            breakdown[tx.currency].refundCount += 1;
        }

        return breakdown;
    }, [transactions]);

    const filteredTransactions = useMemo(() => {
        let result = [...transactions];
        if (filters.status) result = result.filter(tx => tx.status === filters.status);
        if (filters.gateway) result = result.filter(tx => tx.gateway === filters.gateway);
        if (filters.dateFrom) {
            const fromDate = new Date(filters.dateFrom);
            result = result.filter(tx => new Date(tx.created_at) >= fromDate);
        }
        if (filters.dateTo) {
            const toDate = new Date(filters.dateTo);
            toDate.setHours(23, 59, 59);
            result = result.filter(tx => new Date(tx.created_at) <= toDate);
        }
        if (filters.search) {
            const searchLower = filters.search.toLowerCase();
            result = result.filter(tx =>
                (tx.user_name?.toLowerCase().includes(searchLower)) ||
                (tx.user_email?.toLowerCase().includes(searchLower)) ||
                (tx.id.toLowerCase().includes(searchLower))
            );
        }
        result.sort((a, b) => {
            let comparison = 0;
            if (sortBy === 'date') {
                comparison = new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
            } else if (sortBy === 'amount') {
                comparison = a.amount - b.amount;
            }
            return sortOrder === 'desc' ? -comparison : comparison;
        });
        return result;
    }, [transactions, filters, sortBy, sortOrder]);

    const clearFilters = () => {
        setFilters({ status: '', gateway: '', dateFrom: '', dateTo: '', search: '' });
    };
    const hasActiveFilters = Object.values(filters).some(v => v !== '');
    const gateways = useMemo(() => {
        const unique = new Set(transactions.map(tx => tx.gateway).filter(Boolean));
        return Array.from(unique);
    }, [transactions]);

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <h3 className="text-xl font-bold text-slate-800">Financials</h3>
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => refreshFXRates()}
                        disabled={fxRatesLoading}
                        className="text-xs font-bold text-emerald-600 hover:text-emerald-800 bg-emerald-50 hover:bg-emerald-100 px-4 py-2 rounded-lg transition-colors disabled:opacity-50"
                    >
                        {fxRatesLoading ? 'Refreshing...' : 'Refresh FX Rates'}
                    </button>
                    <button
                        onClick={() => loadTransactions(page)}
                        disabled={loading}
                        className="text-xs font-bold text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 px-4 py-2 rounded-lg transition-colors disabled:opacity-50"
                    >
                        {loading ? 'Loading...' : 'Refresh'}
                    </button>
                </div>
            </div>
            {fxRatesMessage && (
                <div className={`px-4 py-2 rounded-lg text-sm ${fxRatesMessage.type === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
                    {fxRatesMessage.text}
                </div>
            )}
            
            {/* FX Rate API Key Input */}
            <div className="flex items-center gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                <span className="text-xs font-bold text-slate-600">ExchangeRate-API Key (optional):</span>
                <input 
                    type="password" 
                    placeholder="Enter API key for more currencies"
                    value={exchangeRateApiKey}
                    onChange={e => setExchangeRateApiKey(e.target.value)}
                    className="flex-1 px-3 py-1.5 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none"
                />
                <span className="text-xs text-slate-400">Uses Frankfurter (free) if empty</span>
            </div>

            {/* Multi-Currency Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {Object.entries(currencyBreakdown).map(([currency, stats]) => {
                    const symbol = CURRENCY_SYMBOLS[currency] || currency;
                    const isZeroDecimal = ZERO_DECIMAL_CURRENCIES.includes(currency);
                    const totalRevenue = isZeroDecimal ? stats.revenue : stats.revenue / 100;
                    const totalRefunded = isZeroDecimal ? stats.refunded : stats.refunded / 100;
                    const netRevenue = totalRevenue - totalRefunded;
                    return (
                        <div key={currency} className="bg-white rounded-2xl p-4 border border-slate-100">
                            <div className="flex items-center gap-2 mb-2">
                                <span className="text-lg">{symbol}</span>
                                <span className="text-xs font-bold text-slate-500 uppercase">{currency}</span>
                            </div>
                            <div className="text-sm text-slate-600">Revenue: <span className="font-bold text-emerald-700">{formatAmount(stats.revenue, currency)}</span></div>
                            <div className="text-sm text-slate-600">Refunded: <span className="font-bold text-rose-700">{formatAmount(stats.refunded, currency)}</span></div>
                            <div className="text-sm text-slate-600">Net: <span className="font-black text-indigo-700">{formatAmount(isZeroDecimal ? netRevenue : netRevenue * 100, currency)}</span></div>
                            <div className="text-xs text-slate-400 mt-1">{stats.count} transactions</div>
                        </div>
                    );
                })}
                {Object.keys(currencyBreakdown).length === 0 && (
                    <div className="col-span-full text-center py-8 text-slate-400">No revenue data yet</div>
                )}
            </div>

            {/* Filters */}
            <div className="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                <div className="flex items-center justify-between mb-3">
                    <span className="text-xs font-bold text-slate-500 uppercase">Filters</span>
                    {hasActiveFilters && (
                        <button onClick={clearFilters} className="text-xs font-bold text-slate-500 hover:text-slate-700">Clear All</button>
                    )}
                </div>
                <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <div className="col-span-2 md:col-span-1">
                        <input type="text" placeholder="Search user..." value={filters.search}
                            onChange={e => setFilters(f => ({ ...f, search: e.target.value }))}
                            className="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" />
                    </div>
                    <select value={filters.status} onChange={e => setFilters(f => ({ ...f, status: e.target.value }))}
                        className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none bg-white">
                        <option value="">All Statuses</option>
                        <option value="success">Success</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                        <option value="refunded">Refunded</option>
                    </select>
                    <select value={filters.gateway} onChange={e => setFilters(f => ({ ...f, gateway: e.target.value }))}
                        className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none bg-white">
                        <option value="">All Gateways</option>
                        {gateways.map(g => (<option key={g} value={g}>{g.charAt(0).toUpperCase() + g.slice(1)}</option>))}
                    </select>
                    <input type="date" value={filters.dateFrom} onChange={e => setFilters(f => ({ ...f, dateFrom: e.target.value }))}
                        className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" title="From date" />
                    <input type="date" value={filters.dateTo} onChange={e => setFilters(f => ({ ...f, dateTo: e.target.value }))}
                        className="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none" title="To date" />
                </div>
            </div>

            {/* Transactions Table */}
            <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden p-6">
                <div className="flex items-center justify-between mb-4">
                    <span className="text-sm text-slate-500">
                        Showing {filteredTransactions.length} of {transactions.length} transactions
                        {pagination && ` (Page ${pagination.page} of ${pagination.total_pages})`}
                    </span>
                    <div className="flex items-center gap-2">
                        {pagination && pagination.has_prev && (
                            <button onClick={() => { setPage(p => p - 1); loadTransactions(page - 1); }}
                                className="text-xs bg-slate-100 hover:bg-slate-200 px-3 py-1 rounded-lg">Previous</button>
                        )}
                        {pagination && pagination.has_next && (
                            <button onClick={() => { setPage(p => p + 1); loadTransactions(page + 1); }}
                                className="text-xs bg-slate-100 hover:bg-slate-200 px-3 py-1 rounded-lg">Next</button>
                        )}
                        <select value={`${sortBy}-${sortOrder}`} onChange={e => {
                            const [by, order] = e.target.value.split('-') as ['date' | 'amount', 'asc' | 'desc'];
                            setSortBy(by); setSortOrder(order);
                        }} className="text-xs border border-slate-200 rounded-lg px-2 py-1 bg-white">
                            <option value="date-desc">Newest First</option>
                            <option value="date-asc">Oldest First</option>
                            <option value="amount-desc">Highest Amount</option>
                            <option value="amount-asc">Lowest Amount</option>
                        </select>
                    </div>
                </div>

                <table className="w-full text-left">
                    <thead>
                        <tr className="border-b border-slate-100">
                            <th className="pb-4 pl-2 text-xs font-black uppercase text-slate-400">Date</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400">User</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Amount</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Status</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Gateway</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {filteredTransactions.map(tx => (
                            <tr key={tx.id} className="hover:bg-slate-50">
                                <td className="py-4 pl-2 text-xs text-slate-500">
                                    {new Date(tx.created_at).toLocaleDateString()}
                                    <div className="text-[10px] text-slate-400">{new Date(tx.created_at).toLocaleTimeString()}</div>
                                </td>
                                <td className="py-4">
                                    <div className="font-bold text-slate-900">{tx.user_name || 'Guest'}</div>
                                    <div className="text-[10px] text-slate-400">{tx.user_email}</div>
                                </td>
                                <td className="py-4 font-mono font-bold text-slate-700">
                                    {formatAmount(tx.amount, tx.currency)}
                                </td>
                                <td className="py-4">
                                    <span className={`px-2 py-1 rounded-md text-[10px] font-bold uppercase ${tx.status === 'success' ? 'bg-emerald-100 text-emerald-700' :
                                        tx.status === 'pending' ? 'bg-amber-100 text-amber-700' :
                                            tx.status === 'refunded' ? 'bg-slate-100 text-slate-600' :
                                                'bg-rose-100 text-rose-700'}`}>
                                        {tx.status}
                                    </span>
                                </td>
                                <td className="py-4 text-xs text-slate-500 uppercase">{tx.gateway}</td>
                                <td className="py-4 text-right">
                                    {tx.status === 'success' && (
                                        <button onClick={() => handleRefund(tx.id)}
                                            className="text-xs font-bold text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 px-3 py-1 rounded-lg transition-colors">
                                            Refund
                                        </button>
                                    )}
                                    {tx.status === 'refunded' && (
                                        <span className="text-[10px] font-bold text-slate-400">Refunded</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {filteredTransactions.length === 0 && !loading && (
                            <tr>
                                <td colSpan={6} className="text-center py-8 text-slate-400">
                                    {hasActiveFilters ? 'No transactions match your filters' : 'No transactions recorded'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default AdminFinance;
