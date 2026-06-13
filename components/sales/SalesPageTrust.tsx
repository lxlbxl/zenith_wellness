
import React, { useState } from 'react';
import { User } from '../../types';
import SalesAction from './SalesAction';
import VideoPlayer from '../ui/VideoPlayer';
import { track } from '../../src/analytics';

interface SalesPageTrustProps {
    user?: User;
    onLogin?: (user: User) => void;
    onPurchase?: () => void;
    onSwitchToLogin?: () => void;
    price?: number; // Price in cents from cohort settings
    cohortId?: string;
}

const SalesPageTrust: React.FC<SalesPageTrustProps> = ({ user, onLogin, onPurchase, onSwitchToLogin, price = 8900, cohortId }) => {
    const [showVSL, setShowVSL] = useState(false);

    return (
        <div className="min-h-screen bg-slate-50 font-sans text-slate-900 pb-20">
            {/* VSL Modal */}
            {showVSL && (
                <VideoPlayer
                    src="https://www.youtube.com/embed/dQw4w9WgXcQ"
                    duration="14:20"
                    onClose={() => setShowVSL(false)}
                    onPurchase={onPurchase}
                />
            )}

            {/* Hero / VSL Section */}
            <section className="bg-slate-900 text-white pt-12 pb-24 relative overflow-hidden">
                <div className="absolute top-0 right-0 w-[50rem] h-[50rem] bg-indigo-600/20 rounded-full blur-3xl -mr-20 -mt-20"></div>
                <div className="absolute bottom-0 left-0 w-[40rem] h-[40rem] bg-purple-600/20 rounded-full blur-3xl -ml-20 -mb-20"></div>

                <div className="container mx-auto px-6 max-w-6xl relative z-10">
                    <nav className="flex items-center justify-between mb-16">
                        <div className="flex items-center gap-2">
                            <div className="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center">
                                <i className="fa-solid fa-mountain-sun text-white text-sm"></i>
                            </div>
                            <span className="font-black tracking-tighter text-xl">ZENITH</span>
                        </div>
                        <button
                            onClick={onSwitchToLogin}
                            className="text-sm font-bold text-slate-300 hover:text-white transition-colors"
                        >
                            Member Login
                        </button>
                    </nav>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                        <div className="space-y-8">
                            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-900/50 border border-indigo-700/50 text-indigo-300 text-xs font-bold uppercase tracking-widest">
                                <span className="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                                System Update 2.0
                            </div>
                            <h1 className="text-5xl md:text-6xl font-black leading-[1.1] tracking-tight">
                                Stop Guessing. <br />
                                <span className="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-400">Start Synchronizing.</span>
                            </h1>
                            <p className="text-lg text-slate-400 max-w-lg leading-relaxed">
                                Most productivity plans fail because they fight your biology. Zenith is the first <strong>Human Performance Operating System</strong> that aligns your work with your metabolic, hormonal, and cognitive cycles.
                            </p>

                            <div className="flex flex-col sm:flex-row gap-4 pt-4">
                                <button onClick={() => { setShowVSL(true); track('cta_click', { cta_label: 'Watch Video', variant: 'trust' }); }} className="bg-indigo-600 text-white px-8 py-4 rounded-xl font-black uppercase tracking-widest hover:bg-indigo-500 hover:scale-[1.02] transition-all shadow-xl shadow-indigo-900/20">
                                    Watch the Walkthrough
                                </button>
                                <div className="flex items-center gap-4 px-4">
                                    <div className="flex -space-x-3">
                                        {[1, 2, 3].map(i => (
                                            <div key={i} className="w-10 h-10 rounded-full border-2 border-slate-900 bg-slate-700"></div>
                                        ))}
                                    </div>
                                    <div className="text-xs font-bold text-slate-400">
                                        <span className="text-white">2,400+</span> members<br />active now
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* The VSL Placeholder */}
                        <div onClick={() => setShowVSL(true)} className="bg-slate-800 rounded-2xl border border-slate-700 shadow-2xl overflow-hidden aspect-video relative group cursor-pointer">
                            <div className="absolute inset-0 bg-black/40 group-hover:bg-black/20 transition-all flex items-center justify-center">
                                <div className="w-20 h-20 bg-indigo-600/90 text-white rounded-full flex items-center justify-center pl-1 shadow-2xl group-hover:scale-110 transition-transform">
                                    <i className="fa-solid fa-play text-2xl"></i>
                                </div>
                            </div>
                            <img src="https://picsum.photos/seed/zenith_dashboard/800/450" alt="Dashboard Preview" className="w-full h-full object-cover opacity-80" />

                            <div className="absolute bottom-4 left-4 right-4 flex items-center justify-between pointer-events-none">
                                <div className="bg-black/60 backdrop-blur-md px-3 py-1.5 rounded-lg text-xs font-bold text-white">
                                    <span className="text-indigo-400 mr-2">●</span> Live Demo
                                </div>
                                <span className="text-xs font-mono text-slate-400">14:20</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* The "Glass Box" Tour */}
            <section className="py-24 container mx-auto px-6 max-w-6xl">
                <div className="text-center max-w-3xl mx-auto mb-20">
                    <h2 className="text-3xl md:text-4xl font-black text-slate-900 mb-6">See Inside The Ecosystem</h2>
                    <p className="text-slate-500 text-lg">We don't hide our methods behind a paywall. This is exactly what you get when you join the Zenith Cohort.</p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                    {[
                        { title: "Metabolic Dashboard", desc: "Real-time correlation of food, mood, and focus.", icon: "fa-chart-line", color: "text-emerald-600 bg-emerald-50" },
                        { title: "Cohort Lounge", desc: "Gamified group accountability. Never train alone.", icon: "fa-users-viewfinder", color: "text-indigo-600 bg-indigo-50" },
                        { title: "Zenith Smart Orchestrator", desc: "24/7 coaching that knows your exact biological context.", icon: "fa-robot", color: "text-purple-600 bg-purple-50" }
                    ].map((feature, i) => (
                        <div key={i} className="bg-white p-8 rounded-[2rem] border border-slate-100 shadow-lg hover:shadow-xl transition-all hover:-translate-y-1">
                            <div className={`w-14 h-14 ${feature.color} rounded-2xl flex items-center justify-center text-2xl mb-6`}>
                                <i className={`fa-solid ${feature.icon}`}></i>
                            </div>
                            <h3 className="text-xl font-black text-slate-900 mb-3">{feature.title}</h3>
                            <p className="text-slate-500 leading-relaxed">{feature.desc}</p>
                        </div>
                    ))}
                </div>
            </section>

            {/* The Offer / Registration */}
            <section className="py-20 bg-slate-900 text-white relative overflow-hidden">
                <div className="container mx-auto px-6 max-w-4xl relative z-10">
                    <div className="bg-white text-slate-900 rounded-[3rem] p-8 md:p-12 shadow-2xl flex flex-col md:flex-row gap-12 items-center">
                        <div className="flex-1 space-y-6">
                            <h2 className="text-3xl font-black tracking-tight">Your Cohort Awaits.</h2>
                            <p className="text-slate-500 font-medium">Join 2,400+ high performers optimizing their life. Instant access to the dashboard, community, and Smart coach.</p>
                            <ul className="space-y-3">
                                {[
                                    "Unlimited Smart Coaching",
                                    "Full Cycle & Metabolic Tracking",
                                    "Access to the 'Active' Cohort Lounge",
                                    "21-Day Metabolic Reset Program"
                                ].map((item, i) => (
                                    <li key={i} className="flex items-center gap-3 text-sm font-bold text-slate-700">
                                        <i className="fa-solid fa-circle-check text-emerald-500"></i>
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>

                        <div className="w-full md:w-96 bg-slate-50 p-6 md:p-8 rounded-[2rem] border border-slate-100">
                            <h3 className="text-lg font-black text-center mb-6 uppercase tracking-widest text-slate-400">{user ? 'Confirm Access' : 'Create Free Account'}</h3>
                            <SalesAction
                                user={user}
                                onLogin={onLogin}
                                onPurchase={onPurchase}
                                onSwitchToLogin={onSwitchToLogin}
                                btnText="Start My Transformation"
                                defaultPersona="newbie"
                                price={price}
                            />
                        </div>
                    </div>
                </div>
            </section>
            {/* Sticky Mobile CTA Bar */}
            <div className="fixed bottom-0 left-0 right-0 z-50 bg-white border-t border-slate-200 shadow-2xl md:hidden p-4">
                <div className="flex items-center justify-between gap-4">
                    <div className="flex-1 min-w-0">
                        <button
                            onClick={() => { onPurchase?.(); track('purchase', { value: price / 100, currency: 'GBP', program_title: 'Zenith Wellness' }); }}
                            className="w-full bg-indigo-600 text-white py-3 px-6 rounded-xl font-black uppercase tracking-widest text-sm hover:bg-indigo-500 transition-all shadow-lg"
                        >
                            Get Access Now
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default SalesPageTrust;
