
import React, { useState } from 'react';
import AdminOverview from './admin/AdminOverview';
import AdminUsers from './admin/AdminUsers';
import AdminFinance from './admin/AdminFinance';
import AdminLeads from './admin/AdminLeads';
import AdminContent from './admin/AdminContent';
import AdminSettings from './admin/AdminSettings';
import AdminAnalytics from './admin/AdminAnalytics';
import AdminActivity from './admin/AdminActivity';
import { CohortManagement } from './admin/CohortManagement';
import AdminCompliance from './admin/AdminCompliance';
import VariantStudio from './admin/VariantStudio';

type AdminTab = 'overview' | 'users' | 'cohorts' | 'finance' | 'leads' | 'content' | 'settings' | 'analytics' | 'variants' | 'compliance' | 'activity';

const AdminPanel: React.FC = () => {
    const [activeTab, setActiveTab] = useState<AdminTab>('overview');

    const menuItems: { id: AdminTab; label: string; icon: string }[] = [
        { id: 'overview', label: 'Overview', icon: 'fa-chart-pie' },
        { id: 'analytics', label: 'Analytics', icon: 'fa-chart-line' },
        { id: 'activity', label: 'Activity', icon: 'fa-clock-rotate-left' },
        { id: 'users', label: 'Users', icon: 'fa-users' },
        { id: 'cohorts', label: 'Cohorts', icon: 'fa-users-viewfinder' },
        { id: 'finance', label: 'Finance', icon: 'fa-sack-dollar' },
        { id: 'leads', label: 'Leads', icon: 'fa-funnel-dollar' },
        { id: 'content', label: 'Content & AI', icon: 'fa-layer-group' },
        { id: 'variants', label: 'Variants (AI)', icon: 'fa-wand-magic-sparkles' },
        { id: 'compliance', label: 'Compliance', icon: 'fa-shield-halved' },
        { id: 'settings', label: 'Settings', icon: 'fa-gear' },
    ];

    return (
        <div className="animate-in fade-in duration-500">
            <header className="flex justify-between items-center mb-8">
                <div>
                    <h2 className="text-3xl font-black text-slate-900 tracking-tight">Admin Console</h2>
                    <p className="text-slate-500 mt-1">Platform metrics and control center</p>
                </div>
            </header>

            <div className="flex flex-col md:flex-row gap-8">
                {/* Sidebar */}
                <aside className="w-full md:w-64 flex-shrink-0">
                    <div className="bg-white rounded-3xl p-4 shadow-sm border border-slate-100 sticky top-4">
                        <nav className="space-y-1">
                            {menuItems.map(item => (
                                <button
                                    key={item.id}
                                    onClick={() => setActiveTab(item.id)}
                                    className={`w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-bold transition-all ${activeTab === item.id
                                        ? 'bg-slate-900 text-white shadow-lg shadow-slate-200'
                                        : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                                        }`}
                                >
                                    <i className={`fa-solid ${item.icon} w-5`}></i>
                                    {item.label}
                                </button>
                            ))}
                        </nav>
                    </div>
                </aside>

                {/* Main Content */}
                <main className="flex-1 min-h-[500px]">
                    {activeTab === 'overview' && <AdminOverview />}
                    {activeTab === 'analytics' && <AdminAnalytics />}
                    {activeTab === 'activity' && <AdminActivity />}
                    {activeTab === 'users' && <AdminUsers />}
                    {activeTab === 'cohorts' && <CohortManagement />}
                    {activeTab === 'finance' && <AdminFinance />}
                    {activeTab === 'leads' && <AdminLeads />}
                    {activeTab === 'content' && <AdminContent />}
                    {activeTab === 'compliance' && <AdminCompliance />}
                    {activeTab === 'variants' && <VariantStudio />}
                    {activeTab === 'settings' && <AdminSettings />}
                </main>
            </div>
        </div>
    );
};

export default AdminPanel;
