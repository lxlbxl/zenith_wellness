
import React, { useState } from 'react';
import { User } from '../../types';
import SalesAction from './SalesAction';

interface SalesPageBioSyncProps {
    user?: User;
    onLogin?: (user: User) => void;
    onPurchase?: () => void;
    onSwitchToLogin?: () => void;
    price?: number; // Price in cents from cohort settings
}

const SalesPageBioSync: React.FC<SalesPageBioSyncProps> = ({ user, onLogin, onPurchase, onSwitchToLogin, price = 5900 }) => {
    const [quizStep, setQuizStep] = useState(0);
    const [showForm, setShowForm] = useState(false);

    const quizQuestions = [
        {
            q: "How often do you feel an energy 'crash' in the afternoon?",
            a: ["Daily", "Sometimes", "Rarely", "Never"]
        },
        {
            q: "How would you describe your focus levels right now?",
            a: ["Scattered", "Inconsistent", "Decent", "Razor Sharp"]
        },
        {
            q: "Do you track your biological cycles?",
            a: ["No, I don't", "Sometimes", "Yes, manually", "Yes, with tech"]
        }
    ];

    const handleAnswer = () => {
        if (quizStep < quizQuestions.length - 1) {
            setQuizStep(prev => prev + 1);
        } else {
            setShowForm(true);
        }
    };

    return (
        <div className="min-h-screen bg-white font-sans text-slate-900">
            <header className="py-6 border-b border-slate-100">
                <div className="container mx-auto px-6 flex justify-between items-center">
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 bg-emerald-500 rounded-lg flex items-center justify-center text-white">
                            <i className="fa-solid fa-dna"></i>
                        </div>
                        <span className="font-black text-xl tracking-tighter">ZENITH <span className="text-emerald-600">BIO-SYNC</span></span>
                    </div>
                    <button
                        onClick={onSwitchToLogin}
                        className="text-sm font-bold text-slate-500 hover:text-emerald-600 transition-colors"
                    >
                        Member Login
                    </button>
                </div>
            </header>

            <main className="container mx-auto px-6 py-12 md:py-20 max-w-4xl">
                {!showForm ? (
                    <div className="space-y-12 text-center animate-in fade-in duration-500">
                        <div className="space-y-6 max-w-2xl mx-auto">
                            <div className="inline-block px-4 py-2 bg-emerald-50 text-emerald-700 text-xs font-black uppercase tracking-widest rounded-full">
                                Biological Optimization Engine
                            </div>
                            <h1 className="text-4xl md:text-6xl font-black text-slate-900 tracking-tight leading-tight">
                                Your Productivity is <span className="text-emerald-500 underline decoration-4 decoration-emerald-200 underline-offset-4">Biological</span>.
                            </h1>
                            <p className="text-xl text-slate-500 leading-relaxed">
                                You don't need another todo list. You need to map your workflow to your metabolic and hormonal rhythm.
                                Take the 30-second diagnostic to find your profile.
                            </p>
                        </div>

                        <div className="max-w-xl mx-auto bg-white p-8 md:p-12 rounded-[2.5rem] shadow-2xl shadow-emerald-100 border border-slate-100 relative overflow-hidden">
                            <div className="absolute top-0 left-0 w-full h-2 bg-slate-100">
                                <div
                                    className="h-full bg-emerald-500 transition-all duration-500"
                                    style={{ width: `${((quizStep + 1) / quizQuestions.length) * 100}%` }}
                                ></div>
                            </div>

                            <div className="py-6">
                                <h3 className="text-2xl font-bold mb-8">{quizQuestions[quizStep].q}</h3>
                                <div className="grid grid-cols-1 gap-3">
                                    {quizQuestions[quizStep].a.map((ans, i) => (
                                        <button
                                            key={i}
                                            onClick={handleAnswer}
                                            className="w-full text-left px-6 py-4 rounded-xl border border-slate-200 font-bold text-slate-600 hover:border-emerald-500 hover:bg-emerald-50 hover:text-emerald-700 transition-all"
                                        >
                                            {ans}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-12 items-center animate-in slide-in-from-bottom duration-700">
                        <div className="space-y-8">
                            <div className="bg-emerald-100 w-16 h-16 rounded-2xl flex items-center justify-center text-3xl text-emerald-600 mx-auto md:mx-0">
                                <i className="fa-solid fa-check"></i>
                            </div>
                            <h2 className="text-4xl font-black text-slate-900">Analysis Complete.</h2>
                            <p className="text-lg text-slate-500 leading-relaxed">
                                Your biological data suggests significant untapped potential in your <strong>Luteal Phase</strong> focus blocks.
                            </p>
                            <p className="text-lg text-slate-500">
                                Create your free Zenith account to view your full <strong>Metabolic Profile</strong> and start your personalized Sync Protocol.
                            </p>

                            <div className="bg-slate-50 p-6 rounded-2xl border border-slate-100">
                                <div className="flex items-center gap-4 mb-4">
                                    <i className="fa-solid fa-microchip text-indigo-500 text-2xl"></i>
                                    <span className="font-bold text-slate-700">Zenith IQ Engine Ready</span>
                                </div>
                                <div className="h-2 bg-slate-200 rounded-full overflow-hidden">
                                    <div className="w-full h-full bg-indigo-500 animate-pulse"></div>
                                </div>
                            </div>
                        </div>

                        <div className="bg-white p-8 rounded-[2.5rem] shadow-2xl border border-slate-100">
                            <h3 className="text-xl font-black text-center mb-6">Unlock Your Results</h3>
                            <SalesAction
                                user={user}
                                onLogin={onLogin}
                                onPurchase={onPurchase}
                                onSwitchToLogin={onSwitchToLogin}
                                defaultPersona="active"
                                btnText="Reveal My Protocol"
                                price={price}
                            />
                        </div>
                    </div>
                )}
            </main>

            {/* Social Proof Strip */}
            <section className="bg-slate-50 py-12 border-t border-slate-100 mt-12">
                <div className="container mx-auto px-6 text-center">
                    <p className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-8">Trusted by bio-hackers at</p>
                    <div className="flex flex-wrap justify-center gap-12 opacity-50 grayscale">
                        {['Oura', 'Whoop', 'Levels', 'EightSleep'].map(brand => (
                            <span key={brand} className="text-xl font-black text-slate-400">{brand} users</span>
                        ))}
                    </div>
                </div>
            </section>
        </div>
    );
};

export default SalesPageBioSync;
