import React, { useState, useEffect } from 'react';
import { User } from '../../types';

interface QuizFunnelProps {
    onComplete?: (result: QuizResult, email: string) => void;
    onLogin?: (user: User) => void;
}

interface QuizResult {
    profile: 'hormonal_warrior' | 'energy_reclaimer' | 'cycle_syncer' | 'metabolic_reset';
    score: number;
    symptoms: string[];
    recommendation: string;
}

interface QuizQuestion {
    id: number;
    question: string;
    type: 'single' | 'multi';
    options: { value: string; label: string; emoji?: string }[];
    category: string;
}

const questions: QuizQuestion[] = [
    {
        id: 1,
        question: "What's your #1 health goal right now?",
        type: 'single',
        category: 'goal',
        options: [
            { value: 'balance_hormones', label: 'Balance my hormones', emoji: '⚖️' },
            { value: 'more_energy', label: 'Have more energy', emoji: '⚡' },
            { value: 'manage_cycle', label: 'Better manage my cycle', emoji: '🌙' },
            { value: 'lose_weight', label: 'Lose stubborn weight', emoji: '🔥' },
        ],
    },
    {
        id: 2,
        question: "What's your age range?",
        type: 'single',
        category: 'demographic',
        options: [
            { value: '18-24', label: '18 - 24', emoji: '🌱' },
            { value: '25-34', label: '25 - 34', emoji: '🌿' },
            { value: '35-44', label: '35 - 44', emoji: '🌳' },
            { value: '45+', label: '45+', emoji: '🌻' },
        ],
    },
    {
        id: 3,
        question: "Which symptoms do you experience? (Select all)",
        type: 'multi',
        category: 'symptoms',
        options: [
            { value: 'fatigue', label: 'Constant fatigue', emoji: '😴' },
            { value: 'bloating', label: 'Bloating', emoji: '🎈' },
            { value: 'mood_swings', label: 'Mood swings', emoji: '🎭' },
            { value: 'acne', label: 'Hormonal acne', emoji: '😣' },
            { value: 'weight_gain', label: 'Unexplained weight gain', emoji: '⚖️' },
            { value: 'brain_fog', label: 'Brain fog', emoji: '🌫️' },
        ],
    },
    {
        id: 4,
        question: "How would you describe your energy levels?",
        type: 'single',
        category: 'energy',
        options: [
            { value: 'exhausted', label: "I'm running on empty", emoji: '🔋' },
            { value: 'low', label: 'Low, I need coffee to function', emoji: '☕' },
            { value: 'moderate', label: 'Okay, but crashes often', emoji: '📉' },
            { value: 'good', label: 'Pretty good most days', emoji: '✨' },
        ],
    },
    {
        id: 5,
        question: "How's your sleep quality?",
        type: 'single',
        category: 'sleep',
        options: [
            { value: 'terrible', label: "Can't fall or stay asleep", emoji: '😵' },
            { value: 'poor', label: 'Wake up tired', emoji: '😫' },
            { value: 'okay', label: 'Decent but not refreshing', emoji: '😐' },
            { value: 'great', label: 'I sleep like a baby', emoji: '😴' },
        ],
    },
    {
        id: 6,
        question: "Do you experience any cycle-related issues?",
        type: 'multi',
        category: 'cycle',
        options: [
            { value: 'irregular', label: 'Irregular periods', emoji: '📅' },
            { value: 'painful', label: 'Painful cramps', emoji: '😖' },
            { value: 'heavy', label: 'Heavy bleeding', emoji: '💧' },
            { value: 'pms', label: 'Severe PMS', emoji: '😤' },
            { value: 'pcos', label: 'PCOS symptoms', emoji: '🔬' },
            { value: 'none', label: 'None of these', emoji: '✅' },
        ],
    },
    {
        id: 7,
        question: "How stressed are you on a daily basis?",
        type: 'single',
        category: 'stress',
        options: [
            { value: 'overwhelmed', label: "I'm at my breaking point", emoji: '🌋' },
            { value: 'high', label: 'Very stressed', emoji: '😰' },
            { value: 'moderate', label: 'Manageable stress', emoji: '😓' },
            { value: 'low', label: 'Pretty zen', emoji: '🧘' },
        ],
    },
    {
        id: 8,
        question: "What best describes your eating habits?",
        type: 'single',
        category: 'diet',
        options: [
            { value: 'chaotic', label: 'Chaotic, I eat whatever', emoji: '🍕' },
            { value: 'skipping', label: 'I often skip meals', emoji: '⏭️' },
            { value: 'trying', label: 'Trying but struggling', emoji: '🥗' },
            { value: 'balanced', label: 'Pretty balanced', emoji: '🥙' },
        ],
    },
    {
        id: 9,
        question: "How often do you exercise?",
        type: 'single',
        category: 'exercise',
        options: [
            { value: 'never', label: "I don't currently", emoji: '🛋️' },
            { value: 'rarely', label: '1-2 times/month', emoji: '🚶' },
            { value: 'sometimes', label: '1-2 times/week', emoji: '🏃' },
            { value: 'often', label: '3+ times/week', emoji: '💪' },
        ],
    },
    {
        id: 10,
        question: "What have you tried before?",
        type: 'single',
        category: 'history',
        options: [
            { value: 'nothing', label: 'This is my first try', emoji: '🌟' },
            { value: 'diets', label: 'Various diets', emoji: '📋' },
            { value: 'supplements', label: 'Supplements/medication', emoji: '💊' },
            { value: 'everything', label: "Everything, nothing works", emoji: '😩' },
        ],
    },
];

