import React, { useState } from 'react';
import { api } from '../services/api';

const PrivacyCenter: React.FC = () => {
    const [requestStatus, setRequestStatus] = useState<'idle' | 'loading' | 'success' | 'error'>('idle');
    const [statusMessage, setStatusMessage] = useState('');
    const [activeTab, setActiveTab] = useState<'export' | 'delete' | 'consents'>('consents');
    const [consents, setConsents] = useState({
        cookie_analytics: true,
        cookie_marketing: false,
        email_marketing: true
    });
    const [deleteReason, setDeleteReason] = useState('');

    const handleExport = async () => {
        setRequestStatus('loading');
        try {
            await api.post('/privacy/request-export', {});
            setRequestStatus('success');
            setStatusMessage('Export request submitted. We will notify you when your data is ready.');
        } catch (err: any) {
            setRequestStatus('error');
            setStatusMessage(err.message || 'Failed to request export.');
        }
    };

    const handleDelete = async () => {
        if (!confirm('Are you sure? This action is irreversible.')) return;
        setRequestStatus('loading');
        try {
            await api.post('/privacy/request-deletion', { reason: deleteReason });
            setRequestStatus('success');
            setStatusMessage('Deletion request submitted. Your account is scheduled for deletion.');
        } catch (err: any) {
            setRequestStatus('error');
            setStatusMessage(err.message || 'Failed to request deletion.');
        }
    };

    const handleSaveConsents = async () => {
        setRequestStatus('loading');
        try {
            await api.post('/privacy/update-consent', { consents });
            setRequestStatus('success');
            setStatusMessage('Privacy preferences updated successfully.');
        } catch (err: any) {
            setRequestStatus('error');
            setStatusMessage('Failed to update preferences.');
        }
    };

    return (
        <div className="bg-white rounded-[2.5rem] p-10 shadow-sm border border-slate-100 max-w-4xl mx-auto">
            <div className="mb-8">
                <h2 className="text-3xl font-black text-slate-900 mb-2">Privacy Center</h2>
                <p className="text-slate-500">Manage your data, privacy preferences, and rights under GDPR/CCPA.</p>
            </div>

            {/* Tabs */}
            <div className="flex gap-2 mb-8 bg-slate-50 p-1.5 rounded-2xl w-fit">
                {(['consents', 'export', 'delete'] as const).map(tab => (
                    <button
                        key={tab}
                        onClick={() => { setActiveTab(tab); setRequestStatus('idle'); setStatusMessage(''); }}
                        className={`px-6 py-3 rounded-xl text-sm font-bold capitalize transition-all ${activeTab === tab
                                ? 'bg-white text-slate-900 shadow-sm'
                                : 'text-slate-400 hover:text-slate-600'
                            }`}
                    >
                        {tab === 'consents' ? 'Preferences' : tab === 'export' ? 'Download Data' : 'Delete Account'}
                    </button>
                ))}
            </div>

            {/* Status Message */}
            {statusMessage && (
                <div className={`p-4 rounded-xl mb-6 flex items-center gap-3 ${requestStatus === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                    }`}>
                    <i className={`fa-solid ${requestStatus === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'}`}></i>
                    <p className="text-sm font-bold">{statusMessage}</p>
                </div>
            )}

            {/* Consents Tab */}
            {activeTab === 'consents' && (
                <div className="space-y-6 animate-in fade-in duration-500">
                    <div className="space-y-4">
                        {[
                            { key: 'cookie_analytics', label: 'Analytics Cookies', desc: 'Help us improve by tracking usage patterns.' },
                            { key: 'cookie_marketing', label: 'Marketing Cookies', desc: 'Allow us to show relevant offers and promotions.' },
                            { key: 'email_marketing', label: 'Email Communications', desc: 'Receive updates, tips, and cohort announcements.' },
                        ].map((item) => (
                            <div key={item.key} className="flex items-center justify-between p-4 border border-slate-100 rounded-2xl hover:bg-slate-50 transition-colors">
                                <div>
                                    <h4 className="font-bold text-slate-800">{item.label}</h4>
                                    <p className="text-xs text-slate-500">{item.desc}</p>
                                </div>
                                <label className="relative inline-flex items-center cursor-pointer">
                                    <input
                                        type="checkbox"
                                        className="sr-only peer"
                                        checked={(consents as any)[item.key]}
                                        onChange={e => setConsents({ ...consents, [item.key]: e.target.checked })}
                                    />
                                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>
                        ))}
                    </div>
                    <button
                        onClick={handleSaveConsents}
                        disabled={requestStatus === 'loading'}
                        className="bg-slate-900 text-white px-8 py-3 rounded-xl font-bold hover:bg-slate-800 transition-colors disabled:opacity-50"
                    >
                        {requestStatus === 'loading' ? 'Saving...' : 'Save Preferences'}
                    </button>
                </div>
            )}

            {/* Export Tab */}
            {activeTab === 'export' && (
                <div className="space-y-6 animate-in fade-in duration-500">
                    <div className="bg-indigo-50 p-6 rounded-3xl border border-indigo-100 flex items-start gap-4">
                        <div className="w-12 h-12 bg-white rounded-2xl flex items-center justify-center text-indigo-600 shrink-0">
                            <i className="fa-solid fa-file-export"></i>
                        </div>
                        <div>
                            <h3 className="font-bold text-slate-900 mb-2">Request Data Export</h3>
                            <p className="text-sm text-slate-600 leading-relaxed mb-4">
                                You have the right to request a copy of all personal data we hold about you.
                                This includes your profile, wellness logs, journal entries, and payment history.
                                <br /><br />
                                Once requested, we will prepare a complete archive (JSON/CSV) and notify you when it's ready for download.
                                This process naturally takes up to 48 hours.
                            </p>
                            <button
                                onClick={handleExport}
                                disabled={requestStatus === 'loading'}
                                className="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 hover:bg-indigo-700 transition-colors disabled:opacity-50"
                            >
                                {requestStatus === 'loading' ? 'Processing...' : 'Request Archive'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Delete Tab */}
            {activeTab === 'delete' && (
                <div className="space-y-6 animate-in fade-in duration-500">
                    <div className="bg-rose-50 p-6 rounded-3xl border border-rose-100">
                        <div className="flex items-center gap-3 mb-4 text-rose-600">
                            <i className="fa-solid fa-triangle-exclamation text-2xl"></i>
                            <h3 className="font-black text-lg">Danger Zone</h3>
                        </div>
                        <p className="text-sm text-slate-700 leading-relaxed mb-6">
                            Requesting account deletion will permanently remove your account and all associated data from our servers.
                            <b>This action cannot be undone.</b>
                        </p>

                        <div className="mb-6">
                            <label className="block text-xs font-bold text-slate-500 uppercase mb-2">Reason (Optional)</label>
                            <textarea
                                className="w-full p-4 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-rose-500 focus:border-rose-500 outline-none"
                                rows={3}
                                placeholder="Tell us why you're leaving..."
                                value={deleteReason}
                                onChange={e => setDeleteReason(e.target.value)}
                            ></textarea>
                        </div>

                        <button
                            onClick={handleDelete}
                            disabled={requestStatus === 'loading'}
                            className="bg-rose-600 text-white px-6 py-3 rounded-xl font-bold text-sm shadow-lg shadow-rose-200 hover:bg-rose-700 transition-colors disabled:opacity-50"
                        >
                            {requestStatus === 'loading' ? 'Submitting...' : 'Request Permanent Deletion'}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default PrivacyCenter;
