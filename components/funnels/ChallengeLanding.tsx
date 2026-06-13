import React, { useState, useEffect } from 'react';
import { User } from '../../types';
import { track } from '../../src/analytics';

interface ChallengeLandingProps {
    onRegister?: (user: User) => void;
    onLogin?: (user: User) => void;
    onSwitchToLogin?: () => void;
}

const ChallengeLanding: React.FC<ChallengeLandingProps> = ({ onRegister, onLogin, onSwitchToLogin }) => {
    const [countdown, setCountdown] = useState({ days: 2, hours: 14, minutes: 47, seconds: 23 });
    const [showPaymentModal, setShowPaymentModal] = useState(false);
    const [expandedFaq, setExpandedFaq] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Countdown timer
    useEffect(() => {
        const timer = setInterval(() => {
            setCountdown(prev => {
                let { days, hours, minutes, seconds } = prev;
                seconds--;
                if (seconds < 0) { seconds = 59; minutes--; }
                if (minutes < 0) { minutes = 59; hours--; }
                if (hours < 0) { hours = 23; days--; }
                if (days < 0) { days = 0; hours = 0; minutes = 0; seconds = 0; }
                return { days, hours, minutes, seconds };
            });
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    const weekData = [
        {
            week: 1,
            title: 'Foundation Week',
            status: 'free',
            days: ['Reset Your Metabolism', 'Morning Ritual Setup', 'Hormone-Friendly Nutrition', 'Movement Basics', 'Sleep Optimization', 'Stress Audit', 'Week 1 Checkpoint'],
            results: 'By Day 7, most women report 40% better sleep and reduced bloating',
            icon: '🌱'
        },
        {
            week: 2,
            title: 'Transformation Week',
            status: 'locked',
            days: ['Cycle Syncing Intro', 'Energy Optimization', 'Advanced Nutrition', 'Strength Building', 'Emotional Release', 'Community Challenge', 'Week 2 Checkpoint'],
            results: 'By Day 14, 78% report significant energy improvements',
            icon: '🔥'
        },
        {
            week: 3,
            title: 'Integration Week',
            status: 'locked',
            days: ['Habit Stacking', 'Lifestyle Design', 'Sustained Results', 'Future Planning', 'Community Celebration', 'Final Assessment', 'Graduation Day'],
            results: 'By Day 21, your new habits are locked in for life',
            icon: '👑'
        }
    ];

    const testimonials = [
        { name: 'Aisha O.', location: 'Lagos', text: 'I lost 4kg in 21 days without feeling deprived. The community kept me accountable!', avatar: '👩🏾' },
        { name: 'Blessing N.', location: 'Abuja', text: 'My periods are finally regular for the first time in years. This program changed everything.', avatar: '👩🏽' },
        { name: 'Chioma E.', location: 'Port Harcourt', text: 'The energy I have now is unreal. I wake up before my alarm!', avatar: '👩🏿' },
        { name: 'Funke A.', location: 'Ibadan', text: 'Best money I ever spent. Sara AI is like having a personal coach 24/7.', avatar: '👩🏾' },
    ];

    const faqs = [
        { q: 'What happens after the free 7 days?', a: 'After Day 7, you\'ll need to upgrade to continue. But don\'t worry - you can keep your Week 1 progress and the basics we teach. The real transformation happens in Weeks 2 & 3 with advanced protocols.' },
        { q: 'Is this safe for PCOS?', a: 'Absolutely! Our protocols are specifically designed for hormonal conditions like PCOS. Many of our members have PCOS and see amazing results.' },
        { q: 'How much time do I need daily?', a: 'About 20-30 minutes. Our daily missions are designed for busy women. You can do them in the morning, lunch break, or evening.' },
        { q: 'What if I miss a day?', a: 'Life happens! You can catch up at your own pace. The program is designed to be flexible while still delivering results.' },
        { q: 'Is there a money-back guarantee?', a: 'Yes! If you complete the program and don\'t see results, we\'ll refund you in full. No questions asked.' },
    ];

    const handleFreeSignup = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        try {
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name,
                    email,
                    password: Math.random().toString(36).slice(-8), // Temporary password
                    persona: 'newbie',
                    source: '21day_challenge',
                    challenge_tier: 'free',
                }),
            });

            if (response.ok) {
                const data = await response.json();
                track('sign_up', { method: 'email', source: '21day_challenge' });
                track('challenge_landing');
                onRegister?.(data.user);
            }
        } catch {
            // Handle error
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <div className="min-h-screen bg-slate-950 text-white font-sans">
            {/* Announcement bar */}
            <div className="bg-gradient-to-r from-emerald-600 to-teal-600 py-3 px-4 text-center text-sm font-bold">
                <span className="animate-pulse">🔥</span> 247 women joined today • <span className="underline">Only 53 spots left</span>
            </div>

            {/* Header */}
            <header className="container mx-auto px-6 py-6 flex justify-between items-center">
                <div className="font-black text-2xl tracking-tighter">
                    ZENITH<span className="text-emerald-400">21</span>
                </div>
                <div className="flex items-center gap-4">
                    {onSwitchToLogin && (
                        <button
                            onClick={onSwitchToLogin}
                            className="text-sm text-slate-400 hover:text-white transition-colors"
                        >
                            Already have access? Login
                        </button>
                    )}
                </div>
            </header>

            {/* Hero Section */}
            <section className="relative overflow-hidden">
                {/* Background effects */}
                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute top-0 left-1/4 w-[600px] h-[600px] bg-emerald-500/10 rounded-full blur-[120px]" />
                    <div className="absolute bottom-0 right-1/4 w-[400px] h-[400px] bg-teal-500/10 rounded-full blur-[100px]" />
                </div>

                <div className="container mx-auto px-6 py-16 md:py-24 relative z-10">
                    <div className="max-w-4xl mx-auto text-center">
                        {/* Countdown */}
                        <div className="inline-flex items-center gap-4 mb-8 bg-white/5 backdrop-blur-sm px-6 py-3 rounded-full border border-white/10">
                            <span className="text-red-400 font-bold text-sm">Cohort closes in:</span>
                            <div className="flex gap-2 font-mono">
                                <div className="bg-red-500/20 px-3 py-1 rounded-lg text-red-300">
                                    {String(countdown.days).padStart(2, '0')}d
                                </div>
                                <div className="bg-red-500/20 px-3 py-1 rounded-lg text-red-300">
                                    {String(countdown.hours).padStart(2, '0')}h
                                </div>
                                <div className="bg-red-500/20 px-3 py-1 rounded-lg text-red-300">
                                    {String(countdown.minutes).padStart(2, '0')}m
                                </div>
                                <div className="bg-red-500/20 px-3 py-1 rounded-lg text-red-300 animate-pulse">
                                    {String(countdown.seconds).padStart(2, '0')}s
                                </div>
                            </div>
                        </div>

                        {/* Headline */}
                        <h1 className="text-4xl md:text-6xl lg:text-7xl font-black leading-none mb-6">
                            Transform Your Body<br />
                            <span className="text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 to-teal-400">
                                In Just 21 Days
                            </span>
                        </h1>

                        <p className="text-xl text-slate-300 max-w-2xl mx-auto mb-10">
                            Join 15,000+ women who used our science-backed protocol to balance hormones, boost energy, and finally feel at home in their bodies. <strong className="text-emerald-400">First 7 days are FREE.</strong>
                        </p>

                        {/* CTA Form */}
                        <form onSubmit={handleFreeSignup} className="max-w-md mx-auto space-y-4">
                            <input
                                type="text"
                                placeholder="Your first name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                required
                                className="w-full px-6 py-4 bg-white/10 border border-white/20 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            <input
                                type="email"
                                placeholder="Your email address"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                required
                                className="w-full px-6 py-4 bg-white/10 border border-white/20 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="w-full py-5 bg-gradient-to-r from-emerald-500 to-teal-500 rounded-xl font-bold text-lg shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 transform hover:-translate-y-1 transition-all disabled:opacity-50"
                            >
                                {isSubmitting ? 'Starting...' : 'Start My Free 7 Days →'}
                            </button>
                        </form>

                        <p className="mt-4 text-sm text-slate-500">
                            ✓ No credit card required • ✓ Cancel anytime • ✓ Instant access
                        </p>

                        {/* Social proof */}
                        <div className="mt-12 flex items-center justify-center gap-2">
                            <div className="flex -space-x-3">
                                {['👩🏾', '👩🏽', '👩🏿', '👩🏻', '👩🏼'].map((emoji, i) => (
                                    <div key={i} className="w-10 h-10 rounded-full bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center text-lg border-2 border-slate-950">
                                        {emoji}
                                    </div>
                                ))}
                            </div>
                            <span className="text-sm text-slate-400">
                                <strong className="text-white">2,847</strong> women started this week
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            {/* What You'll Transform */}
            <section className="py-20 bg-slate-900/50">
                <div className="container mx-auto px-6">
                    <h2 className="text-3xl md:text-4xl font-black text-center mb-16">
                        What You'll Transform
                    </h2>

                    <div className="grid md:grid-cols-3 gap-8 max-w-5xl mx-auto">
                        {[
                            { icon: '⚡', title: 'Energy Levels', before: 'Exhausted by 2pm', after: 'Energized all day' },
                            { icon: '🌙', title: 'Hormone Balance', before: 'Mood swings & PMS', after: 'Stable & in control' },
                            { icon: '🔥', title: 'Metabolism', before: 'Can\'t lose weight', after: 'Effortless results' },
                        ].map((item, i) => (
                            <div key={i} className="bg-slate-800/50 p-8 rounded-3xl border border-white/5 hover:border-emerald-500/30 transition-colors">
                                <div className="text-5xl mb-6">{item.icon}</div>
                                <h3 className="font-bold text-xl mb-4">{item.title}</h3>
                                <div className="space-y-3">
                                    <div className="flex items-center gap-3">
                                        <span className="text-red-400">✗</span>
                                        <span className="text-slate-400 line-through">{item.before}</span>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <span className="text-emerald-400">✓</span>
                                        <span className="text-white font-medium">{item.after}</span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Week Breakdown */}
            <section className="py-20">
                <div className="container mx-auto px-6">
                    <h2 className="text-3xl md:text-4xl font-black text-center mb-4">
                        Your 21-Day Journey
                    </h2>
                    <p className="text-center text-slate-400 mb-16 max-w-xl mx-auto">
                        Week 1 is completely FREE. See results before you invest a single Naira.
                    </p>

                    <div className="max-w-4xl mx-auto space-y-8">
                        {weekData.map((week) => (
                            <div
                                key={week.week}
                                className={`relative p-8 rounded-3xl border transition-all ${week.status === 'free'
                                    ? 'bg-gradient-to-br from-emerald-900/30 to-teal-900/30 border-emerald-500/30'
                                    : 'bg-slate-800/30 border-white/5'
                                    }`}
                            >
                                {/* Status badge */}
                                <div className="absolute -top-3 right-8">
                                    {week.status === 'free' ? (
                                        <span className="px-4 py-1 bg-emerald-500 text-black font-bold text-xs uppercase rounded-full">
                                            ✓ FREE
                                        </span>
                                    ) : (
                                        <span className="px-4 py-1 bg-slate-700 text-slate-300 font-bold text-xs uppercase rounded-full flex items-center gap-1">
                                            <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clipRule="evenodd" />
                                            </svg>
                                            Premium
                                        </span>
                                    )}
                                </div>

                                <div className="flex items-start gap-6">
                                    <div className="text-5xl">{week.icon}</div>
                                    <div className="flex-1">
                                        <h3 className="font-bold text-2xl mb-2">Week {week.week}: {week.title}</h3>
                                        <p className="text-emerald-400 text-sm font-medium mb-4">{week.results}</p>

                                        <div className="grid grid-cols-7 gap-2">
                                            {week.days.map((day, i) => (
                                                <div
                                                    key={i}
                                                    className={`text-center p-2 rounded-lg text-xs ${week.status === 'free'
                                                        ? 'bg-emerald-500/20 text-emerald-300'
                                                        : 'bg-slate-700/50 text-slate-400'
                                                        }`}
                                                >
                                                    <div className="font-bold">Day {(week.week - 1) * 7 + i + 1}</div>
                                                    <div className="mt-1 truncate" title={day}>{day.split(' ')[0]}</div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Testimonials */}
            <section className="py-20 bg-slate-900/50">
                <div className="container mx-auto px-6">
                    <h2 className="text-3xl md:text-4xl font-black text-center mb-16">
                        Real Women. Real Results.
                    </h2>

                    <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
                        {testimonials.map((t, i) => (
                            <div key={i} className="bg-slate-800/50 p-6 rounded-2xl border border-white/5">
                                <div className="text-4xl mb-4">{t.avatar}</div>
                                <p className="text-slate-300 mb-4 italic">"{t.text}"</p>
                                <div className="text-sm">
                                    <span className="font-bold text-white">{t.name}</span>
                                    <span className="text-slate-500"> • {t.location}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Pricing */}
            <section className="py-20">
                <div className="container mx-auto px-6">
                    <div className="max-w-lg mx-auto text-center">
                        <h2 className="text-3xl md:text-4xl font-black mb-4">
                            Invest In Yourself
                        </h2>
                        <p className="text-slate-400 mb-12">
                            Start free. Upgrade when you're ready for the full transformation.
                        </p>

                        {/* Pricing card */}
                        <div className="bg-gradient-to-br from-slate-800 to-slate-900 p-8 rounded-3xl border border-emerald-500/30 relative overflow-hidden">
                            <div className="absolute top-0 right-0 w-32 h-32 bg-emerald-500/20 rounded-full blur-3xl" />

                            <div className="relative z-10">
                                <div className="text-sm text-emerald-400 font-bold uppercase tracking-wider mb-2">
                                    Full 21-Day Access
                                </div>

                                <div className="mb-6">
                                    <span className="text-slate-500 line-through text-2xl">₦15,000</span>
                                    <span className="text-5xl font-black text-white ml-3">₦8,900</span>
                                </div>

                                <ul className="text-left space-y-3 mb-8">
                                    {[
                                        'Full 21-day protocol access',
                                        'AI Coach Sara (unlimited)',
                                        'Private WhatsApp community',
                                        'Daily mission control',
                                        'Meal plans & workout guides',
                                        'Lifetime access to recordings',
                                        '100% money-back guarantee',
                                    ].map((item, i) => (
                                        <li key={i} className="flex items-center gap-3">
                                            <svg className="w-5 h-5 text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                            </svg>
                                            <span>{item}</span>
                                        </li>
                                    ))}
                                </ul>

                                <button
                                    onClick={() => { setShowPaymentModal(true); track('purchase', { value: 8900, currency: 'NGN', program_title: '21-Day Challenge' }); }}
                                    className="w-full py-5 bg-gradient-to-r from-emerald-500 to-teal-500 rounded-xl font-bold text-lg hover:shadow-lg hover:shadow-emerald-500/30 transition-all"
                                >
                                    Get Full Access Now
                                </button>

                                <p className="mt-4 text-xs text-slate-500">
                                    Or start with 7 free days above
                                </p>
                            </div>
                        </div>

                        {/* Guarantee */}
                        <div className="mt-8 flex items-center justify-center gap-3 text-sm text-slate-400">
                            <span className="text-2xl">🛡️</span>
                            <span>100% Money-Back Guarantee. No questions asked.</span>
                        </div>
                    </div>
                </div>
            </section>

            {/* FAQ */}
            <section className="py-20 bg-slate-900/50">
                <div className="container mx-auto px-6">
                    <h2 className="text-3xl md:text-4xl font-black text-center mb-16">
                        Common Questions
                    </h2>

                    <div className="max-w-2xl mx-auto space-y-4">
                        {faqs.map((faq, i) => (
                            <div
                                key={i}
                                className="bg-slate-800/50 rounded-2xl border border-white/5 overflow-hidden"
                            >
                                <button
                                    onClick={() => setExpandedFaq(expandedFaq === i ? null : i)}
                                    className="w-full p-6 flex items-center justify-between text-left"
                                >
                                    <span className="font-bold">{faq.q}</span>
                                    <svg
                                        className={`w-5 h-5 transition-transform ${expandedFaq === i ? 'rotate-180' : ''}`}
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                                {expandedFaq === i && (
                                    <div className="px-6 pb-6 text-slate-400">
                                        {faq.a}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Final CTA */}
            <section className="py-20">
                <div className="container mx-auto px-6 text-center">
                    <h2 className="text-3xl md:text-4xl font-black mb-6">
                        Your Transformation Starts Now
                    </h2>
                    <p className="text-slate-400 mb-10 max-w-xl mx-auto">
                        Don't spend another day feeling exhausted, frustrated, and stuck. Join thousands of women who chose to transform.
                    </p>

                    <button
                        onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
                        className="inline-flex items-center gap-3 px-10 py-5 bg-gradient-to-r from-emerald-500 to-teal-500 rounded-2xl font-bold text-lg shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 transform hover:-translate-y-1 transition-all"
                    >
                        Start My Free 7 Days
                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </button>

                    {/* Countdown reminder */}
                    <p className="mt-6 text-red-400 font-medium animate-pulse">
                        ⏰ Only {countdown.days}d {countdown.hours}h {countdown.minutes}m left to join this cohort
                    </p>
                </div>
            </section>

            {/* Footer */}
            <footer className="py-8 border-t border-white/5">
                <div className="container mx-auto px-6 text-center text-sm text-slate-500">
                    © 2026 Zenith Wellness. All rights reserved. | <a href="/privacy" className="hover:text-white">Privacy</a> | <a href="/terms" className="hover:text-white">Terms</a>
                </div>
            </footer>

            {/* Payment Modal Placeholder */}
            {showPaymentModal && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-6">
                    <div className="bg-slate-900 p-8 rounded-3xl max-w-md w-full border border-white/10">
                        <h3 className="text-2xl font-bold mb-6">Complete Your Purchase</h3>
                        <p className="text-slate-400 mb-6">Payment integration will redirect to Paystack or Flutterwave.</p>
                        <button
                            onClick={() => setShowPaymentModal(false)}
                            className="w-full py-4 bg-slate-700 rounded-xl font-bold"
                        >
                            Close
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ChallengeLanding;
