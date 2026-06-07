import React from 'react';
import { AppTab, UserRole } from '../types';

interface MobileMenuProps {
    onNavigate: (tab: AppTab) => void;
    userRole?: UserRole;
}

const MobileMenu: React.FC<MobileMenuProps> = ({ onNavigate, userRole }) => {
    const sections = [
        {
            title: 'Daily Logistics',
            items: [
                { id: AppTab.MEAL_TRACKER, icon: 'fa-utensils', label: 'Meals', color: 'bg-orange-50 text-orange-600' },
                { id: AppTab.ROUTINES, icon: 'fa-list-check', label: 'Routines', color: 'bg-indigo-50 text-indigo-600' },
                { id: AppTab.GOALS, icon: 'fa-bullseye', label: 'Goals', color: 'bg-emerald-50 text-emerald-600' },
                { id: AppTab.JOURNAL, icon: 'fa-book-open', label: 'Journal', color: 'bg-brand-50 text-brand-600' },
            ]
        },
        {
            title: 'Bio-Insights',
            items: [
                { id: AppTab.WELLNESS, icon: 'fa-heart-pulse', label: 'Wellness', color: 'bg-rose-50 text-rose-600' },
                { id: AppTab.CYCLE_TRACKER, icon: 'fa-droplet', label: 'Cycle', color: 'bg-blue-50 text-blue-600' },
            ]
        },
        {
            title: 'Growth & Rewards',
            items: [
                { id: AppTab.CHALLENGES, icon: 'fa-layer-group', label: 'Hub', color: 'bg-amber-50 text-amber-600' },
                { id: AppTab.GAMIFICATION, icon: 'fa-medal', label: 'Awards', color: 'bg-purple-50 text-purple-600' },
                { id: AppTab.RESOURCES, icon: 'fa-bookmark', label: 'Library', color: 'bg-stone-50 text-stone-600' },
                { id: AppTab.SETTINGS, icon: 'fa-gear', label: 'Settings', color: 'bg-slate-50 text-slate-600' },
            ]
        }
    ];

    return (
        <div className="animate-in fade-in slide-in-from-bottom-8 duration-500 pb-10">
            <header className="mb-8 flex items-center justify-between">
                <div>
                    <h1 className="text-3xl font-black text-slate-900 tracking-tight">Ecosystem</h1>
                    <p className="text-slate-500 text-sm">Everything at your fingertips</p>
                </div>
                <div className="flex gap-2">
                    <span className="text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded-lg border border-slate-200">
                        <i className="fa-solid fa-command mr-1"></i>K
                    </span>
                </div>
            </header>

            <div className="space-y-8">
                {/* Zenith IQ - Priority */}
                <button
                    onClick={() => onNavigate(AppTab.COACH)}
                    className="w-full flex items-center gap-4 p-6 rounded-[2.5rem] bg-gradient-to-br from-brand-600 to-indigo-700 text-white shadow-xl shadow-brand-600/20 group hover:scale-[1.02] transition-all relative overflow-hidden"
                >
                    <div className="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2"></div>
                    <div className="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center text-2xl backdrop-blur-md">
                        <i className="fa-solid fa-brain"></i>
                    </div>
                    <div className="text-left relative z-10">
                        <p className="font-serif font-bold text-xl leading-tight">Zenith IQ</p>
                        <p className="text-brand-200 text-xs mt-0.5 opacity-80">Universal Health Orchestrator</p>
                    </div>
                    <i className="fa-solid fa-arrow-right-long ml-auto text-white/40 group-hover:translate-x-1 transition-transform"></i>
                </button>

                {sections.map((section, idx) => (
                    <section key={idx}>
                        <h3 className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4 px-2">{section.title}</h3>
                        <div className="grid grid-cols-2 gap-3">
                            {section.items.map(item => (
                                <button
                                    key={item.id}
                                    onClick={() => onNavigate(item.id)}
                                    className="flex flex-col items-start p-5 rounded-[2rem] bg-white border border-slate-100 shadow-sm hover:border-brand-200 hover:shadow-md transition-all group"
                                >
                                    <div className={`w-12 h-12 rounded-2xl flex items-center justify-center text-xl mb-4 ${item.color} group-hover:scale-110 transition-transform`}>
                                        <i className={`fa-solid ${item.icon}`}></i>
                                    </div>
                                    <span className="font-bold text-slate-700 text-sm">{item.label}</span>
                                </button>
                            ))}
                        </div>
                    </section>
                ))}

                {/* Admin Quick Action */}
                {userRole === 'admin' && (
                    <button
                        onClick={() => onNavigate(AppTab.ADMIN)}
                        className="w-full flex items-center gap-4 p-5 rounded-[2rem] bg-slate-900 text-white shadow-lg group hover:bg-slate-800 transition-all border border-slate-700"
                    >
                        <div className="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center text-slate-400">
                            <i className="fa-solid fa-shield-halved"></i>
                        </div>
                        <span className="font-bold">Admin Control Center</span>
                    </button>
                )}

                {/* Go Premium CTA */}
                <div className="p-8 bg-brand-50 rounded-[3rem] border border-brand-100 text-center relative overflow-hidden group">
                    <div className="relative z-10">
                        <div className="w-16 h-16 bg-white rounded-3xl shadow-xl flex items-center justify-center text-3xl text-brand-500 mx-auto mb-4 group-hover:rotate-6 transition-transform">
                            <i className="fa-solid fa-crown"></i>
                        </div>
                        <h3 className="font-serif font-black text-xl text-brand-900 mb-2">Elevate Your Zenith</h3>
                        <p className="text-brand-700/60 text-sm mb-6 max-w-[200px] mx-auto leading-relaxed">Unlock unlimited Smart insights and the full protocol suite.</p>
                        <button
                            onClick={() => onNavigate(AppTab.CHALLENGES)}
                            className="bg-brand-600 text-white px-8 py-4 rounded-2xl font-black text-sm shadow-xl shadow-brand-600/30 hover:bg-brand-500 transition-all active:scale-95"
                        >
                            View Bio-Hacking Plans
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default MobileMenu;
