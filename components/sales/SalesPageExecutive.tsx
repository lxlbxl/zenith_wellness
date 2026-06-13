import React from 'react';
import { User } from '../../types';
import SalesAction from './SalesAction';
import MemberCount from '../ui/MemberCount';
import TestimonialCarousel from '../ui/TestimonialCarousel';
import ResultsGallery from '../ui/ResultsGallery';

interface SalesPageExecutiveProps {
    user?: User;
    onLogin?: (user: User) => void;
    onPurchase?: () => void;
    onSwitchToLogin?: () => void;
    price?: number; // Price in cents from cohort settings
}

const SalesPageExecutive: React.FC<SalesPageExecutiveProps> = ({ user, onLogin, onPurchase, onSwitchToLogin, price = 12900 }) => {
    return (
        <div className="min-h-screen bg-black text-white font-sans antialiased selection:bg-[#D4AF37] selection:text-black pb-20">
            <nav className="border-b border-white/10 py-6">
                <div className="container mx-auto px-6 flex justify-between items-center">
                    <span className="font-bold text-2xl tracking-tight">ZENITH<span className="text-[#D4AF37]">.EXEC</span></span>
                    {!user && (
                        <button onClick={onSwitchToLogin} className="text-xs font-bold uppercase tracking-widest text-zinc-500 hover:text-white transition-colors">
                            Client Portal Login
                        </button>
                    )}
                </div>
            </nav>

            <main className="container mx-auto px-6 py-20 lg:py-32 grid grid-cols-1 lg:grid-cols-2 gap-20 items-center">
                <div className="space-y-10 animate-in slide-in-from-left duration-700 delay-100">
                    <div className="inline-flex items-center gap-3 px-4 py-2 bg-white/5 border border-white/10 rounded-none text-[10px] font-bold uppercase tracking-[0.2em] text-[#D4AF37]">
                        <span>Limited Intake: Q1 2026</span>
                    </div>

                    <h1 className="text-5xl lg:text-7xl font-semibold leading-[1.05] tracking-tight">
                        Your biological <br />
                        infrastructure <br />
                        <span className="text-zinc-600">is failing.</span>
                    </h1>

                    <p className="text-zinc-400 text-lg leading-relaxed max-w-lg">
                        You have optimized your portfolio, your team, and your tech stack. But you are running your body on legacy code.
                        <br /><br />
                        Zenith is the <strong>Executive Performance System</strong> that aligns your biological prime time with your high-leverage decisions.
                    </p>

                    <div className="grid grid-cols-2 gap-8 border-t border-white/10 pt-8">
                        <div>
                            <div className="text-3xl font-bold text-white mb-1">4.5h</div>
                            <div className="text-xs text-zinc-500 uppercase tracking-widest">Deep Work / Day</div>
                        </div>
                        <div>
                            <div className="text-3xl font-bold text-white mb-1">15%</div>
                            <div className="text-xs text-zinc-500 uppercase tracking-widest">Cognitive Lift</div>
                        </div>
                    </div>
                </div>

                <div className="bg-zinc-900 border border-white/5 p-10 lg:p-14 relative animate-in slide-in-from-right duration-700">
                    <div className="absolute -top-4 -right-4 w-20 h-20 bg-[#D4AF37]/20 blur-3xl rounded-full pointer-events-none"></div>

                    <div className="flex overflow-hidden mb-6">
                        <div className="bg-zinc-800 px-4 py-1 rounded text-xs text-zinc-400 font-mono">
                            <MemberCount variant="minimal" label="" /> active execs
                        </div>
                    </div>

                    <h2 className="text-2xl mb-8">Secure Your Protocol</h2>

                    <SalesAction
                        user={user}
                        onLogin={onLogin}
                        onPurchase={onPurchase}
                        btnText="Initiate Application"
                        price={price}
                        defaultPersona="lead"
                        isDark={true}
                    />
                </div>
            </main>

            <section className="py-20 bg-zinc-900">
                <div className="container mx-auto px-4 max-w-4xl">
                    <div className="text-center mb-12">
                        <h2 className="text-3xl font-serif text-white mb-4">Verified Outcomes</h2>
                        <TestimonialCarousel />
                    </div>
                </div>
            </section>

            <section className="py-20 bg-black">
                <div className="container mx-auto px-4 max-w-5xl">
                    <div className="text-center mb-12">
                        <h2 className="text-3xl font-serif text-white mb-4">Performance Data</h2>
                        <ResultsGallery />
                    </div>
                </div>
            </section>

            <section className="border-t border-white/10 py-12">
                <div className="container mx-auto px-6 flex flex-col md:flex-row gap-12 justify-between items-end">
                    <p className="text-zinc-500 text-sm max-w-md">
                        "The most significant ROI I have seen in 30 years of business wasn't a stock. It was sleeping properly."
                        <br /><br />
                        <span className="text-white font-bold">— Alex T, Hedge Fund Manager</span>
                    </p>
                    <div className="flex gap-4">
                        <div className="w-12 h-12 border border-white/10 flex items-center justify-center text-zinc-500"><i className="fa-brands fa-apple"></i></div>
                        <div className="w-12 h-12 border border-white/10 flex items-center justify-center text-zinc-500"><i className="fa-brands fa-google"></i></div>
                        <div className="w-12 h-12 border border-white/10 flex items-center justify-center text-zinc-500"><i className="fa-brands fa-amazon"></i></div>
                    </div>
                </div>
            </section>
            {/* Sticky Mobile CTA */}
            {!user && (
                <div className="fixed bottom-0 left-0 right-0 z-50 bg-zinc-900 border-t border-white/10 shadow-2xl md:hidden p-4">
                    <div className="flex items-center justify-between gap-4">
                        <div className="flex-1 min-w-0">
                            <button
                                onClick={onPurchase}
                                className="w-full bg-[#D4AF37] text-black py-3 px-6 rounded-xl font-black uppercase tracking-widest text-sm hover:bg-[#e8c84a] transition-all shadow-lg"
                            >
                                Initiate Application
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default SalesPageExecutive;
