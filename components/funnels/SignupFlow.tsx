import React, { useState } from 'react';
import { User } from '../../types';
import { track } from '../../src/analytics';

interface SignupFlowProps {
    onComplete: (user: User) => void;
    onSwitchToLogin?: () => void;
}

const SignupFlow: React.FC<SignupFlowProps> = ({ onComplete, onSwitchToLogin }) => {
    const [step, setStep] = useState(0);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');

    // Form data
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        password: '',
        goals: [] as string[],
        primaryFocus: '',
        persona: 'lead' as 'lead' | 'active' | 'veteran',
    });

    const goals = [
        { id: 'weight', label: 'Weight Management', icon: 'fa-scale-balanced', color: 'emerald' },
        { id: 'energy', label: 'More Energy', icon: 'fa-bolt', color: 'amber' },
        { id: 'hormones', label: 'Hormonal Balance', icon: 'fa-heart-pulse', color: 'rose' },
        { id: 'focus', label: 'Mental Clarity', icon: 'fa-brain', color: 'indigo' },
        { id: 'sleep', label: 'Better Sleep', icon: 'fa-moon', color: 'purple' },
        { id: 'fitness', label: 'Fitness Goals', icon: 'fa-dumbbell', color: 'blue' },
    ];

    const focusAreas = [
        { id: 'pcos', label: 'PCOS / Hormonal Health', desc: 'Cycle-synced protocols for hormonal healing' },
        { id: 'metabolic', label: 'Metabolic Reset', desc: '21-day transformation for sustainable weight loss' },
        { id: 'performance', label: 'Peak Performance', desc: 'Executive-level cognitive optimization' },
        { id: 'general', label: 'Overall Wellness', desc: 'Holistic approach to mind-body balance' },
    ];

    const toggleGoal = (goalId: string) => {
        setFormData(prev => ({
            ...prev,
            goals: prev.goals.includes(goalId)
                ? prev.goals.filter(g => g !== goalId)
                : [...prev.goals, goalId]
        }));
    };

    const handleSubmit = async () => {
        setIsLoading(true);
        setError('');

        try {
            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: formData.name,
                    email: formData.email,
                    password: formData.password,
                    persona: formData.persona,
                    metadata: {
                        goals: formData.goals,
                        primaryFocus: formData.primaryFocus,
                        signupSource: 'multi_step_flow',
                    }
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Registration failed');
            }

            // Store token
            if (data.token) {
                localStorage.setItem('auth_token', data.token);
            }

            // Move to success step
            setStep(5);

            // Track signup
            track('sign_up', {
                method: 'email',
                focus: formData.primaryFocus,
                goals: formData.goals.join(','),
            });

            // After 2 seconds, complete the flow
            setTimeout(() => {
                onComplete(data.user);
            }, 2500);

        } catch (err: any) {
            setError(err.message || 'Something went wrong');
        } finally {
            setIsLoading(false);
        }
    };

    const nextStep = () => {
        if (step === 4) {
            handleSubmit();
        } else {
            setStep(prev => prev + 1);
        }
    };

    const prevStep = () => setStep(prev => Math.max(0, prev - 1));

    const canProceed = () => {
        switch (step) {
            case 0: return true;
            case 1: return formData.name.length >= 2 && formData.email.includes('@');
            case 2: return formData.goals.length > 0;
            case 3: return formData.primaryFocus !== '';
            case 4: return formData.password.length >= 6;
            default: return false;
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 flex items-center justify-center p-4 relative overflow-hidden">
            {/* Animated background */}
            <div className="absolute inset-0 overflow-hidden pointer-events-none">
                <div className="absolute top-1/4 -left-20 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl animate-pulse" />
                <div className="absolute bottom-1/4 -right-20 w-96 h-96 bg-purple-500/20 rounded-full blur-3xl animate-pulse delay-1000" />
                <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[40rem] h-[40rem] bg-emerald-500/10 rounded-full blur-3xl" />
            </div>

            <div className="w-full max-w-xl relative z-10">
                {/* Progress bar */}
                {step > 0 && step < 5 && (
                    <div className="mb-8">
                        <div className="flex justify-between text-xs font-bold text-white/50 mb-2">
                            <span>Step {step} of 4</span>
                            <span>{Math.round((step / 4) * 100)}% Complete</span>
                        </div>
                        <div className="h-2 bg-white/10 rounded-full overflow-hidden">
                            <div
                                className="h-full bg-gradient-to-r from-indigo-500 to-emerald-500 transition-all duration-500"
                                style={{ width: `${(step / 4) * 100}%` }}
                            />
                        </div>
                    </div>
                )}

                {/* Card container */}
                <div className="bg-white/5 backdrop-blur-xl border border-white/10 rounded-[2rem] p-8 md:p-12 shadow-2xl">

                    {/* Step 0: Welcome */}
                    {step === 0 && (
                        <div className="text-center animate-in fade-in slide-in-from-bottom-4 duration-700">
                            <div className="w-20 h-20 mx-auto bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl flex items-center justify-center text-white text-3xl mb-8 shadow-lg shadow-indigo-500/30">
                                <i className="fa-solid fa-mountain-sun"></i>
                            </div>

                            <h1 className="text-3xl md:text-4xl font-black text-white mb-4 tracking-tight">
                                Welcome to <span className="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-emerald-400">Zenith</span>
                            </h1>

                            <p className="text-white/60 text-lg mb-8 leading-relaxed max-w-md mx-auto">
                                You're about to join 2,400+ members optimizing their biology for peak performance and lasting wellness.
                            </p>

                            <div className="grid grid-cols-3 gap-4 mb-10">
                                {[
                                    { icon: 'fa-dna', label: 'Personalized' },
                                    { icon: 'fa-robot', label: 'AI-Powered' },
                                    { icon: 'fa-users', label: 'Community' },
                                ].map((item, i) => (
                                    <div key={i} className="bg-white/5 rounded-xl p-4 border border-white/5">
                                        <i className={`fa-solid ${item.icon} text-indigo-400 text-xl mb-2`}></i>
                                        <p className="text-white/70 text-xs font-bold uppercase tracking-wider">{item.label}</p>
                                    </div>
                                ))}
                            </div>

                            <button
                                onClick={nextStep}
                                className="w-full bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-4 px-8 rounded-xl font-black text-lg uppercase tracking-wider hover:from-indigo-500 hover:to-purple-500 transition-all shadow-lg shadow-indigo-500/30 hover:shadow-xl hover:shadow-indigo-500/40 hover:-translate-y-0.5"
                            >
                                Start Your Application
                            </button>

                            <p className="text-white/40 text-sm mt-6">
                                Already have an account?{' '}
                                <button onClick={onSwitchToLogin} className="text-indigo-400 font-bold hover:text-indigo-300 transition-colors">
                                    Sign In
                                </button>
                            </p>
                        </div>
                    )}

                    {/* Step 1: Basic Info */}
                    {step === 1 && (
                        <div className="animate-in fade-in slide-in-from-right duration-500">
                            <button onClick={prevStep} className="text-white/50 hover:text-white mb-6 text-sm font-bold flex items-center gap-2">
                                <i className="fa-solid fa-arrow-left"></i> Back
                            </button>

                            <h2 className="text-2xl font-black text-white mb-2">Let's get to know you</h2>
                            <p className="text-white/50 mb-8">Tell us your name and how to reach you.</p>

                            <div className="space-y-6">
                                <div>
                                    <label className="block text-white/70 text-sm font-bold mb-2">Your Name</label>
                                    <input
                                        type="text"
                                        value={formData.name}
                                        onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
                                        placeholder="Enter your full name"
                                        className="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-4 text-white placeholder:text-white/30 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all"
                                    />
                                </div>

                                <div>
                                    <label className="block text-white/70 text-sm font-bold mb-2">Email Address</label>
                                    <input
                                        type="email"
                                        value={formData.email}
                                        onChange={(e) => setFormData(prev => ({ ...prev, email: e.target.value }))}
                                        placeholder="your@email.com"
                                        className="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-4 text-white placeholder:text-white/30 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all"
                                    />
                                </div>
                            </div>

                            <button
                                onClick={nextStep}
                                disabled={!canProceed()}
                                className="w-full mt-8 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-4 px-8 rounded-xl font-black uppercase tracking-wider disabled:opacity-50 disabled:cursor-not-allowed hover:from-indigo-500 hover:to-purple-500 transition-all"
                            >
                                Continue
                            </button>
                        </div>
                    )}

                    {/* Step 2: Goals */}
                    {step === 2 && (
                        <div className="animate-in fade-in slide-in-from-right duration-500">
                            <button onClick={prevStep} className="text-white/50 hover:text-white mb-6 text-sm font-bold flex items-center gap-2">
                                <i className="fa-solid fa-arrow-left"></i> Back
                            </button>

                            <h2 className="text-2xl font-black text-white mb-2">What are your goals?</h2>
                            <p className="text-white/50 mb-8">Select all that apply to personalize your experience.</p>

                            <div className="grid grid-cols-2 gap-3">
                                {goals.map((goal) => (
                                    <button
                                        key={goal.id}
                                        onClick={() => toggleGoal(goal.id)}
                                        className={`p-4 rounded-xl border text-left transition-all ${formData.goals.includes(goal.id)
                                                ? 'bg-indigo-600/20 border-indigo-500 text-white'
                                                : 'bg-white/5 border-white/10 text-white/70 hover:bg-white/10 hover:border-white/20'
                                            }`}
                                    >
                                        <i className={`fa-solid ${goal.icon} text-xl mb-2 ${formData.goals.includes(goal.id) ? 'text-indigo-400' : 'text-white/40'}`}></i>
                                        <p className="font-bold text-sm">{goal.label}</p>
                                    </button>
                                ))}
                            </div>

                            <button
                                onClick={nextStep}
                                disabled={!canProceed()}
                                className="w-full mt-8 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-4 px-8 rounded-xl font-black uppercase tracking-wider disabled:opacity-50 disabled:cursor-not-allowed hover:from-indigo-500 hover:to-purple-500 transition-all"
                            >
                                Continue
                            </button>
                        </div>
                    )}

                    {/* Step 3: Primary Focus */}
                    {step === 3 && (
                        <div className="animate-in fade-in slide-in-from-right duration-500">
                            <button onClick={prevStep} className="text-white/50 hover:text-white mb-6 text-sm font-bold flex items-center gap-2">
                                <i className="fa-solid fa-arrow-left"></i> Back
                            </button>

                            <h2 className="text-2xl font-black text-white mb-2">Choose your primary focus</h2>
                            <p className="text-white/50 mb-8">We'll customize your dashboard and recommendations.</p>

                            <div className="space-y-3">
                                {focusAreas.map((area) => (
                                    <button
                                        key={area.id}
                                        onClick={() => setFormData(prev => ({ ...prev, primaryFocus: area.id }))}
                                        className={`w-full p-5 rounded-xl border text-left transition-all ${formData.primaryFocus === area.id
                                                ? 'bg-indigo-600/20 border-indigo-500'
                                                : 'bg-white/5 border-white/10 hover:bg-white/10 hover:border-white/20'
                                            }`}
                                    >
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="font-bold text-white">{area.label}</p>
                                                <p className="text-white/50 text-sm mt-1">{area.desc}</p>
                                            </div>
                                            <div className={`w-6 h-6 rounded-full border-2 flex items-center justify-center ${formData.primaryFocus === area.id
                                                    ? 'border-indigo-500 bg-indigo-500'
                                                    : 'border-white/30'
                                                }`}>
                                                {formData.primaryFocus === area.id && (
                                                    <i className="fa-solid fa-check text-white text-xs"></i>
                                                )}
                                            </div>
                                        </div>
                                    </button>
                                ))}
                            </div>

                            <button
                                onClick={nextStep}
                                disabled={!canProceed()}
                                className="w-full mt-8 bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-4 px-8 rounded-xl font-black uppercase tracking-wider disabled:opacity-50 disabled:cursor-not-allowed hover:from-indigo-500 hover:to-purple-500 transition-all"
                            >
                                Continue
                            </button>
                        </div>
                    )}

                    {/* Step 4: Create Password */}
                    {step === 4 && (
                        <div className="animate-in fade-in slide-in-from-right duration-500">
                            <button onClick={prevStep} className="text-white/50 hover:text-white mb-6 text-sm font-bold flex items-center gap-2">
                                <i className="fa-solid fa-arrow-left"></i> Back
                            </button>

                            <h2 className="text-2xl font-black text-white mb-2">Secure your account</h2>
                            <p className="text-white/50 mb-8">Create a password to protect your data.</p>

                            {error && (
                                <div className="bg-red-500/20 border border-red-500/50 text-red-300 px-4 py-3 rounded-xl mb-6 text-sm">
                                    <i className="fa-solid fa-circle-exclamation mr-2"></i>
                                    {error}
                                </div>
                            )}

                            <div className="space-y-6">
                                <div>
                                    <label className="block text-white/70 text-sm font-bold mb-2">Choose a Password</label>
                                    <input
                                        type="password"
                                        value={formData.password}
                                        onChange={(e) => setFormData(prev => ({ ...prev, password: e.target.value }))}
                                        placeholder="Minimum 6 characters"
                                        className="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-4 text-white placeholder:text-white/30 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all"
                                    />
                                    <p className="text-white/40 text-xs mt-2">
                                        <i className="fa-solid fa-shield-halved mr-1"></i>
                                        Your data is encrypted and secure
                                    </p>
                                </div>

                                <div className="bg-white/5 rounded-xl p-4 border border-white/10">
                                    <p className="text-white/70 text-sm mb-3 font-bold">Your Application Summary</p>
                                    <div className="space-y-2 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-white/50">Name</span>
                                            <span className="text-white font-bold">{formData.name}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-white/50">Email</span>
                                            <span className="text-white font-bold">{formData.email}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-white/50">Focus</span>
                                            <span className="text-white font-bold capitalize">{formData.primaryFocus || 'General'}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button
                                onClick={nextStep}
                                disabled={!canProceed() || isLoading}
                                className="w-full mt-8 bg-gradient-to-r from-emerald-600 to-teal-600 text-white py-4 px-8 rounded-xl font-black uppercase tracking-wider disabled:opacity-50 disabled:cursor-not-allowed hover:from-emerald-500 hover:to-teal-500 transition-all flex items-center justify-center gap-2"
                            >
                                {isLoading ? (
                                    <>
                                        <i className="fa-solid fa-spinner fa-spin"></i>
                                        Creating Account...
                                    </>
                                ) : (
                                    <>
                                        Complete Application
                                        <i className="fa-solid fa-arrow-right"></i>
                                    </>
                                )}
                            </button>
                        </div>
                    )}

                    {/* Step 5: Success */}
                    {step === 5 && (
                        <div className="text-center animate-in fade-in zoom-in duration-700">
                            <div className="w-24 h-24 mx-auto bg-gradient-to-br from-emerald-500 to-teal-600 rounded-full flex items-center justify-center text-white text-4xl mb-8 shadow-lg shadow-emerald-500/30 animate-bounce">
                                <i className="fa-solid fa-check"></i>
                            </div>

                            <h2 className="text-3xl font-black text-white mb-4">Welcome to Zenith!</h2>
                            <p className="text-white/60 text-lg mb-8">
                                Your account is ready. Preparing your personalized dashboard...
                            </p>

                            <div className="flex items-center justify-center gap-2 text-emerald-400">
                                <i className="fa-solid fa-spinner fa-spin"></i>
                                <span className="font-bold">Loading your experience</span>
                            </div>
                        </div>
                    )}

                </div>

                {/* Footer */}
                {step < 5 && (
                    <p className="text-center text-white/30 text-xs mt-8">
                        By continuing, you agree to our Terms of Service and Privacy Policy
                    </p>
                )}
            </div>
        </div>
    );
};

export default SignupFlow;
