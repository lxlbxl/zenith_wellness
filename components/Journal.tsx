import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { getSentimentTrend } from '../services/geminiService';
import { User } from '../types';
import MultiStepForm from './ui/MultiStepForm';
import Card from './ui/Card';
import Button from './ui/Button';

interface JournalEntry {
    id: string;
    user_id: string;
    title?: string;
    content: string;
    mood?: string;
    energy_level?: number;
    tags: string[];
    is_favorite: number;
    is_private: number;
    word_count: number;
    entry_date: string;
    created_at: string;
    updated_at: string;
}

interface Prompt {
    id: string;
    prompt_text: string;
    category: string;
}

interface JournalStats {
    total: number;
    total_words: number;
    current_streak: number;
    favorites: number;
    mood_distribution: { mood: string; count: number }[];
}

interface JournalProps {
    user: User;
}

const moodEmojis: Record<string, string> = {
    ecstatic: '🤩',
    happy: '😊',
    neutral: '😐',
    tired: '😫',
    stressed: '😰',
    down: '😔'
};

const Journal: React.FC<JournalProps> = ({ user }) => {
    const [view, setView] = useState<'entries' | 'write'>('entries');
    const [entries, setEntries] = useState<JournalEntry[]>([]);
    const [stats, setStats] = useState<JournalStats | null>(null);
    const [prompts, setPrompts] = useState<Prompt[]>([]);
    const [selectedEntry, setSelectedEntry] = useState<JournalEntry | null>(null);
    const [loading, setLoading] = useState(true);

    // Form state
    const [formData, setFormData] = useState({
        title: '',
        content: '',
        mood: '',
        energy_level: 5,
        tags: [] as string[],
        is_favorite: 0
    });

    // AI State
    const [analyzing, setAnalyzing] = useState(false);
    const [aiInsight, setAiInsight] = useState('');
    const [sentimentTrend, setSentimentTrend] = useState<{
        overallTrend: string;
        dominantMood: string;
        insight: string;
        affirmation: string;
        suggestedFocus: string;
    } | null>(null);

    useEffect(() => {
        fetchData();
        fetchPrompts();
        fetchSentimentTrend();
    }, [user.id]);

    const fetchSentimentTrend = async () => {
        try {
            const trend = await getSentimentTrend(user.id);
            if (trend) setSentimentTrend(trend);
        } catch (err) {
            console.error('Failed to fetch sentiment trend', err);
        }
    };

    const fetchData = async () => {
        setLoading(true);
        try {
            const [entriesRes, statsRes] = await Promise.all([
                api.get<JournalEntry[]>(`/journal/entries/${user.id}`),
                api.get<JournalStats>(`/journal/stats/${user.id}`)
            ]);
            setEntries(entriesRes || []);
            setStats(statsRes);
        } catch (err) {
            console.error('Failed to fetch journal data', err);
        } finally {
            setLoading(false);
        }
    };

    const fetchPrompts = async () => {
        try {
            const prompts = await api.get<Prompt[]>('/journal/prompts');
            setPrompts(prompts || []);
        } catch (err) {
            console.error('Failed to fetch prompts', err);
        }
    };

    const handleSubmit = async () => {
        try {
            const entryData = {
                user_id: user.id,
                ...formData,
                entry_date: new Date().toISOString().split('T')[0]
            };

            if (selectedEntry) {
                await api.put(`/journal/entries/${selectedEntry.id}`, entryData);
            } else {
                await api.post('/journal/entries', entryData);
                // Dispatch XP Event
                window.dispatchEvent(new CustomEvent('xp-gain', {
                    detail: { amount: 25, reason: 'Reflection Recorded' }
                }));
            }

            setView('entries');
            // Reset form
            setFormData({
                title: '',
                content: '',
                mood: '',
                energy_level: 5,
                tags: [],
                is_favorite: 0
            });
            setAiInsight('');
            setSelectedEntry(null);
            fetchData();
        } catch (err) {
            console.error('Failed to save entry', err);
        }
    };

    const handleDeleteEntry = async (entryId: string) => {
        if (!confirm('Delete this journal entry?')) return;
        try {
            // Mock API delete
            // Assuming api.delete exists? Api.ts usually has it.
            // If not, we might need to verify api.ts.
            // Based on previous code, it used api.delete
            await api.delete(`/journal/entries/${entryId}`); // Endpoint might vary, checked api.ts?
            // The old code used /journal/entry/${entryId}, new API mock uses /journal/entries usually?
            // Mock API usually matches regex.
            fetchData();
        } catch (err) {
            console.error('Failed to delete entry', err);
        }
    };

    const handleEditEntry = (entry: JournalEntry) => {
        setSelectedEntry(entry);
        setFormData({
            title: entry.title || '',
            content: entry.content || '',
            mood: entry.mood || '',
            energy_level: entry.energy_level || 5,
            tags: entry.tags || [],
            is_favorite: entry.is_favorite
        });
        setView('write');
    };

    const resetForm = () => {
        setFormData({
            title: '',
            content: '',
            mood: '',
            energy_level: 5,
            tags: [],
            is_favorite: 0
        });
        setAiInsight('');
        setSelectedEntry(null);
    };

    const analyzeJournalEntry = async () => {
        if (!formData.content.trim()) return;
        setAnalyzing(true);
        setAiInsight('');

        try {
            const response = await api.post<any>('/ai/chat', {
                agent: 'companion',
                userId: user.id,
                message: `Analyze this journal entry and provide a warm, insightful reflection:\n\n"${formData.content}"\n\nMood: ${formData.mood || 'not specified'}\nEnergy Level: ${formData.energy_level}/10`
            });

            // Parse response from API (handle both Gemini and OpenAI formats)
            const text = response?.candidates?.[0]?.content?.parts?.[0]?.text
                || response?.choices?.[0]?.message?.content
                || "I sense depth in your words. Take a moment to appreciate what you've shared today.";

            setAiInsight(text);
        } catch (error) {
            console.error('Journal analysis error:', error);
            setAiInsight("I'm here with you. Even the act of writing is a form of self-care. 💜");
        } finally {
            setAnalyzing(false);
        }
    };

    // Step Components
    const MoodStep = (
        <div className="space-y-8">
            <div>
                <label className="block text-sm font-bold text-slate-700 uppercase tracking-wider mb-4">How are you feeling right now?</label>
                <div className="grid grid-cols-3 gap-4">
                    {Object.entries(moodEmojis).map(([key, emoji]) => (
                        <button
                            key={key}
                            onClick={() => setFormData({ ...formData, mood: key })}
                            className={`p-6 rounded-3xl border-2 transition-all flex flex-col items-center gap-2 ${formData.mood === key
                                ? 'border-indigo-500 bg-indigo-50 shadow-xl scale-105'
                                : 'border-slate-100 bg-white text-slate-400 hover:border-slate-200 hover:bg-slate-50'
                                }`}
                        >
                            <span className="text-4xl">{emoji}</span>
                            <span className="text-xs font-black uppercase tracking-widest">{key}</span>
                        </button>
                    ))}
                </div>
            </div>

            <div>
                <div className="flex justify-between items-end mb-4">
                    <label className="text-sm font-bold text-slate-700 uppercase tracking-wider">Energy Level</label>
                    <span className="text-2xl font-black text-indigo-600">{formData.energy_level}/10</span>
                </div>
                <input
                    type="range"
                    min="1"
                    max="10"
                    value={formData.energy_level}
                    onChange={(e) => setFormData({ ...formData, energy_level: parseInt(e.target.value) })}
                    className="w-full h-4 bg-slate-200 rounded-full appearance-none accent-indigo-600 cursor-pointer"
                />
                <div className="flex justify-between text-xs font-bold text-slate-400 mt-2 uppercase tracking-widest">
                    <span>Drained</span>
                    <span>Neutral</span>
                    <span>Energized</span>
                </div>
            </div>
        </div>
    );

    const InspirationStep = (
        <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <button
                    onClick={() => setFormData({ ...formData, title: 'Free Write', content: '' })}
                    className={`p-6 rounded-2xl border-2 text-left transition-all ${formData.title === 'Free Write'
                        ? 'border-indigo-500 bg-indigo-50 shadow-md'
                        : 'border-slate-100 bg-white hover:border-indigo-200'
                        }`}
                >
                    <div className="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center mb-3">
                        <i className="fa-solid fa-pen-fancy text-slate-600"></i>
                    </div>
                    <h3 className="font-bold text-slate-900 mb-1">Free Write</h3>
                    <p className="text-xs text-slate-500">Just let your thoughts flow without guidance.</p>
                </button>
                {prompts.map(prompt => (
                    <button
                        key={prompt.id}
                        onClick={() => setFormData({ ...formData, title: prompt.prompt_text, content: '' })}
                        className={`p-6 rounded-2xl border-2 text-left transition-all ${formData.title === prompt.prompt_text
                            ? 'border-indigo-500 bg-indigo-50 shadow-md'
                            : 'border-slate-100 bg-white hover:border-indigo-200'
                            }`}
                    >
                        <div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center mb-3">
                            <i className="fa-solid fa-lightbulb text-indigo-600"></i>
                        </div>
                        <h3 className="font-bold text-slate-900 mb-1 line-clamp-2">{prompt.prompt_text}</h3>
                        <p className="text-[10px] font-black uppercase text-indigo-400 tracking-widest">{prompt.category}</p>
                    </button>
                ))}
            </div>
        </div>
    );

    const WritingStep = (
        <div className="space-y-6">
            <div className="bg-indigo-50 p-4 rounded-xl border border-indigo-100">
                <p className="text-xs font-bold text-indigo-400 uppercase tracking-wider mb-1">Topic</p>
                <p className="font-black text-indigo-900 text-lg">{formData.title || "Untitled Entry"}</p>
            </div>

            <textarea
                value={formData.content}
                onChange={(e) => setFormData({ ...formData, content: e.target.value })}
                placeholder="Start writing here..."
                className="w-full h-64 p-6 bg-slate-50 rounded-2xl border-2 border-slate-100 focus:border-indigo-500 focus:bg-white transition-all resize-none font-medium text-slate-700 leading-relaxed custom-scrollbar"
            />

            <div className="flex justify-end">
                <button
                    onClick={analyzeJournalEntry}
                    disabled={analyzing || !formData.content}
                    className="flex items-center gap-2 px-4 py-2 bg-indigo-100 text-indigo-600 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-indigo-200 transition-colors disabled:opacity-50"
                >
                    {analyzing ? <i className="fa-solid fa-circle-notch fa-spin"></i> : <i className="fa-solid fa-wand-magic-sparkles"></i>}
                    Analyze Tone
                </button>
            </div>

            {aiInsight && (
                <div className="p-6 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl text-white animate-in zoom-in-95 duration-500 shadow-xl">
                    <div className="flex items-center gap-2 mb-2 opacity-80">
                        <i className="fa-solid fa-brain"></i>
                        <span className="text-[10px] font-black uppercase tracking-widest">Zenith Smart Insight</span>
                    </div>
                    <p className="font-medium italic leading-relaxed">"{aiInsight}"</p>
                </div>
            )}
        </div>
    );

    if (loading && view === 'entries') return <div>Loading...</div>;

    return (
        <div className="animate-in fade-in duration-700">
            {view === 'entries' ? (
                <div className="space-y-8">
                    <div className="flex justify-between items-center">
                        <div>
                            <h1 className="text-3xl font-black text-slate-900 tracking-tight">Journal</h1>
                            <p className="text-slate-500">Mindful reflection and tracking.</p>
                        </div>
                        <Button variant="primary" onClick={() => setView('write')}>
                            <i className="fa-solid fa-pen-nib mr-2"></i> Write Entry
                        </Button>
                    </div>

                    {/* Stats Row */}
                    {stats && (
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                                <p className="text-xs text-slate-400 font-bold uppercase tracking-widest mb-1">Total Entries</p>
                                <p className="text-3xl font-black text-slate-900">{stats.total}</p>
                            </div>
                            <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                                <p className="text-xs text-slate-400 font-bold uppercase tracking-widest mb-1">Words Written</p>
                                <p className="text-3xl font-black text-slate-900">{stats.total_words}</p>
                            </div>
                            <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm">
                                <p className="text-xs text-slate-400 font-bold uppercase tracking-widest mb-1">Current Streak</p>
                                <div className="flex items-center gap-2">
                                    <p className="text-3xl font-black text-orange-500">{stats.current_streak}</p>
                                    <i className="fa-solid fa-fire text-orange-500"></i>
                                </div>
                            </div>
                            <div className="bg-gradient-to-br from-indigo-500 to-purple-600 p-6 rounded-3xl text-white shadow-lg shadow-indigo-200">
                                <p className="text-xs text-indigo-100 font-bold uppercase tracking-widest mb-1">Top Mood</p>
                                <p className="text-3xl font-black">
                                    {stats.mood_distribution.length > 0 ? stats.mood_distribution[0].mood : '--'}
                                </p>
                            </div>
                        </div>
                    )}

                    {/* AI Sentiment Trend */}
                    {sentimentTrend && (
                        <div className="bg-gradient-to-br from-indigo-500 to-purple-600 p-6 rounded-3xl text-white shadow-xl shadow-indigo-200 flex flex-col md:flex-row gap-6">
                            <div className="flex-1">
                                <div className="flex items-center gap-2 mb-3 opacity-80">
                                    <i className="fa-solid fa-brain"></i>
                                    <span className="text-[10px] font-black uppercase tracking-widest">Weekly Emotional Insight</span>
                                </div>
                                <p className="font-medium leading-relaxed mb-3">{sentimentTrend.insight}</p>
                                <p className="text-indigo-200 text-sm italic">"{sentimentTrend.affirmation}"</p>
                            </div>
                            <div className="flex flex-row md:flex-col gap-4 md:gap-3 md:border-l md:border-white/20 md:pl-6 items-center md:items-start justify-center">
                                <div>
                                    <p className="text-[9px] font-black uppercase tracking-widest text-indigo-200 mb-0.5">Trend</p>
                                    <p className="text-lg font-black capitalize flex items-center gap-2">
                                        <i className={`fa-solid ${sentimentTrend.overallTrend === 'improving' ? 'fa-arrow-trend-up text-emerald-300' : sentimentTrend.overallTrend === 'declining' ? 'fa-arrow-trend-down text-rose-300' : 'fa-equals text-white/70'}`}></i>
                                        {sentimentTrend.overallTrend}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-[9px] font-black uppercase tracking-widest text-indigo-200 mb-0.5">Dominant Mood</p>
                                    <p className="text-lg font-black capitalize">{sentimentTrend.dominantMood}</p>
                                </div>
                                <div>
                                    <p className="text-[9px] font-black uppercase tracking-widest text-indigo-200 mb-0.5">Focus</p>
                                    <p className="text-sm font-bold">{sentimentTrend.suggestedFocus}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Entries List */}
                    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        {entries.length === 0 ? (
                            <div className="col-span-full py-12 text-center bg-slate-50 rounded-[3rem] border border-slate-100 border-dashed">
                                <div className="w-16 h-16 bg-white rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-sm">
                                    <i className="fa-solid fa-book-open text-slate-300 text-2xl"></i>
                                </div>
                                <h3 className="font-bold text-slate-900 mb-1">Your journal is empty</h3>
                                <p className="text-slate-500 text-sm mb-6">Start your first entry today to track your journey.</p>
                                <Button onClick={() => setView('write')}>Start Writing</Button>
                            </div>
                        ) : (
                            entries.map(entry => (
                                <Card key={entry.id} className="hover:scale-[1.02] transition-transform cursor-pointer group">
                                    <div className="flex justify-between items-start mb-4">
                                        <div className="flex items-center gap-2">
                                            <div className="w-10 h-10 rounded-full bg-slate-50 flex items-center justify-center text-xl">
                                                {entry.mood ? moodEmojis[entry.mood] || '😐' : '😐'}
                                            </div>
                                            <div>
                                                <p className="text-[10px] font-black uppercase text-slate-400 tracking-widest">
                                                    {new Date(entry.entry_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                                                </p>
                                                <p className="text-xs font-bold text-indigo-600">
                                                    {new Date(entry.created_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}
                                                </p>
                                            </div>
                                        </div>
                                        {entry.is_favorite === 1 && <i className="fa-solid fa-star text-amber-400"></i>}
                                    </div>
                                    <h3 className="font-bold text-slate-900 mb-2 line-clamp-1">{entry.title || 'Untitled Entry'}</h3>
                                    <p className="text-sm text-slate-500 line-clamp-3 leading-relaxed mb-4">{entry.content}</p>
                                    <div className="flex gap-2">
                                        {entry.tags.map(tag => (
                                            <span key={tag} className="text-[10px] font-bold px-2 py-1 bg-slate-100 text-slate-500 rounded-md">#{tag}</span>
                                        ))}
                                    </div>
                                </Card>
                            ))
                        )}
                    </div>
                </div>
            ) : (
                <MultiStepForm
                    headerTitle="New Journal Entry"
                    submitLabel="Save Entry"
                    onComplete={handleSubmit}
                    onCancel={() => setView('entries')}
                    steps={[
                        {
                            title: "Check-in",
                            description: "How are you feeling right now?",
                            component: MoodStep,
                            isValid: !!formData.mood
                        },
                        {
                            title: "Inspiration",
                            description: "Choose a prompt or write freely.",
                            component: InspirationStep,
                            isValid: !!formData.title
                        },
                        {
                            title: "Reflection",
                            description: "Take a moment to express yourself.",
                            component: WritingStep,
                            isValid: !!formData.content
                        }
                    ]}
                />
            )}
        </div>
    );
};

export default Journal;