const resultProfiles = {
    hormonal_warrior: {
        title: 'The Hormonal Warrior',
        emoji: '⚔️',
        color: 'from-purple-600 to-pink-600',
        description: 'Your symptoms point to hormonal imbalances that need targeted attention. PCOS, estrogen dominance, or thyroid issues may be at play.',
        recommendation: 'The 21-Day Hormone Reset Protocol',
        stats: '87% of women with your profile saw improvements in 3 weeks',
    },
    energy_reclaimer: {
        title: 'The Energy Reclaimer',
        emoji: '⚡',
        color: 'from-amber-500 to-orange-600',
        description: 'Your adrenals are crying for help! Chronic stress and poor sleep have depleted your energy reserves.',
        recommendation: 'The Adrenal Recovery Program',
        stats: '92% reported better energy within 14 days',
    },
    cycle_syncer: {
        title: 'The Cycle Syncer',
        emoji: '🌙',
        color: 'from-indigo-600 to-blue-600',
        description: 'Your body is asking you to work WITH your cycle, not against it. Cycle syncing can transform your experience.',
        recommendation: 'The Cycle Syncing Mastery Program',
        stats: '78% reduced PMS symptoms in one cycle',
    },
    metabolic_reset: {
        title: 'The Metabolic Reset',
        emoji: '🔥',
        color: 'from-red-500 to-rose-600',
        description: 'Your metabolism needs a strategic reset. Weight resistance often stems from hormone-metabolism connections.',
        recommendation: 'The 21-Day Metabolic Reset',
        stats: '84% broke through their weight plateau',
    },
};

