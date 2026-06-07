
import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../../services/api';

interface AIModel {
    id: string;
    name: string;
    description?: string;
}

const AdminSettings: React.FC = () => {
    const [settings, setSettings] = useState<any[]>([]);
    const [testEmail, setTestEmail] = useState('');
    const [models, setModels] = useState<AIModel[]>([]);
    const [loadingModels, setLoadingModels] = useState(false);

    // Get current provider from settings
    const getCurrentProvider = useCallback(() => {
        const providerSetting = settings.find(s => s.setting_key === 'ai_provider');
        return providerSetting?.setting_value || 'gemini';
    }, [settings]);

    // Fetch models for a provider
    const fetchModels = useCallback(async (provider: string) => {
        setLoadingModels(true);
        try {
            const res = await api.get<{ success: boolean; models: AIModel[] }>(
                `/admin/settings/fetch-models?provider=${provider}`
            );
            if (res.success && res.models) {
                setModels(res.models);
            }
        } catch (e) {
            console.error('Failed to fetch models:', e);
            setModels([]);
        } finally {
            setLoadingModels(false);
        }
    }, []);

    useEffect(() => {
        api.get<any[]>('/admin/settings').then(setSettings);
    }, []);

    // Fetch models when settings load or provider changes
    useEffect(() => {
        if (settings.length > 0) {
            const provider = getCurrentProvider();
            fetchModels(provider);
        }
    }, [settings, getCurrentProvider, fetchModels]);

    const handleChange = (key: string, value: string) => {
        const newSettings = settings.map(s => s.setting_key === key ? { ...s, setting_value: value } : s);
        setSettings(newSettings);

        // If provider changed, fetch new models immediately
        if (key === 'ai_provider') {
            fetchModels(value);
        }
    };

    const handleSave = async (key: string, value: string, group: string) => {
        await api.post('/admin/settings', { key, value, group });
        alert('Saved');
    };

    const handleTestSMTP = async () => {
        try {
            const res = await api.post('/admin/settings/test-smtp', { email: testEmail });
            alert((res as any).message);
        } catch (e) {
            alert('Failed');
        }
    };

    const [activeTab, setActiveTab] = useState('general');
    const [logs, setLogs] = useState<any[]>([]);

    const fetchLogs = async () => {
        try {
            const res = await api.get<any[]>('/admin/settings/email-logs');
            setLogs(res || []);
        } catch (e) {
            console.error('Failed to fetch logs', e);
        }
    };

    const [auditLogs, setAuditLogs] = useState<any[]>([]);

    const fetchAuditLogs = async () => {
        try {
            const res = await api.get<any[]>('/admin/logs');
            setAuditLogs(res || []);
        } catch (e) {
            console.error('Failed to fetch audit logs', e);
        }
    };

    useEffect(() => {
        if (activeTab === 'logs') {
            fetchLogs();
        } else if (activeTab === 'audit') {
            fetchAuditLogs();
        }
    }, [activeTab]);

    const tabs = [
        { id: 'general', label: 'General', icon: 'fa-sliders' },
        { id: 'smtp', label: 'Mail Server', icon: 'fa-envelope' },
        { id: 'payment', label: 'Payment & Currency', icon: 'fa-credit-card' },
        { id: 'ai', label: 'AI & Models', icon: 'fa-robot' },
        { id: 'logs', label: 'Email Logs', icon: 'fa-list-ul' },
        { id: 'audit', label: 'Audit Logs', icon: 'fa-shield-halved' }
    ];

    // Render input based on setting key
    const renderInput = (s: any) => {
        // ... (keep existing renderInput logic)
        // Provider dropdown
        if (s.setting_key === 'ai_provider') {
            return (
                <select
                    value={s.setting_value}
                    onChange={(e) => handleChange(s.setting_key, e.target.value)}
                    className="flex-1 bg-slate-50 border-slate-200 rounded-xl px-4 py-3 text-sm font-bold uppercase outline-none focus:ring-2 focus:ring-indigo-500/20"
                >
                    <option value="gemini">Gemini</option>
                    <option value="openai">OpenAI</option>
                    <option value="openrouter">OpenRouter</option>
                </select>
            );
        }

        // Active Payment Gateway Dropdown
        if (s.setting_key === 'active_payment_gateway') {
            return (
                <select
                    value={s.setting_value}
                    onChange={(e) => handleChange(s.setting_key, e.target.value)}
                    className="flex-1 bg-slate-50 border-slate-200 rounded-xl px-4 py-3 text-sm font-bold uppercase outline-none focus:ring-2 focus:ring-indigo-500/20"
                >
                    <option value="stripe">Stripe</option>
                    <option value="paystack">Paystack</option>
                    <option value="flutterwave">Flutterwave</option>
                </select>
            );
        }

        // Model dropdown (dynamic)
        if (s.setting_key === 'ai_default_model') {
            return (
                <div className="flex-1 relative">
                    <select
                        value={s.setting_value}
                        onChange={(e) => handleChange(s.setting_key, e.target.value)}
                        disabled={loadingModels}
                        className="w-full bg-slate-50 border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-500/20 disabled:opacity-50"
                    >
                        {loadingModels ? (
                            <option>Loading models...</option>
                        ) : models.length > 0 ? (
                            models.map(m => (
                                <option key={m.id} value={m.id}>
                                    {m.name} {m.description ? `— ${m.description.slice(0, 40)}...` : ''}
                                </option>
                            ))
                        ) : (
                            <option value={s.setting_value}>{s.setting_value || 'No models available'}</option>
                        )}
                    </select>
                    {loadingModels && (
                        <div className="absolute right-3 top-1/2 -translate-y-1/2">
                            <i className="fa-solid fa-spinner fa-spin text-indigo-500"></i>
                        </div>
                    )}
                </div>
            );
        }

        // Default text/password input
        return (
            <input
                type={s.is_encrypted ? "password" : "text"}
                value={s.setting_value}
                onChange={(e) => handleChange(s.setting_key, e.target.value)}
                className="flex-1 bg-slate-50 border-slate-200 rounded-xl px-4 py-3 text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all focus:bg-white"
                placeholder={s.description}
            />
        );
    };

    return (
        <div className="space-y-6">
            <div className="flex bg-white p-1 rounded-2xl border border-slate-100 shadow-sm w-fit">
                {tabs.map(tab => (
                    <button
                        key={tab.id}
                        onClick={() => setActiveTab(tab.id)}
                        className={`flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold transition-all ${activeTab === tab.id
                            ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-200'
                            : 'text-slate-500 hover:bg-slate-50'
                            }`}
                    >
                        <i className={`fa-solid ${tab.icon}`}></i>
                        {tab.label}
                    </button>
                ))}
            </div>

            <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
                <div className="grid grid-cols-1 gap-6 max-w-4xl">
                    {settings.filter(s => s.setting_group === activeTab).map(s => (
                        <div key={s.setting_key} className="flex flex-col gap-2">
                            <label className="text-xs font-black text-slate-400 uppercase tracking-wider">{s.description || s.setting_key}</label>
                            <div className="flex gap-3">
                                {renderInput(s)}
                                <button
                                    onClick={() => handleSave(s.setting_key, s.setting_value, s.setting_group)}
                                    className="bg-slate-900 text-white px-6 py-2 rounded-xl text-sm font-bold hover:bg-slate-800 transition-colors shadow-lg shadow-slate-200"
                                >
                                    Save
                                </button>
                            </div>
                        </div>
                    ))}

                    {activeTab === 'smtp' && (
                        <div className="mt-8 pt-8 border-t border-dashed border-slate-200">
                            <h4 className="font-bold text-slate-800 mb-4 flex items-center gap-2">
                                <i className="fa-solid fa-paper-plane text-indigo-500"></i>
                                Test Connectivity
                            </h4>
                            <div className="flex gap-3 bg-indigo-50 p-4 rounded-2xl border border-indigo-100">
                                <input
                                    type="email"
                                    placeholder="Enter your email to receive a test..."
                                    value={testEmail}
                                    onChange={e => setTestEmail(e.target.value)}
                                    className="flex-1 bg-white border-transparent rounded-xl px-4 py-3 text-sm font-bold outline-none ring-2 ring-transparent focus:ring-indigo-200"
                                />
                                <button
                                    onClick={handleTestSMTP}
                                    className="bg-indigo-600 text-white px-6 py-2 rounded-xl text-sm font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition-all"
                                >
                                    Send Test
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Show model count for AI tab */}
                    {activeTab === 'ai' && models.length > 0 && (
                        <div className="mt-4 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-2xl border border-indigo-100">
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 bg-indigo-500 rounded-xl flex items-center justify-center">
                                    <i className="fa-solid fa-microchip text-white"></i>
                                </div>
                                <div>
                                    <p className="font-bold text-slate-800">
                                        {models.length} models available
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        From {getCurrentProvider().toUpperCase()} API • Updated live
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {activeTab === 'logs' && (
                        <div className="overflow-hidden">
                            <div className="flex justify-between items-center mb-4">
                                <h4 className="font-bold text-slate-800">Email History</h4>
                                <button onClick={() => fetchLogs()} className="text-sm text-indigo-600 font-bold hover:underline">
                                    <i className="fa-solid fa-refresh mr-1"></i> Refresh
                                </button>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="text-xs font-black uppercase text-slate-400 border-b border-slate-100">
                                            <th className="py-3 pl-2">Status</th>
                                            <th className="py-3">Recipient</th>
                                            <th className="py-3">Subject</th>
                                            <th className="py-3">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody className="text-sm">
                                        {logs.map((log: any) => (
                                            <tr key={log.id} className="border-b border-slate-50 hover:bg-slate-50">
                                                <td className="py-3 pl-2">
                                                    <span className={`px-2 py-1 rounded text-[10px] font-bold uppercase ${log.status === 'sent' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'
                                                        }`}>
                                                        {log.status === 'sent' ? 'Sent' : 'Failed'}
                                                    </span>
                                                </td>
                                                <td className="py-3 font-medium text-slate-700">{log.recipient}</td>
                                                <td className="py-3 text-slate-600">{log.subject}</td>
                                                <td className="py-3 text-slate-400 text-xs">{new Date(log.sent_at).toLocaleString()}</td>
                                            </tr>
                                        ))}
                                        {logs.length === 0 && (
                                            <tr><td colSpan={4} className="py-8 text-center text-slate-400">No logs found</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {activeTab === 'audit' && (
                        <div className="overflow-hidden">
                            <div className="flex justify-between items-center mb-4">
                                <h4 className="font-bold text-slate-800">System Audit Logs</h4>
                                <button onClick={() => fetchAuditLogs()} className="text-sm text-indigo-600 font-bold hover:underline">
                                    <i className="fa-solid fa-refresh mr-1"></i> Refresh
                                </button>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="text-xs font-black uppercase text-slate-400 border-b border-slate-100">
                                            <th className="py-3 pl-2">Action</th>
                                            <th className="py-3">Admin</th>
                                            <th className="py-3">Target</th>
                                            <th className="py-3">Details</th>
                                            <th className="py-3">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody className="text-sm">
                                        {auditLogs.map((log: any) => (
                                            <tr key={log.id} className="border-b border-slate-50 hover:bg-slate-50">
                                                <td className="py-3 pl-2">
                                                    <span className="px-2 py-1 rounded bg-slate-100 text-slate-600 text-[10px] font-bold uppercase">
                                                        {log.action}
                                                    </span>
                                                </td>
                                                <td className="py-3 font-medium text-slate-700">{log.admin_name || 'Unknown'}</td>
                                                <td className="py-3 text-slate-500 text-xs font-mono">{log.target_type}:{log.target_id?.slice(0, 8)}</td>
                                                <td className="py-3 text-slate-600">{log.details}</td>
                                                <td className="py-3 text-slate-400 text-xs">{new Date(log.created_at).toLocaleString()}</td>
                                            </tr>
                                        ))}
                                        {auditLogs.length === 0 && (
                                            <tr><td colSpan={5} className="py-8 text-center text-slate-400">No audit logs found</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {settings.filter(s => s.setting_group === activeTab && activeTab !== 'logs' && activeTab !== 'audit').length === 0 && activeTab !== 'smtp' && activeTab !== 'ai' && activeTab !== 'logs' && activeTab !== 'audit' && (
                        <div className="text-center py-12 text-slate-400">
                            <i className="fa-solid fa-empty-set text-4xl mb-3 opacity-20"></i>
                            <p>No settings found for this section.</p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default AdminSettings;
