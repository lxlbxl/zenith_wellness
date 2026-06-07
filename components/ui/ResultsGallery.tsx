import React, { useState, useEffect } from 'react';
import { api } from '../../services/api';

interface ResultData {
    id: string;
    user_name: string;
    program_name: string;
    metric_label: string;
    metric_value: string;
    description: string;
    image_url: string;
}

const ResultsGallery: React.FC = () => {
    const [results, setResults] = useState<ResultData[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchResults = async () => {
            try {
                const res = await api.get<ResultData[] | { data: ResultData[] }>('/results.php');
                const data = Array.isArray(res) ? res : (res as any).data;
                if (Array.isArray(data)) {
                    setResults(data);
                }
            } catch (err) {
                console.error("Failed to load results", err);
            } finally {
                setLoading(false);
            }
        };
        fetchResults();
    }, []);

    if (loading) return null; // or skeleton
    if (results.length === 0) return null;

    return (
        <div className="py-12">
            <div className="text-center mb-10">
                <h3 className="text-2xl font-serif text-slate-900 mb-2">Real Results</h3>
                <p className="text-slate-500">Transformations from our community.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {results.map((r, idx) => (
                    <div key={r.id} className="relative group overflow-hidden rounded-2xl shadow-lg border border-slate-100 bg-white">
                        <div className="h-48 overflow-hidden relative">
                            <img src={r.image_url} alt="Result" className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" />
                            <div className="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                            <div className="absolute bottom-4 left-4 text-white">
                                <p className="font-bold text-lg">{r.metric_value}</p>
                                <p className="text-xs text-white/80 uppercase tracking-widest">{r.metric_label}</p>
                            </div>
                        </div>

                        <div className="p-6">
                            <p className="text-slate-600 italic text-sm mb-4">"{r.description}"</p>
                            <div className="flex items-center justify-between border-t border-slate-50 pt-3">
                                <span className="font-bold text-slate-900 text-sm">{r.user_name}</span>
                                <span className="text-[10px] bg-indigo-50 text-indigo-600 px-2 py-1 rounded-md uppercase font-bold tracking-wide">{r.program_name}</span>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default ResultsGallery;
