
import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../../services/api';

interface AIModel {
    id: string;
    name: string;
    description?: string;
}

const PROVIDERS = [
    { value: 'gemini', label: 'Gemini' },
    { value: 'openai', label: 'OpenAI' },
    { value: 'openrouter', label: 'OpenRouter' }
];

const AdminContent: React.FC = () => {
    const [activeSubTab, setActiveSubTab] = useState<'cohorts' | 'prompts'>('cohorts');
    const [viewMode, setViewMode] = useState<'list' | 'edit_cohort' | 'challenges'>('list');
    const [cohorts, setCohorts] = useState<any[]>([]);
    const [prompts, setPrompts] = useState<any[]>([]);
    const [selectedCohort, setSelectedCohort] = useState<any>({ id: '', title: '', description: '', price: 8900, category: 'wellness', image_url: '', objectives: '' });
    const [challenges, setChallenges] = useState<any[]>([]);
    const [loading, setLoading] = useState(false);

    // Dynamic model loading per agent
    const [agentModels, setAgentModels] = useState<Record<string, AIModel[]>>({});
    const [loadingModels, setLoadingModels] = useState<Record<string, boolean>>({});

    // Test prompt state
    const [testingAgent, setTestingAgent] = useState<any>(null);
    const [testInput, setTestInput] = useState('');
    const [testResult, setTestResult] = useState('');
    const [testLoading, setTestLoading] = useState(false);

    // Fetch models for a specific provider
    const fetchModelsForProvider = useCallback(async (provider: string, promptId: string) => {
        setLoadingModels(prev => ({ ...prev, [promptId]: true }));
        try {
            const res = await api.get<{ success: boolean; models: AIModel[] }>(
                `/admin/settings/fetch-models?provider=${provider}`
            );
            if (res.success && res.models) {
                setAgentModels(prev => ({ ...prev, [promptId]: res.models }));
            }
        } catch (e) {
            console.error('Failed to fetch models:', e);
        } finally {
            setLoadingModels(prev => ({ ...prev, [promptId]: false }));
        }
    }, []);

    useEffect(() => {
        setLoading(true);
        if (activeSubTab === 'cohorts') {
            api.get<any[]>('/admin/cohorts').then(data => { setCohorts(data || []); setLoading(false); });
        } else {
            api.get<any[]>('/admin/prompts').then(data => {
                setPrompts(data || []);
                setLoading(false);
                // Load models for each agent's provider
                (data || []).forEach(p => {
                    const config = JSON.parse(p.model_config || '{}');
                    const provider = config.provider || 'gemini';
                    fetchModelsForProvider(provider, p.id);
                });
            });
        }
    }, [activeSubTab, fetchModelsForProvider]);

    const loadChallenges = (programId: string) => {
        api.get<any[]>(`/admin/challenges?programId=${programId}`).then(setChallenges);
    };

    const handleManageCohort = (cohort: any) => {
        setSelectedCohort(cohort);
        setViewMode('edit_cohort');
    };

    const handleViewChallenges = (cohort: any) => {
        setSelectedCohort(cohort);
        loadChallenges(cohort.id);
        setViewMode('challenges');
    };

    const handleSaveCohort = async (e: React.FormEvent) => {
        e.preventDefault();
        const form = e.target as HTMLFormElement;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);

        await api.post('/admin/cohorts', data);
        setViewMode('list');
        api.get<any[]>('/admin/cohorts').then(setCohorts);
    };

    const handleSavePrompt = async (p: any, newText: string, config?: any) => {
        await api.post('/admin/prompts', { ...p, systemPrompt: newText, modelConfig: config });
    };

    // Test prompt functionality
    const handleTestPrompt = async () => {
        if (!testingAgent || !testInput.trim()) return;
        setTestLoading(true);
        setTestResult('');
        try {
            const response = await api.post<any>('/ai/chat', {
                agent: testingAgent.agent_name,
                message: testInput,
                system: testingAgent.system_prompt
            });
            const text = response?.candidates?.[0]?.content?.parts?.[0]?.text
                || response?.choices?.[0]?.message?.content
                || JSON.stringify(response, null, 2);
            setTestResult(text);
        } catch (err: any) {
            setTestResult('Error: ' + (err.message || 'Failed to test prompt'));
        }
        setTestLoading(false);
    };

    const updatePromptConfig = (promptId: string, key: string, value: any) => {
        setPrompts(prev => prev.map(p => {
            if (p.id === promptId) {
                const config = JSON.parse(p.model_config || '{}');
                config[key] = value;
                return { ...p, model_config: JSON.stringify(config) };
            }
            return p;
        }));
    };

    // Handle provider change - fetch new models
    const handleProviderChange = (promptId: string, newProvider: string, prompt: any) => {
        const config = JSON.parse(prompt.model_config || '{}');
        config.provider = newProvider;
        config.model = ''; // Reset model when provider changes
        updatePromptConfig(promptId, 'provider', newProvider);
        fetchModelsForProvider(newProvider, promptId);
        handleSavePrompt(prompt, prompt.system_prompt, config);
    };

    if (loading) return <div className="p-10 text-center text-slate-400">Loading modules...</div>;

    return (
        <div className="space-y-6">
            <div className="flex gap-4 border-b border-slate-100 pb-2">
                <button
                    onClick={() => { setActiveSubTab('cohorts'); setViewMode('list'); }}
                    className={`text-sm font-bold pb-2 px-2 ${activeSubTab === 'cohorts' ? 'text-indigo-600 border-b-2 border-indigo-600' : 'text-slate-400'}`}
                >
                    Cohorts
                </button>
                <button
                    onClick={() => setActiveSubTab('prompts')}
                    className={`text-sm font-bold pb-2 px-2 ${activeSubTab === 'prompts' ? 'text-indigo-600 border-b-2 border-indigo-600' : 'text-slate-400'}`}
                >
                    AI Agents
                </button>
            </div>

            {activeSubTab === 'cohorts' && viewMode === 'list' && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {cohorts.map(program => (
                        <div key={program.id} className="bg-white p-4 rounded-3xl border border-slate-100 shadow-sm relative group">
                            <h4 className="font-bold">{program.title}</h4>
                            <p className="text-xs text-slate-500 line-clamp-2 mb-2">{program.description}</p>
                            <div className="flex justify-between items-center text-xs mb-3">
                                <span className="bg-slate-100 px-2 py-1 rounded text-slate-600 uppercase font-bold text-[10px]">{program.category}</span>
                                <span className="font-mono">${(program.price / 100).toFixed(2)}</span>
                            </div>
                            <div className="flex gap-2">
                                <button onClick={() => handleManageCohort(program)} className="flex-1 bg-slate-50 text-slate-600 py-2 rounded-xl text-xs font-bold hover:bg-indigo-50 hover:text-indigo-600">
                                    Edit Details
                                </button>
                                <button onClick={() => handleViewChallenges(program)} className="flex-1 bg-slate-900 text-white py-2 rounded-xl text-xs font-bold hover:bg-slate-800">
                                    Manage Challenges
                                </button>
                            </div>
                        </div>
                    ))}
                    <div
                        onClick={() => { setSelectedCohort({ id: '', title: '', description: '', price: 8900, category: 'wellness' }); setViewMode('edit_cohort'); }}
                        className="bg-slate-50 p-4 rounded-3xl border border-slate-200 border-dashed flex flex-col items-center justify-center text-slate-400 hover:bg-slate-100 cursor-pointer transition-colors min-h-[150px]"
                    >
                        <i className="fa-solid fa-plus text-2xl mb-2"></i>
                        <span className="text-sm font-bold">Create New Cohort</span>
                    </div>
                </div>
            )}

            {activeSubTab === 'cohorts' && viewMode === 'edit_cohort' && (
                <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm max-w-2xl">
                    <div className="flex justify-between items-center mb-6">
                        <h3 className="font-bold text-xl">{selectedCohort.id ? 'Edit Cohort' : 'New Cohort'}</h3>
                        <button onClick={() => setViewMode('list')} className="text-slate-400 hover:text-slate-600"><i className="fa-solid fa-xmark"></i></button>
                    </div>
                    <form onSubmit={handleSaveCohort} className="space-y-4">
                        <input type="hidden" name="id" value={selectedCohort.id} />
                        <div>
                            <label className="text-xs font-bold text-slate-400 uppercase">Title</label>
                            <input name="title" defaultValue={selectedCohort.title} className="w-full bg-slate-50 p-3 rounded-xl font-bold border-slate-200" required />
                        </div>
                        <div>
                            <label className="text-xs font-bold text-slate-400 uppercase">Description</label>
                            <textarea name="description" defaultValue={selectedCohort.description} className="w-full bg-slate-50 p-3 rounded-xl border-slate-200 h-24" />
                        </div>
                        <div>
                            <label className="text-xs font-bold text-slate-400 uppercase">Cover Image URL</label>
                            <input name="image_url" defaultValue={selectedCohort.image_url} placeholder="https://example.com/image.jpg" className="w-full bg-slate-50 p-3 rounded-xl border-slate-200" />
                        </div>
                        <div>
                            <label className="text-xs font-bold text-slate-400 uppercase">Objectives (one per line)</label>
                            <textarea name="objectives" defaultValue={selectedCohort.objectives} placeholder="Build healthy habits&#10;Improve sleep quality&#10;Reduce stress" className="w-full bg-slate-50 p-3 rounded-xl border-slate-200 h-20 text-sm" />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="text-xs font-bold text-slate-400 uppercase">Start Date</label>
                                <input type="date" name="startDate" defaultValue={selectedCohort.start_date} className="w-full bg-slate-50 p-3 rounded-xl border-slate-200" required />
                            </div>
                            <div>
                                <label className="text-xs font-bold text-slate-400 uppercase">Duration (Days)</label>
                                <input type="number" name="duration" defaultValue={selectedCohort.duration_days ?? 21} className="w-full bg-slate-50 p-3 rounded-xl border-slate-200" />
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="text-xs font-bold text-slate-400 uppercase">Category</label>
                                <select name="category" defaultValue={selectedCohort.category} className="w-full bg-slate-50 p-3 rounded-xl border-slate-200">
                                    <option value="wellness">Wellness</option>
                                    <option value="productivity">Productivity</option>
                                    <option value="fitness">Fitness</option>
                                </select>
                            </div>
                            <div>
                                <label className="text-xs font-bold text-slate-400 uppercase">Price (Cents)</label>
                                <input type="number" name="price" defaultValue={selectedCohort.price ?? 8900} className="w-full bg-slate-50 p-3 rounded-xl border-slate-200" />
                            </div>
                        </div>
                        <button type="submit" className="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold shadow-lg shadow-indigo-200 mt-4">Save Cohort</button>
                    </form>
                </div>
            )}

            {activeSubTab === 'cohorts' && viewMode === 'challenges' && (
                <div className="space-y-4">
                    <div className="flex items-center gap-4 mb-4">
                        <button onClick={() => setViewMode('list')} className="bg-slate-100 p-2 rounded-lg text-slate-500 hover:bg-slate-200"><i className="fa-solid fa-arrow-left"></i></button>
                        <div>
                            <h3 className="font-bold text-lg">{selectedCohort.title} <span className="text-slate-400 font-normal">/ Challenges</span></h3>
                            <p className="text-xs text-slate-400">Manage daily content and challenges for this cohort.</p>
                        </div>
                    </div>

                    <div className="bg-indigo-50 p-4 rounded-2xl border border-indigo-100">
                        <h4 className="text-xs font-bold text-indigo-800 uppercase mb-2">Add New Challenge</h4>
                        <form onSubmit={async (e) => {
                            e.preventDefault();
                            const form = e.target as HTMLFormElement;
                            const fd = new FormData(form);
                            await api.post('/admin/challenges', {
                                programId: selectedCohort.id,
                                title: fd.get('title'),
                                description: fd.get('description'),
                                duration: fd.get('duration')
                            });
                            form.reset();
                            loadChallenges(selectedCohort.id);
                        }} className="flex gap-2">
                            <input name="title" placeholder="Challenge Title" className="flex-1 px-3 py-2 rounded-xl text-sm border-transparent focus:ring-2 focus:ring-indigo-200" required />
                            <input name="duration" type="number" placeholder="Mins" className="w-20 px-3 py-2 rounded-xl text-sm" />
                            <button type="submit" className="bg-indigo-600 text-white px-4 py-2 rounded-xl font-bold text-sm"><i className="fa-solid fa-plus"></i></button>
                        </form>
                    </div>

                    <div className="space-y-2">
                        {challenges.map(ch => (
                            <div key={ch.id} className="bg-white p-4 rounded-2xl border border-slate-100 flex justify-between items-center">
                                <div>
                                    <h5 className="font-bold text-slate-800">{ch.title}</h5>
                                    <p className="text-xs text-slate-500">{ch.duration_minutes} mins • {ch.type}</p>
                                </div>
                                <button onClick={async () => {
                                    if (confirm('Delete challenge?')) {
                                        await api.delete(`/admin/challenges?id=${ch.id}`);
                                        loadChallenges(selectedCohort.id);
                                    }
                                }} className="text-rose-400 hover:text-rose-600"><i className="fa-solid fa-trash"></i></button>
                            </div>
                        ))}
                        {challenges.length === 0 && <div className="text-center text-slate-400 py-8">No challenges added yet.</div>}
                    </div>
                </div>
            )}

            {activeSubTab === 'prompts' && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 space-y-6">
                        {prompts.map(prompt => {
                            const config = JSON.parse(prompt.model_config || '{}');
                            const currentProvider = config.provider || 'gemini';
                            const currentModels = agentModels[prompt.id] || [];
                            const isLoadingModels = loadingModels[prompt.id] || false;

                            return (
                                <div key={prompt.id} className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm relative overflow-hidden group">
                                    <div className="absolute top-0 right-0 p-4 opacity-50 text-6xl text-slate-100 -z-0">
                                        <i className="fa-brands fa-android"></i>
                                    </div>
                                    <div className="relative z-10">
                                        <div className="flex justify-between items-start mb-4">
                                            <div>
                                                <h4 className="font-bold text-lg text-slate-800 capitalize">{(prompt.agent_name || 'Unnamed Agent').replace(/_/g, ' ')}</h4>
                                                <span className="text-xs font-mono text-indigo-500 bg-indigo-50 px-2 py-1 rounded">{prompt.id}</span>
                                            </div>
                                            <button
                                                onClick={() => { setTestingAgent(prompt); setTestInput(''); setTestResult(''); }}
                                                className="bg-emerald-50 text-emerald-600 px-3 py-1 rounded-lg text-xs font-bold hover:bg-emerald-100"
                                            >
                                                <i className="fa-solid fa-play mr-1"></i> Test
                                            </button>
                                        </div>

                                        {/* Provider & Model Selection */}
                                        <div className="grid grid-cols-2 gap-3 mb-4">
                                            <div>
                                                <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">AI Provider</label>
                                                <select
                                                    className="w-full bg-slate-50 p-2 rounded-lg text-sm border-slate-200 font-bold uppercase"
                                                    value={currentProvider}
                                                    onChange={(e) => handleProviderChange(prompt.id, e.target.value, prompt)}
                                                >
                                                    {PROVIDERS.map(p => (
                                                        <option key={p.value} value={p.value}>{p.label}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="relative">
                                                <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">Model</label>
                                                <select
                                                    className="w-full bg-slate-50 p-2 rounded-lg text-sm border-slate-200 disabled:opacity-50"
                                                    value={config.model || ''}
                                                    disabled={isLoadingModels}
                                                    onChange={(e) => {
                                                        updatePromptConfig(prompt.id, 'model', e.target.value);
                                                        handleSavePrompt(prompt, prompt.system_prompt, { ...config, model: e.target.value });
                                                    }}
                                                >
                                                    {isLoadingModels ? (
                                                        <option>Loading models...</option>
                                                    ) : currentModels.length > 0 ? (
                                                        <>
                                                            <option value="">Select a model...</option>
                                                            {currentModels.map(m => (
                                                                <option key={m.id} value={m.id}>{m.name}</option>
                                                            ))}
                                                        </>
                                                    ) : (
                                                        <option value={config.model || ''}>{config.model || 'No models available'}</option>
                                                    )}
                                                </select>
                                                {isLoadingModels && (
                                                    <div className="absolute right-3 top-8">
                                                        <i className="fa-solid fa-spinner fa-spin text-indigo-500"></i>
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-2 gap-3 mb-4">
                                            <div>
                                                <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">
                                                    Temperature: {config.temperature ?? 0.7}
                                                </label>
                                                <input
                                                    type="range"
                                                    min="0" max="1" step="0.1"
                                                    className="w-full"
                                                    value={config.temperature ?? 0.7}
                                                    onChange={(e) => {
                                                        const temp = parseFloat(e.target.value);
                                                        updatePromptConfig(prompt.id, 'temperature', temp);
                                                    }}
                                                    onMouseUp={(e) => {
                                                        const temp = parseFloat((e.target as HTMLInputElement).value);
                                                        handleSavePrompt(prompt, prompt.system_prompt, { ...config, temperature: temp });
                                                    }}
                                                />
                                            </div>
                                            <div>
                                                <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">Max Tokens</label>
                                                <input
                                                    type="number"
                                                    className="w-full bg-slate-50 p-2 rounded-lg text-sm border-slate-200"
                                                    value={config.maxTokens || 1024}
                                                    onChange={(e) => {
                                                        const tokens = parseInt(e.target.value);
                                                        updatePromptConfig(prompt.id, 'maxTokens', tokens);
                                                    }}
                                                    onBlur={(e) => {
                                                        const tokens = parseInt(e.target.value);
                                                        handleSavePrompt(prompt, prompt.system_prompt, { ...config, maxTokens: tokens });
                                                    }}
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">System Instructions</label>
                                            <textarea
                                                className="w-full h-48 bg-slate-50 border-slate-200 rounded-xl p-4 text-xs font-mono text-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none leading-relaxed resize-y"
                                                defaultValue={prompt.system_prompt}
                                                onBlur={(e) => handleSavePrompt(prompt, e.target.value, config)}
                                                placeholder="You are a helpful AI assistant..."
                                            />
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                        {prompts.length === 0 && (
                            <div className="text-center py-12 text-slate-400">
                                <i className="fa-solid fa-robot text-4xl mb-3"></i>
                                <p>No AI agents configured. Run database setup to seed defaults.</p>
                            </div>
                        )}
                    </div>

                    <div className="space-y-6">
                        <div className="bg-indigo-900 text-white p-6 rounded-3xl shadow-lg relative overflow-hidden">
                            <div className="relative z-10">
                                <h3 className="font-bold text-lg mb-2">AI Agent Studio</h3>
                                <p className="text-xs text-indigo-200 mb-4">Configure each agent's AI provider, model, and behavior.</p>
                                <div className="text-xs space-y-2 text-indigo-200">
                                    <p><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Select provider per agent</p>
                                    <p><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Dynamic model selection</p>
                                    <p><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Configure temperature & tokens</p>
                                    <p><i className="fa-solid fa-check text-emerald-400 mr-2"></i>Test prompts live</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Prompt Testing Modal */}
            {testingAgent && (
                <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-3xl max-w-2xl w-full max-h-[80vh] overflow-y-auto shadow-2xl">
                        <div className="p-6 border-b border-slate-100 flex justify-between items-center">
                            <div>
                                <h3 className="font-bold text-lg">Test: {(testingAgent.agent_name || '').replace(/_/g, ' ')}</h3>
                                <p className="text-xs text-slate-400">Send a test message to preview agent response</p>
                            </div>
                            <button onClick={() => setTestingAgent(null)} className="text-slate-400 hover:text-slate-600">
                                <i className="fa-solid fa-xmark text-xl"></i>
                            </button>
                        </div>
                        <div className="p-6 space-y-4">
                            <div>
                                <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">Test Input</label>
                                <textarea
                                    className="w-full h-24 bg-slate-50 p-3 rounded-xl text-sm border-slate-200 focus:ring-2 focus:ring-indigo-500 outline-none"
                                    placeholder="Type a test message..."
                                    value={testInput}
                                    onChange={(e) => setTestInput(e.target.value)}
                                />
                            </div>
                            <button
                                onClick={handleTestPrompt}
                                disabled={testLoading || !testInput.trim()}
                                className="w-full bg-indigo-600 text-white py-3 rounded-xl font-bold disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {testLoading ? <><i className="fa-solid fa-spinner fa-spin mr-2"></i>Testing...</> : <><i className="fa-solid fa-paper-plane mr-2"></i>Send Test</>}
                            </button>
                            {testResult && (
                                <div>
                                    <label className="text-xs font-bold text-slate-400 uppercase mb-1 block">Response</label>
                                    <div className="bg-slate-50 p-4 rounded-xl text-sm text-slate-700 whitespace-pre-wrap max-h-64 overflow-y-auto font-mono">
                                        {testResult}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AdminContent;
