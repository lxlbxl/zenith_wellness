import React from 'react';
import { User } from '../../types';
import SalesAction from './SalesAction';
import MemberCount from '../ui/MemberCount';
import TestimonialCarousel from '../ui/TestimonialCarousel';
import ResultsGallery from '../ui/ResultsGallery';

interface SalesPagePCOSProps {
    user?: User;
    onLogin?: (user: User) => void;
    onPurchase?: () => void;
    price?: number; // Price in cents from cohort settings
}

const SalesPagePCOS: React.FC<SalesPagePCOSProps> = ({ user, onLogin, onPurchase, price = 4900 }) => {
    return (
        <div className="min-h-screen bg-rose-50/50 font-sans text-stone-900 font-['Outfit'] pb-20">
            <nav className="py-6 container mx-auto px-6 flex justify-between items-center">
                <div className="flex items-center gap-2">
                    <div className="w-8 h-8 bg-rose-200 text-rose-500 rounded-full flex items-center justify-center">
                        <i className="fa-solid fa-heart-pulse"></i>
                    </div>
                    <span className="font-serif font-bold text-xl tracking-wide text-stone-800">Zenith<span className="text-rose-400">Care</span></span>
                </div>
                {!user && (
                    <button onClick={() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })} className="hidden md:block font-bold text-rose-500 hover:text-rose-600 transition-colors">
                        Member Login
                    </button>
                )}
            </nav>

            <main className="container mx-auto px-6 py-12 md:py-20 grid grid-cols-1 md:grid-cols-2 gap-16 items-center">
                <div className="space-y-8 animate-in slide-in-from-left duration-700">
                    <div className="inline-block px-4 py-1.5 bg-white border border-rose-100 rounded-full text-rose-500 text-xs font-bold uppercase tracking-widest shadow-sm">
                        New: The 21-Day Hormonal Reset
                    </div>
                    <h1 className="font-serif text-5xl md:text-6xl leading-tight text-stone-900">
                        You aren't "lazy". <br />
                        <span className="italic text-rose-500">You are inflamed.</span>
                    </h1>
                    <p className="text-lg text-stone-500 leading-relaxed max-w-md">
                        Traditional diet advice fails women with PCOS and Endometriosis because it ignores your biology.
                        We don't count calories—we count chemicals, stress signals, and cycle phases.
                    </p>

                    <div className="space-y-4 border-l-2 border-rose-200 pl-6">
                        <div className="bg-white p-6 rounded-r-2xl shadow-sm">
                            <p className="italic text-stone-600 mb-4">"I spent 5 years fighting my body. Zenith taught me how to work WITH it. My symptoms vanished in 3 cycles."</p>
                            <div className="flex items-center gap-3">
                                <img src="https://i.pravatar.cc/100?img=5" className="w-10 h-10 rounded-full grayscale" alt="Sarah" />
                                <div>
                                    <div className="font-bold text-sm">Sarah J.</div>
                                    <div className="text-xs text-rose-400 font-bold">Reversed PCOS Symptoms</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="bg-white p-8 md:p-12 rounded-[3rem] shadow-xl border border-rose-100 relative animate-in slide-in-from-right duration-700">
                    <div className="text-center mb-8">
                        <div className="flex justify-center mb-4">
                            <MemberCount variant="badge" label="Women healing now" />
                        </div>
                        <h2 className="font-serif text-3xl text-stone-900 mb-2">Claim Your Care Plan</h2>
                        <p className="text-stone-400 text-sm">Join the 1,200 women healing together.</p>
                    </div>

                    <SalesAction
                        user={user}
                        onLogin={onLogin}
                        onPurchase={onPurchase}
                        btnText="Begin My Healing Journey"
                        defaultPersona="newbie"
                        price={price}
                    />

                    <p className="text-center text-xs text-stone-300 mt-6">HIPAA Compliant Security • Cancel Anytime</p>
                </div>
            </main>

            <section className="bg-white py-20">
                <div className="container mx-auto px-6 max-w-5xl">
                    <h2 className="font-serif text-3xl text-center mb-16">The Zenith Protocol Difference</h2>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-12">
                        {[
                            { icon: 'fa-carrot', title: 'Anti-Inflammatory Nutrition', desc: 'Meal plans that remove triggers and soothe gut lining.' },
                            { icon: 'fa-moon', title: 'Cortisol Management', desc: 'Workouts that don\'t spike your stress hormones.' },
                            { icon: 'fa-user-doctor', title: 'Cycle-Synced Coaching', desc: 'Intelligent guidance that adapts to your follicular and luteal phases.' }
                        ].map((item, i) => (
                            <div key={i} className="text-center space-y-4">
                                <div className="w-16 h-16 mx-auto bg-rose-50 rounded-full flex items-center justify-center text-rose-400 text-2xl">
                                    <i className={`fa-solid ${item.icon}`}></i>
                                </div>
                                <h3 className="font-bold text-lg">{item.title}</h3>
                                <p className="text-sm text-stone-500">{item.desc}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Social Proof */}
            <section className="py-20 bg-slate-50">
                <div className="container mx-auto px-4 max-w-4xl" data-component="testimonials">
                    <div className="text-center mb-12">
                        <h2 className="text-3xl font-serif text-slate-900 mb-4">What Our Members Say</h2>
                        <TestimonialCarousel />
                    </div>
                </div>
            </section>

            {/* Results */}
            <section className="py-20 bg-white">
                <div className="container mx-auto px-4 max-w-5xl" data-component="results">
                    <div className="text-center mb-12">
                        <h2 className="text-3xl font-serif text-slate-900 mb-4">Real Transformations</h2>
                        <ResultsGallery />
                    </div>
                </div>
            </section>
        </div>
    );
};

export default SalesPagePCOS;
