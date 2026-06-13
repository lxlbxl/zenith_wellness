
import React, { useState, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import MultiStepForm from './ui/MultiStepForm';
import { UserPersona } from '../types';
import MemberCount from './ui/MemberCount';

interface OnboardingQuizProps {
    onComplete: (data: any) => Promise<void>;
    onCancel: () => void;
}

const registerSchema = z.object({
    name: z.string().min(2, "Name is required"),
    email: z.string().email("Invalid email"),
    password: z.string().min(10, "Password must be at least 10 characters"),
    persona: z.enum(['lead', 'newbie', 'active', 'veteran']).optional()
});

type RegisterInputs = z.infer<typeof registerSchema>;

const OnboardingQuiz: React.FC<OnboardingQuizProps> = ({ onComplete, onCancel }) => {
    const [formData, setFormData] = useState<Partial<RegisterInputs>>({
        persona: 'newbie'
    });
    const [todayEnrollments, setTodayEnrollments] = useState<number | null>(null);

    useEffect(() => {
        fetch('/api/stats')
            .then(res => res.json())
            .then(data => {
                if (data.today_enrollments && data.today_enrollments > 0) {
                    setTodayEnrollments(data.today_enrollments);
                }
            })
            .catch(() => {});
    }, []);

    const steps = [
        {
            title: 'Join the Movement',
            description: 'You are in good company.',
            isValid: true,
            component: (
                <div className="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500 text-center py-4">
                    <div className="flex flex-col items-center justify-center gap-4">
                        <div className="w-24 h-24 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-full flex items-center justify-center mb-2 shadow-inner">
                            <i className="fa-solid fa-users-line text-4xl text-indigo-600"></i>
                        </div>
                        <MemberCount variant="full" label="Active Members" />
                    </div>

                    <div className="bg-amber-50 border border-amber-100 p-4 rounded-xl">
                        <p className="text-sm text-amber-800 font-medium">
                            <i className="fa-solid fa-fire mr-2"></i>
                            <strong>High Demand:</strong> {todayEnrollments !== null ? `${todayEnrollments.toLocaleString()} people started their journey today` : 'Spots filling fast — start your journey now'}.
                        </p>
                    </div>

                    <p className="text-stone-500 text-sm leading-relaxed max-w-sm mx-auto">
                        Zenith isn't just an app; it's a protocol used by thousands to optimize their physiology. Let's customize it for you.
                    </p>
                </div>
            )
        },
        {
            title: 'Welcome to Zenith',
            description: "Let's start with your name.",
            isValid: !!formData.name && formData.name.length >= 2,
            component: (
                <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div className="text-center mb-8">
                        <div className="w-20 h-20 bg-stone-900 rounded-full flex items-center justify-center mx-auto mb-4 shadow-xl">
                            <i className="fa-solid fa-hand-sparkles text-3xl text-white"></i>
                        </div>
                        <h3 className="text-2xl font-serif text-stone-800">First things first</h3>
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">What should we call you?</label>
                        <input
                            type="text"
                            value={formData.name || ''}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            placeholder="Your Name"
                            className="w-full px-6 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 text-lg placeholder-stone-300"
                            autoFocus
                        />
                    </div>
                </div>
            )
        },
        {
            title: 'Your Archetype',
            description: 'Which statement resonates with you?',
            isValid: !!formData.persona,
            component: (
                <div className="space-y-4 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    {[
                        { id: 'newbie', label: 'The Explorer', desc: 'I am new to wellness and want guidance.', icon: 'fa-compass' },
                        { id: 'active', label: 'The Optimizer', desc: 'I am active but need structure.', icon: 'fa-person-running' },
                        { id: 'veteran', label: 'The High Performer', desc: 'I want to push my limits.', icon: 'fa-medal' },
                        { id: 'lead', label: 'The Leader', desc: 'I lead others and need peak energy.', icon: 'fa-crown' }
                    ].map((p) => (
                        <div
                            key={p.id}
                            onClick={() => setFormData({ ...formData, persona: p.id as UserPersona })}
                            className={`p-5 rounded-2xl border-2 cursor-pointer transition-all flex items-center gap-4 group ${formData.persona === p.id
                                ? 'border-stone-900 bg-stone-50'
                                : 'border-stone-100 bg-white hover:border-stone-200 hover:shadow-sm'
                                }`}
                        >
                            <div className={`w-12 h-12 rounded-xl flex items-center justify-center text-xl transition-colors ${formData.persona === p.id
                                ? 'bg-stone-900 text-white'
                                : 'bg-stone-100 text-stone-400 group-hover:bg-stone-200 group-hover:text-stone-600'
                                }`}>
                                <i className={`fa-solid ${p.icon}`}></i>
                            </div>
                            <div className="flex-1">
                                <h4 className={`font-serif font-bold ${formData.persona === p.id ? 'text-stone-900' : 'text-stone-600'}`}>{p.label}</h4>
                                <p className="text-xs text-stone-400 font-medium">{p.desc}</p>
                            </div>
                            {formData.persona === p.id && (
                                <i className="fa-solid fa-check-circle text-stone-900 text-xl"></i>
                            )}
                        </div>
                    ))}
                </div>
            )
        },
        {
            title: 'Secure Access',
            description: 'Create your metabolic ID.',
            isValid: !!formData.email && !!formData.password && (() => {
                let score = 0;
                if (formData.password.length >= 10) score++;
                if (/\d/.test(formData.password)) score++;
                if (/[^a-zA-Z0-9]/.test(formData.password)) score++;
                if (/[A-Z]/.test(formData.password)) score++;
                return score >= 4;
            })(),
            component: (
                <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Email Address</label>
                        <input
                            type="email"
                            value={formData.email || ''}
                            onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                            placeholder="you@example.com"
                            className="w-full px-6 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 text-lg placeholder-stone-300"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Create Password</label>
                        <input
                            type="password"
                            value={formData.password || ''}
                            onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                            placeholder="••••••••"
                            className="w-full px-6 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 text-lg placeholder-stone-300"
                        />
                        {formData.password && formData.password.length > 0 && (
                            <div className="mt-3 space-y-2">
                                <div className="flex items-center gap-3">
                                    <div className="flex-1 h-2 bg-stone-100 rounded-full overflow-hidden">
                                        <div
                                            className={`h-full rounded-full transition-all duration-300 ${
                                                (() => {
                                                    let s = 0;
                                                    if (formData.password.length >= 10) s++;
                                                    if (/\d/.test(formData.password)) s++;
                                                    if (/[^a-zA-Z0-9]/.test(formData.password)) s++;
                                                    if (/[A-Z]/.test(formData.password)) s++;
                                                    return s <= 1 ? 'bg-red-500' : s <= 3 ? 'bg-amber-500' : 'bg-green-500';
                                                })()
                                            }`}
                                            style={{ width: `${(() => {
                                                let s = 0;
                                                if (formData.password.length >= 10) s++;
                                                if (/\d/.test(formData.password)) s++;
                                                if (/[^a-zA-Z0-9]/.test(formData.password)) s++;
                                                if (/[A-Z]/.test(formData.password)) s++;
                                                return (s / 4) * 100;
                                            })()}%` }}
                                        />
                                    </div>
                                    <span className={`text-xs font-bold ${
                                        (() => {
                                            let s = 0;
                                            if (formData.password.length >= 10) s++;
                                            if (/\d/.test(formData.password)) s++;
                                            if (/[^a-zA-Z0-9]/.test(formData.password)) s++;
                                            if (/[A-Z]/.test(formData.password)) s++;
                                            return s <= 1 ? 'text-red-500' : s <= 3 ? 'text-amber-500' : 'text-green-600';
                                        })()
                                    }`}>
                                        {(() => {
                                            let s = 0;
                                            if (formData.password.length >= 10) s++;
                                            if (/\d/.test(formData.password)) s++;
                                            if (/[^a-zA-Z0-9]/.test(formData.password)) s++;
                                            if (/[A-Z]/.test(formData.password)) s++;
                                            return s <= 1 ? 'Weak' : s <= 3 ? 'Medium' : 'Strong';
                                        })()}
                                    </span>
                                </div>
                                <ul className="space-y-1 text-[10px] text-stone-400">
                                    <li className={formData.password.length >= 10 ? 'text-green-600' : ''}>
                                        {formData.password.length >= 10 ? '✓' : '○'} At least 10 characters
                                    </li>
                                    <li className={/[A-Z]/.test(formData.password) ? 'text-green-600' : ''}>
                                        {/[A-Z]/.test(formData.password) ? '✓' : '○'} One uppercase letter
                                    </li>
                                    <li className={/\d/.test(formData.password) ? 'text-green-600' : ''}>
                                        {/\d/.test(formData.password) ? '✓' : '○'} One number
                                    </li>
                                    <li className={/[^a-zA-Z0-9]/.test(formData.password) ? 'text-green-600' : ''}>
                                        {/[^a-zA-Z0-9]/.test(formData.password) ? '✓' : '○'} One special character
                                    </li>
                                </ul>
                            </div>
                        )}
                        <p className="text-[10px] text-stone-400 mt-2 ml-1">Must be at least 10 characters with a number, uppercase letter, and special character.</p>
                    </div>
                </div>
            )
        }
    ];

    return (
        <div className="bg-white p-2 rounded-[2rem] h-full flex flex-col relative overflow-hidden shadow-2xl">
            <div className="bg-indigo-600 text-white text-[10px] font-bold uppercase tracking-widest text-center py-2 absolute top-0 left-0 right-0 z-10">
                <i className="fa-solid fa-clock mr-1"></i> Special Offer Expires Soon
            </div>
            <div className="flex justify-end p-2 mt-6">
                <button onClick={onCancel} className="text-stone-400 hover:text-stone-600 text-sm font-bold px-4 py-2">
                    Login instead
                </button>
            </div>
            <MultiStepForm
                steps={steps}
                onComplete={() => onComplete(formData)}
                onCancel={onCancel}
                submitLabel="Start Journey"
            />
        </div>
    );
};

export default OnboardingQuiz;