const QuizFunnel: React.FC<QuizFunnelProps> = ({ onComplete, onLogin }) => {
    const [currentStep, setCurrentStep] = useState(0); // 0 = landing, 1-10 = questions, 11 = results, 12 = email capture
    const [answers, setAnswers] = useState<Record<number, string | string[]>>({});
    const [result, setResult] = useState<QuizResult | null>(null);
    const [email, setEmail] = useState('');
    const [name, setName] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [showExitPopup, setShowExitPopup] = useState(false);

    const totalQuestions = questions.length;
    const progress = currentStep === 0 ? 0 : Math.min((currentStep / totalQuestions) * 100, 100);

    // Exit intent detection
    useEffect(() => {
        const handleMouseLeave = (e: MouseEvent) => {
            if (e.clientY <= 0 && currentStep > 0 && currentStep <= totalQuestions) {
                setShowExitPopup(true);
            }
        };
        document.addEventListener('mouseleave', handleMouseLeave);
        return () => document.removeEventListener('mouseleave', handleMouseLeave);
    }, [currentStep, totalQuestions]);

    const calculateResult = (): QuizResult => {
        let scores = {
            hormonal_warrior: 0,
            energy_reclaimer: 0,
            cycle_syncer: 0,
            metabolic_reset: 0,
        };

        // Goal scoring
        const goal = answers[1] as string;
        if (goal === 'balance_hormones') scores.hormonal_warrior += 3;
        if (goal === 'more_energy') scores.energy_reclaimer += 3;
        if (goal === 'manage_cycle') scores.cycle_syncer += 3;
        if (goal === 'lose_weight') scores.metabolic_reset += 3;

        // Symptoms scoring
        const symptoms = (answers[3] as string[]) || [];
        if (symptoms.includes('fatigue')) scores.energy_reclaimer += 2;
        if (symptoms.includes('mood_swings')) scores.hormonal_warrior += 2;
        if (symptoms.includes('acne')) scores.hormonal_warrior += 2;
        if (symptoms.includes('weight_gain')) scores.metabolic_reset += 2;
        if (symptoms.includes('bloating')) scores.cycle_syncer += 1;
        if (symptoms.includes('brain_fog')) scores.energy_reclaimer += 1;

        // Energy scoring
        const energy = answers[4] as string;
        if (energy === 'exhausted' || energy === 'low') scores.energy_reclaimer += 2;

        // Cycle scoring
        const cycleIssues = (answers[6] as string[]) || [];
        if (cycleIssues.includes('pcos')) scores.hormonal_warrior += 3;
        if (cycleIssues.includes('irregular') || cycleIssues.includes('pms')) scores.cycle_syncer += 2;

        // Stress scoring
        const stress = answers[7] as string;
        if (stress === 'overwhelmed' || stress === 'high') scores.energy_reclaimer += 2;

        // Find highest score
        const maxProfile = Object.entries(scores).reduce((a, b) => (a[1] > b[1] ? a : b))[0] as keyof typeof scores;

        return {
            profile: maxProfile,
            score: scores[maxProfile],
            symptoms: symptoms,
            recommendation: resultProfiles[maxProfile].recommendation,
        };
    };

    const handleAnswer = (questionId: number, value: string) => {
        const question = questions.find(q => q.id === questionId);
        if (!question) return;

        if (question.type === 'multi') {
            const current = (answers[questionId] as string[]) || [];
            if (value === 'none') {
                setAnswers({ ...answers, [questionId]: ['none'] });
            } else {
                const filtered = current.filter(v => v !== 'none');
                if (current.includes(value)) {
                    setAnswers({ ...answers, [questionId]: filtered.filter(v => v !== value) });
                } else {
                    setAnswers({ ...answers, [questionId]: [...filtered, value] });
                }
            }
        } else {
            setAnswers({ ...answers, [questionId]: value });
            // Auto-advance for single choice
            setTimeout(() => {
                if (currentStep < totalQuestions) {
                    setCurrentStep(currentStep + 1);
                } else {
                    const quizResult = calculateResult();
                    setResult(quizResult);
                    setCurrentStep(11);
                }
            }, 300);
        }
    };

    const handleMultiNext = () => {
        if (currentStep < totalQuestions) {
            setCurrentStep(currentStep + 1);
        } else {
            const quizResult = calculateResult();
            setResult(quizResult);
            setCurrentStep(11);
        }
    };

    const handleEmailSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        try {
            // Save lead to backend
            const response = await fetch('/api/leads', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email,
                    name,
                    source: 'quiz_funnel',
                    quiz_result: result?.profile,
                    quiz_answers: answers,
                }),
            });

            if (response.ok) {
                onComplete?.(result!, email);
                setCurrentStep(13); // Success state
            }
        } catch {
            // Still proceed even if API fails
            onComplete?.(result!, email);
            setCurrentStep(13);
        } finally {
            setIsSubmitting(false);
        }
    };

    // Landing page
    if (currentStep === 0) {
        return (
            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 text-white relative overflow-hidden">
                {/* Animated background */}
                <div className="absolute inset-0 overflow-hidden pointer-events-none">
                    <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-purple-500/20 rounded-full blur-3xl animate-pulse" />
                    <div className="absolute bottom-1/4 right-1/4 w-96 h-96 bg-pink-500/20 rounded-full blur-3xl animate-pulse delay-1000" />
                </div>

                <div className="relative z-10 container mx-auto px-6 py-12 min-h-screen flex flex-col justify-center">
                    <div className="max-w-2xl mx-auto text-center">
                        {/* Badge */}
                        <div className="inline-flex items-center gap-2 px-4 py-2 bg-white/10 backdrop-blur-sm rounded-full text-sm font-medium mb-8 animate-bounce">
                            <span className="w-2 h-2 bg-green-400 rounded-full animate-pulse" />
                            2,847 women took this quiz today
                        </div>

                        {/* Headline */}
                        <h1 className="text-4xl md:text-6xl font-black mb-6 leading-tight">
                            What's <span className="text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-400">Stealing</span> Your Energy?
                        </h1>
                        <p className="text-lg md:text-xl text-purple-200/80 mb-10 max-w-lg mx-auto">
                            Take this 2-minute quiz to discover your hormone profile and get a personalized protocol to feel like yourself again.
                        </p>

                        {/* CTA Button */}
                        <button
                            onClick={() => setCurrentStep(1)}
                            className="group relative inline-flex items-center gap-3 px-10 py-5 bg-gradient-to-r from-purple-600 to-pink-600 rounded-2xl text-xl font-bold shadow-2xl shadow-purple-500/30 hover:shadow-purple-500/50 transform hover:-translate-y-1 transition-all duration-300"
                        >
                            Take The Free Quiz
                            <svg className="w-6 h-6 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </button>

                        <p className="mt-6 text-sm text-purple-300/60">
                            ✓ Takes 2 minutes • ✓ 100% Free • ✓ Instant Results
                        </p>

                        {/* Trust badges */}
                        <div className="mt-16 flex flex-wrap justify-center gap-8 opacity-60">
                            <div className="text-center">
                                <div className="text-3xl font-black">15,000+</div>
                                <div className="text-xs uppercase tracking-wider">Women helped</div>
                            </div>
                            <div className="text-center">
                                <div className="text-3xl font-black">4.9/5</div>
                                <div className="text-xs uppercase tracking-wider">Average rating</div>
                            </div>
                            <div className="text-center">
                                <div className="text-3xl font-black">87%</div>
                                <div className="text-xs uppercase tracking-wider">See results in 21 days</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    // Quiz questions (steps 1-10)
    if (currentStep >= 1 && currentStep <= totalQuestions) {
        const question = questions[currentStep - 1];
        const currentAnswer = answers[question.id];
        const isMulti = question.type === 'multi';
        const selectedValues = isMulti ? (currentAnswer as string[]) || [] : [];

        return (
            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 text-white">
                {/* Progress bar */}
                <div className="fixed top-0 left-0 right-0 z-50 bg-black/30 backdrop-blur-sm">
                    <div className="h-1 bg-white/10">
                        <div
                            className="h-full bg-gradient-to-r from-purple-500 to-pink-500 transition-all duration-500"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                    <div className="container mx-auto px-6 py-3 flex justify-between items-center text-sm">
                        <button
                            onClick={() => setCurrentStep(Math.max(0, currentStep - 1))}
                            className="flex items-center gap-2 text-purple-300 hover:text-white transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                            </svg>
                            Back
                        </button>
                        <span className="text-purple-300">
                            Question {currentStep} of {totalQuestions}
                        </span>
                    </div>
                </div>

                <div className="container mx-auto px-6 pt-28 pb-12 min-h-screen flex flex-col justify-center">
                    <div className="max-w-xl mx-auto w-full">
                        {/* Question */}
                        <div className="text-center mb-10 animate-in fade-in slide-in-from-bottom-4 duration-500">
                            <h2 className="text-2xl md:text-3xl font-bold mb-2">{question.question}</h2>
                            {isMulti && (
                                <p className="text-purple-300/70 text-sm">Select all that apply</p>
                            )}
                        </div>

                        {/* Options */}
                        <div className="space-y-3">
                            {question.options.map((option, index) => {
                                const isSelected = isMulti
                                    ? selectedValues.includes(option.value)
                                    : currentAnswer === option.value;

                                return (
                                    <button
                                        key={option.value}
                                        onClick={() => handleAnswer(question.id, option.value)}
                                        className={`w-full p-5 rounded-2xl text-left transition-all duration-300 transform hover:scale-[1.02] animate-in fade-in slide-in-from-bottom-4 ${isSelected
                                            ? 'bg-gradient-to-r from-purple-600 to-pink-600 shadow-lg shadow-purple-500/30'
                                            : 'bg-white/5 hover:bg-white/10 border border-white/10'
                                            }`}
                                        style={{ animationDelay: `${index * 50}ms` }}
                                    >
                                        <div className="flex items-center gap-4">
                                            <span className="text-2xl">{option.emoji}</span>
                                            <span className="font-medium text-lg">{option.label}</span>
                                            {isSelected && (
                                                <svg className="w-6 h-6 ml-auto text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                </svg>
                                            )}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Multi-select next button */}
                        {isMulti && selectedValues.length > 0 && (
                            <button
                                onClick={handleMultiNext}
                                className="mt-8 w-full py-4 bg-gradient-to-r from-purple-600 to-pink-600 rounded-xl font-bold text-lg hover:shadow-lg hover:shadow-purple-500/30 transition-all"
                            >
                                Continue →
                            </button>
                        )}
                    </div>
                </div>

                {/* Exit intent popup */}
                {showExitPopup && (
                    <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-6">
                        <div className="bg-gradient-to-br from-purple-900 to-slate-900 p-8 rounded-3xl max-w-md border border-purple-500/30 animate-in zoom-in duration-300">
                            <div className="text-center">
                                <div className="text-5xl mb-4">⏰</div>
                                <h3 className="text-2xl font-bold mb-2">Wait! Don't leave yet</h3>
                                <p className="text-purple-200/80 mb-6">
                                    You're just {totalQuestions - currentStep + 1} questions away from discovering your hormone profile!
                                </p>
                                <button
                                    onClick={() => setShowExitPopup(false)}
                                    className="w-full py-4 bg-gradient-to-r from-purple-600 to-pink-600 rounded-xl font-bold"
                                >
                                    Continue Quiz
                                </button>
                                <button
                                    onClick={() => setCurrentStep(0)}
                                    className="mt-3 text-sm text-purple-300/60 hover:text-white"
                                >
                                    No thanks, I'll leave
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        );
    }

    // Results page (step 11)
    if (currentStep === 11 && result) {
        const profile = resultProfiles[result.profile];

        return (
            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 text-white">
                <div className="container mx-auto px-6 py-12 min-h-screen flex flex-col justify-center">
                    <div className="max-w-2xl mx-auto text-center">
                        {/* Result reveal */}
                        <div className="animate-in zoom-in duration-700">
                            <div className="text-6xl mb-6">{profile.emoji}</div>
                            <div className="inline-block px-4 py-1 bg-white/10 rounded-full text-sm font-medium mb-4">
                                Your Profile
                            </div>
                            <h1 className={`text-4xl md:text-5xl font-black mb-4 text-transparent bg-clip-text bg-gradient-to-r ${profile.color}`}>
                                {profile.title}
                            </h1>
                            <p className="text-lg text-purple-200/80 mb-8 max-w-md mx-auto">
                                {profile.description}
                            </p>
                        </div>

                        {/* Stats */}
                        <div className="bg-white/5 backdrop-blur-sm rounded-2xl p-6 mb-8 border border-white/10 animate-in slide-in-from-bottom duration-500 delay-300">
                            <div className="flex items-center justify-center gap-2 text-green-400 font-bold">
                                <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                </svg>
                                {profile.stats}
                            </div>
                        </div>

                        {/* Recommendation */}
                        <div className="bg-gradient-to-r from-purple-600/20 to-pink-600/20 rounded-2xl p-8 border border-purple-500/30 mb-8 animate-in slide-in-from-bottom duration-500 delay-500">
                            <h3 className="font-bold text-xl mb-2">Recommended For You:</h3>
                            <p className="text-2xl font-black text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-400">
                                {profile.recommendation}
                            </p>
                        </div>

                        {/* CTA */}
                        <button
                            onClick={() => setCurrentStep(12)}
                            className="inline-flex items-center gap-3 px-10 py-5 bg-gradient-to-r from-purple-600 to-pink-600 rounded-2xl text-xl font-bold shadow-2xl shadow-purple-500/30 hover:shadow-purple-500/50 transform hover:-translate-y-1 transition-all duration-300 animate-in slide-in-from-bottom duration-500 delay-700"
                        >
                            Get My Free Protocol
                            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </button>

                        <p className="mt-4 text-sm text-purple-300/60">
                            ⏰ Your personalized protocol expires in 24 hours
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    // Email capture (step 12)
    if (currentStep === 12 && result) {
        const profile = resultProfiles[result.profile];

        return (
            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 text-white">
                <div className="container mx-auto px-6 py-12 min-h-screen flex flex-col justify-center">
                    <div className="max-w-md mx-auto w-full">
                        <div className="text-center mb-8">
                            <div className="text-5xl mb-4">{profile.emoji}</div>
                            <h2 className="text-2xl font-bold mb-2">
                                Almost there, {profile.title}!
                            </h2>
                            <p className="text-purple-200/80">
                                Enter your details to unlock your personalized protocol.
                            </p>
                        </div>

                        <form onSubmit={handleEmailSubmit} className="space-y-4">
                            <div>
                                <input
                                    type="text"
                                    placeholder="Your first name"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    required
                                    className="w-full px-6 py-4 bg-white/10 border border-white/20 rounded-xl text-white placeholder-purple-300/50 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                />
                            </div>
                            <div>
                                <input
                                    type="email"
                                    placeholder="Your email address"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    required
                                    className="w-full px-6 py-4 bg-white/10 border border-white/20 rounded-xl text-white placeholder-purple-300/50 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                />
                            </div>
                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="w-full py-5 bg-gradient-to-r from-purple-600 to-pink-600 rounded-xl font-bold text-lg shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all disabled:opacity-50"
                            >
                                {isSubmitting ? 'Unlocking...' : 'Unlock My Protocol →'}
                            </button>
                        </form>

                        <div className="mt-8 flex items-center justify-center gap-4 text-xs text-purple-300/60">
                            <span>🔒 No spam, ever</span>
                            <span>•</span>
                            <span>Unsubscribe anytime</span>
                        </div>

                        {/* Urgency */}
                        <div className="mt-8 p-4 bg-red-500/10 border border-red-500/30 rounded-xl text-center animate-pulse">
                            <p className="text-red-300 text-sm font-medium">
                                ⏰ This personalized protocol expires in 23:47:12
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    // Success state (step 13)
    if (currentStep === 13) {
        return (
            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 text-white flex items-center justify-center">
                <div className="text-center max-w-md mx-auto px-6 animate-in zoom-in duration-500">
                    <div className="text-6xl mb-6">🎉</div>
                    <h2 className="text-3xl font-bold mb-4">Check Your Inbox!</h2>
                    <p className="text-purple-200/80 mb-8">
                        Your personalized {result?.recommendation} is on its way! Check your email (and spam folder) for instant access.
                    </p>
                    <div className="space-y-4">
                        <a
                            href="/dashboard"
                            className="block w-full py-4 bg-gradient-to-r from-purple-600 to-pink-600 rounded-xl font-bold"
                        >
                            Go to Dashboard
                        </a>
                        <a
                            href="https://wa.me/2348000000000"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="block w-full py-4 bg-green-600 rounded-xl font-bold"
                        >
                            💬 Join WhatsApp Community
                        </a>
                    </div>
                </div>
            </div>
        );
    }

    return null;
};

export default QuizFunnel;
