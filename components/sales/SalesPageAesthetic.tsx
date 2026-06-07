import React from 'react';
import { User } from '../../types';
import SalesAction from './SalesAction';
import TestimonialCarousel from '../ui/TestimonialCarousel';
import ResultsGallery from '../ui/ResultsGallery';

interface SalesPageAestheticProps {
    user?: User;
    onLogin?: (user: User) => void;
    onPurchase?: () => void;
    price?: number; // Price in cents from cohort settings
}

const SalesPageAesthetic: React.FC<SalesPageAestheticProps> = ({ user, onLogin, onPurchase, price = 2900 }) => {
    return (
        <div className="min-h-screen bg-[#F7F5F0] text-[#2D2D2D] font-sans selection:bg-[#B2C5B2] selection:text-white pb-20 font-['DM_Sans']">
            <div className="fixed top-0 w-full p-6 z-40 flex justify-between uppercase text-xs font-bold tracking-widest mix-blend-multiply pointer-events-none">
                <div>Est. 2026</div>
                <div>Zenith<span className="font-serif italic text-lg lowercase tracking-normal mx-1">wellness</span>Club</div>
                <div className="pointer-events-auto cursor-pointer">{!user ? 'Login' : ''}</div>
            </div>

            <main className="pt-32 pb-20 px-6 container mx-auto max-w-xl text-center">
                <div className="w-full aspect-[4/5] bg-gray-200 rounded-t-[10rem] rounded-b-[2rem] overflow-hidden mb-12 relative group animate-in zoom-in duration-700">
                    <img src="https://images.unsplash.com/photo-1544367563-12123d8965cd?q=80&w=2070&auto=format&fit=crop" className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700" alt="Vibe" />
                    <div className="absolute bottom-6 left-6 bg-white/80 backdrop-blur-sm px-4 py-2 rounded-full text-xs font-bold">
                        ✨ Current Mood: Focused
                    </div>
                </div>

                <h1 className="font-serif italic text-5xl md:text-6xl mb-6 animate-in slide-in-from-bottom duration-700 delay-100">Enter your villain era.</h1>
                <p className="text-sm md:text-base leading-relaxed text-gray-500 mb-10 max-w-sm mx-auto animate-in slide-in-from-bottom duration-700 delay-200">
                    Stop scrolling. Start standardizing. <br />
                    The only app that syncs your pilates, matcha, and cortisol levels into one dashboard.
                </p>

                <div className="bg-white p-8 rounded-[2rem] shadow-xl shadow-[#B2C5B2]/20 text-left relative overflow-hidden animate-in slide-in-from-bottom duration-700 delay-300">
                    <div className="absolute -top-10 -right-10 w-32 h-32 bg-[#B2C5B2]/30 rounded-full blur-2xl"></div>

                    <h3 className="font-bold text-lg mb-6 flex items-center gap-2">
                        <span className="w-2 h-2 bg-[#B2C5B2] rounded-full"></span>
                        Join the club
                    </h3>

                    <SalesAction
                        user={user}
                        onLogin={onLogin}
                        onPurchase={onPurchase}
                        btnText="Get Access"
                        price={price}
                        defaultPersona="newbie"
                    />
                </div>
            </main>

            <section className="py-20 bg-white">
                <div className="container mx-auto px-4 max-w-4xl">
                    <div className="text-center mb-12">
                        <h2 className="text-3xl font-serif text-slate-900 mb-4">Vibe Checks</h2>
                        <TestimonialCarousel />
                    </div>
                </div>
            </section>

            <section className="py-20 bg-[#F7F5F0]">
                <div className="container mx-auto px-4 max-w-5xl">
                    <div className="text-center mb-12">
                        <h2 className="text-3xl font-serif text-slate-900 mb-4">Glow Ups</h2>
                        <ResultsGallery />
                    </div>
                </div>
            </section>

            <footer className="text-center pb-12 text-[10px] uppercase font-bold tracking-widest text-gray-400">
                Designed in Paris • Coded in Silicon Valley
            </footer>
        </div>
    );
};

export default SalesPageAesthetic;
