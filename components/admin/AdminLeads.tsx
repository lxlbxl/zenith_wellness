
import React, { useEffect, useState } from 'react';
import { api } from '../../services/api';

const AdminLeads: React.FC = () => {
    const [leads, setLeads] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get<any[]>('/admin/leads').then(data => { // returns list
            setLeads(data || []);
            setLoading(false);
        });
    }, []);

    const updateStatus = async (id: string, status: string) => {
        await api.post('/admin/leads/status', { id, status });
        const updated = leads.map(l => l.id === id ? { ...l, status } : l);
        setLeads(updated);
    };

    return (
        <div className="space-y-6">
            <h3 className="text-xl font-bold text-slate-800">Lead Pipeline</h3>
            <div className="bg-white rounded-[2.5rem] border border-slate-100 shadow-sm overflow-hidden p-6">
                <table className="w-full text-left">
                    <thead>
                        <tr className="border-b border-slate-100">
                            <th className="pb-4 pl-2 text-xs font-black uppercase text-slate-400">Contact</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Source</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Date</th>
                            <th className="pb-4 text-xs font-black uppercase text-slate-400">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {leads.map(lead => (
                            <tr key={lead.id} className="hover:bg-slate-50 text-sm">
                                <td className="py-4 pl-2">
                                    <div className="font-bold text-slate-900">{lead.name}</div>
                                    <div className="text-slate-500">{lead.email}</div>
                                </td>
                                <td className="py-4 text-slate-600">{lead.source}</td>
                                <td className="py-4 text-slate-400">{new Date(lead.created_at).toLocaleDateString()}</td>
                                <td className="py-4">
                                    <select
                                        value={lead.status}
                                        onChange={(e) => updateStatus(lead.id, e.target.value)}
                                        className="bg-slate-100 border-none text-xs rounded-lg py-1 px-2 font-bold uppercase text-slate-700 focus:ring-2 focus:ring-indigo-500"
                                    >
                                        <option value="new">New</option>
                                        <option value="contacted">Contacted</option>
                                        <option value="qualified">Qualified</option>
                                        <option value="converted">Converted</option>
                                        <option value="lost">Lost</option>
                                    </select>
                                </td>
                            </tr>
                        ))}
                        {leads.length === 0 && !loading && (
                            <tr><td colSpan={4} className="text-center py-8 text-slate-400">No leads yet</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default AdminLeads;
