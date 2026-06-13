
import React, { useState } from 'react';
import { User } from '../../types';
import SalesRegistrationForm from './SalesRegistrationForm';
import VideoPlayer from '../ui/VideoPlayer';
import SpotsRemaining from '../ui/SpotsRemaining';
import CountdownTimer from '../ui/CountdownTimer';
import { track } from '../../src/analytics';

interface SalesPageCohortProps {
    onLogin: (user: User) => void;
    onSwitchToLogin?: () => void;
    onPurchase?: () => void;
}

const SalesPageCohort: React.FC<SalesPageCohortProps> = ({ onLogin, onSwitchToLogin, onPurchase }) => {
    const [showVSL, setShowVSL] = useState(false);
    return (
        <div className="min-h-screen bg-slate-900 font-sans text-white">
            {/* VSL Modal */}
            {showVSL && (
                <VideoPlayer
                    src="https://www.youtube.com/embed/dQw4w9WgXcQ"
                    duration="14:20"
                    onClose={() => setShowVSL(false)}
                    onPurchase={onPurchase}
                />
            )}

            <div className="container mx-auto px-6 py-8 flex justify-between items-center">
                <div className="font-black text-2xl tracking-tighter italic">ZENITH SQUAD</div>
                <div className="flex items-center gap-4">
                    <button
                        onClick={onSwitchToLogin}
                        className="text-sm font-bold text-slate-400 hover:text-white transition-colors"
                    >
                        Member Login
                    </button>
                    <div className="px-4 py-1.5 bg-yellow-500/20 text-yellow-400 border border-yellow-500/50 rounded-full text-xs font-bold uppercase tracking-widest animate-pulse">
                        Cohort 24 Filling Now
                    </div>
                </div>
            </div>

            <main className="container mx-auto px-6 py-12 md:py-20 grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                <div className="space-y-8 order-2 lg:order-1">
                    <h1 className="text-5xl md:text-7xl font-black leading-none tracking-tighter uppercase">
                        You Don't Need<br />
                        <span className="text-transparent bg-clip-text bg-gradient-to-r from-yellow-400 to-orange-500">A Diet.</span><br />
                        You Need<br />
                        <span className="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-cyan-400">A Team.</span>
                    </h1>
                    <p className="text-xl text-slate-400 max-w-md leading-relaxed">
                        Willpower is a finite resource. Structure is infinite. Join the Zenith Cohort and execute the 21-Day Metabolic Reset with a squad that won't let you fail.
                    </p>

                    <div className="space-y-4 pt-4">
                        {[
                            { icon: 'fa-users', title: 'Live Cohort Lounge', desc: 'Secure comms with high performers.' },
                            { icon: 'fa-trophy', title: 'Global Leaderboards', desc: 'Rank up as you log your progress.' },
                            { icon: 'fa-bullseye', title: 'Daily Mission Control', desc: 'Execute the daily directives together.' },
                        ].map((item, i) => (
                            <div key={i} className="flex items-center gap-6 p-4 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10 transition-colors">
                                <div className="w-12 h-12 rounded-xl bg-indigo-600 flex items-center justify-center text-xl flex-shrink-0">
                                    <i className={`fa-solid ${item.icon}`}></i>
                                </div>
                                <div>
                                    <h3 className="font-bold text-lg">{item.title}</h3>
                                    <p className="text-sm text-slate-400">{item.desc}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                    <button
                        onClick={() => { setShowVSL(true); track('cta_click', { cta_label: 'Watch Video', variant: 'cohort' }); }}
                        className="inline-flex items-center gap-3 px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold uppercase tracking-wider hover:bg-indigo-500 transition-all shadow-lg text-sm"
                    >
                        <i className="fa-solid fa-play"></i> Watch the Walkthrough
                    </button>
                </div>

                <div className="order-1 lg:order-2 bg-gradient-to-br from-indigo-900 to-slate-900 p-8 md:p-12 rounded-[3rem] border border-white/10 shadow-2xl relative overflow-hidden">
                    <div className="absolute top-0 right-0 w-64 h-64 bg-yellow-500/20 rounded-full blur-[100px] pointer-events-none"></div>

                    <div className="relative z-10">
                        <div className="text-center mb-10">
                            <h2 className="text-3xl font-black uppercase tracking-tight mb-2">Claim Your Spot</h2>
                            <p className="text-slate-400">This cohort closes in 48 hours.</p>
                        </div>

                        <SalesRegistrationForm
                            onSuccess={onLogin}
                            defaultPersona="veteran"
                            btnText="Join The Squad"
                            className="bg-white/5 p-6 rounded-3xl border border-white/5 backdrop-blur-sm"
                        />

                        {onSwitchToLogin && (
                            <div className="mt-4 pt-4 border-t border-white/10 text-center">
                                <p className="text-xs text-slate-400 font-medium">
                                    Already have an account?{' '}
                                    <button
                                        onClick={onSwitchToLogin}
                                        className="text-yellow-400 font-bold hover:underline"
                                    >
                                        Sign in
                                    </button>
                                </p>
                            </div>
                        )}

                        <div className="mt-8 flex items-center justify-center gap-4 opacity-50">
                            <div className="flex -space-x-3">
                                {[1, 2, 3, 4].map(i => (
                                    <div key={i} className="w-8 h-8 rounded-full bg-slate-700 border-2 border-slate-900"></div>
                                ))}
                            </div>
                            <span className="text-xs font-bold text-slate-400">12 Friends joined today</span>
                        </div>
                    </div>
                </div>
            </main>

            {/* Sticky Mobile CTA */}
            <div className="fixed bottom-0 left-0 right-0 z-50 bg-slate-900 border-t border-white/10 shadow-2xl md:hidden p-4">
                <div className="flex items-center justify-between gap-4">
                    <div className="flex-1 min-w-0 space-y-2">
                        <div className="flex items-center gap-2 text-xs">
                            <span className="text-slate-400 font-medium">Cohort closes:</span>
                            <CountdownTimer targetDate={new Date(Date.now() + 7 * 24 * 60 * 60 * 1000)} size="sm" variant="inline" />
                        </div>
                        <div className="flex items-center gap-3">
                            <button
                                onClick={() => document.querySelector('main')?.scrollIntoView({ behavior: 'smooth' })}
                                className="flex-1 bg-yellow-500 text-black py-3 px-6 rounded-xl font-black uppercase tracking-widest text-sm hover:bg-yellow-400 transition-all shadow-lg"
                            >
                                Join The Squad
                            </button>
                            <SpotsRemaining />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default SalesPageCohort;
