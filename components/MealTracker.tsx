
import React, { useState, useEffect } from 'react';
import { analyzeMealImage } from '../services/geminiService';
import { UserStats } from '../types';
import { db } from '../services/db';
import { api } from '../services/api';
import Card from './ui/Card';
import Button from './ui/Button';

interface MealTrackerProps {
  userId: string;
  stats: UserStats;
  onMealLogged: (macros: any) => void;
}

interface MealHistoryItem {
  id: string;
  date: string;
  foodItems: string[];
  macros: {
    protein: number;
    carbs: number;
    fats: number;
    calories: number;
  };
  wellnessScore: number;
  summary: string;
  imagePreview?: string;
}

const MealTracker: React.FC<MealTrackerProps> = ({ userId, stats, onMealLogged }) => {
  const [loading, setLoading] = useState(false);
  const [pendingResult, setPendingResult] = useState<any>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [history, setHistory] = useState<MealHistoryItem[]>([]);
  const [selectedHistoryItem, setSelectedHistoryItem] = useState<MealHistoryItem | null>(null);

  // New Filter State
  const [selectedDate, setSelectedDate] = useState<string>(new Date().toISOString().split('T')[0]);
  const [mealStats, setMealStats] = useState<any>(null);

  useEffect(() => {
    loadHistory();
    loadStats();
  }, [userId, selectedDate]);

  const loadHistory = async () => {
    try {
      const savedHistory = await api.get<MealHistoryItem[]>(`/meals/history?user_id=${userId}&date=${selectedDate}`);
      setHistory(Array.isArray(savedHistory) ? savedHistory : []);
    } catch (err) {
      console.error('Failed to load history', err);
    }
  };

  const loadStats = async () => {
    try {
      const res = await api.get<any>(`/meals/stats?user_id=${userId}&range=week`);
      // Find today's stats from the array
      const todayStats = res.stats?.find((s: any) => s.date === selectedDate);
      setMealStats(todayStats || { total_calories: 0, meal_count: 0 });
    } catch (err) {
      console.error('Failed to load stats', err);
    }
  };

  const confirmAndLogMeal = async () => {
    if (!pendingResult) return;

    try {
      await api.post('/meals/log', {
        user_id: userId,
        name: pendingResult.foodItems[0] || 'Meal',
        type: 'snack', // Could make this dynamic
        calories: pendingResult.macros.calories,
        macros: pendingResult.macros,
        analysis: {
          wellnessScore: pendingResult.wellnessScore,
          summary: pendingResult.summary,
          foodItems: pendingResult.foodItems
        },
        image: preview
      });

      // Refresh data
      loadHistory();
      loadStats();
      onMealLogged(pendingResult.macros);
      setPendingResult(null);
      setPreview(null);
    } catch (err) {
      alert('Failed to save meal');
    }
  };

  const handleImageUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onloadend = async () => {
      const base64 = (reader.result as string).split(',')[1];
      setPreview(reader.result as string);
      setLoading(true);
      setPendingResult(null);
      try {
        const data = await analyzeMealImage(base64);
        setPendingResult(data);
      } catch (err: any) {
        // Show the fallback result from geminiService so the UI doesn't break
        setPendingResult({
          foodItems: ["Could not identify meal"],
          macros: { protein: 0, carbs: 0, fats: 0, calories: 0 },
          wellnessScore: 0,
          summary: err?.message || "Analysis failed. Please try again with a clearer photo."
        });
      } finally {
        setLoading(false);
      }
    };
    reader.readAsDataURL(file);
  };

  const clearHistory = async () => {
    if (window.confirm(`Are you sure you want to clear meal logs for ${selectedDate}?`)) {
      try {
        await api.delete(`/meals/history?user_id=${userId}&date=${selectedDate}`);
        loadHistory();
        loadStats();
      } catch (e) {
        alert('Failed to clear history');
      }
    }
  };

  return (
    <div className="space-y-8 animate-in fade-in duration-700 pb-12">
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <h2 className="text-3xl font-serif text-stone-900">Nourishment</h2>
          <p className="text-stone-500 mt-1 max-w-xl">
            Log your meals for precise hormonal guidance.
          </p>
        </div>
        <div className="flex gap-4">
          {/* Date Picker */}
          <div className="bg-white px-4 py-3 rounded-2xl border border-stone-100 shadow-sm">
            <input
              type="date"
              value={selectedDate}
              onChange={(e) => setSelectedDate(e.target.value)}
              className="text-stone-800 font-bold bg-transparent outline-none cursor-pointer text-sm"
              max={new Date().toISOString().split('T')[0]}
            />
          </div>

          <div className="bg-white px-6 py-3 rounded-2xl border border-stone-100 shadow-sm flex items-center gap-4">
            <div className="text-right">
              <p className="text-[10px] font-bold text-stone-400 uppercase tracking-widest">{selectedDate === new Date().toISOString().split('T')[0] ? 'Today' : 'Daily'} Intake</p>
              <p className="font-serif text-xl text-stone-800">{mealStats?.total_calories || 0} <span className="text-xs text-stone-400 font-sans font-bold">kcal</span></p>
            </div>
            <div className="w-12 h-12 bg-stone-900 text-white rounded-xl flex items-center justify-center shadow-lg shadow-stone-200 text-xl">
              <i className="fa-solid fa-utensils"></i>
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {/* Upload Section */}
        <Card className="!p-0 overflow-hidden min-h-[400px] flex flex-col items-center justify-center text-center group transition-all relative border border-dashed border-stone-200 hover:border-brand-300 bg-stone-50/50">
          {preview ? (
            <div className="relative w-full h-full bg-stone-900 flex items-center justify-center">
              <img src={preview} className="w-full h-full object-cover absolute inset-0 opacity-80" alt="Preview" />
              <div className="absolute inset-0 bg-gradient-to-t from-stone-900 via-transparent to-transparent"></div>

              {loading && (
                <div className="absolute inset-0 bg-black/40 backdrop-blur-sm flex items-center justify-center flex-col gap-4 z-10">
                  <div className="w-16 h-16 border-4 border-white/20 border-t-brand-400 rounded-full animate-spin"></div>
                  <p className="text-white font-serif tracking-wide animate-pulse">Analyzing...</p>
                </div>
              )}

              <div className="absolute bottom-8 left-0 right-0 p-6 z-10">
                <label className={`inline-block bg-white text-stone-900 px-8 py-3 rounded-xl font-bold cursor-pointer shadow-xl hover:bg-stone-100 transition-all ${loading ? 'opacity-0 pointer-events-none' : ''}`}>
                  Take Another Photo
                  <input type="file" accept="image/*" className="hidden" onChange={handleImageUpload} disabled={loading} />
                </label>
              </div>
            </div>
          ) : (
            <div className="flex flex-col items-center p-12 w-full h-full justify-center">
              <div className="w-24 h-24 bg-white text-stone-300 rounded-3xl flex items-center justify-center text-4xl mb-6 shadow-sm group-hover:text-brand-400 group-hover:scale-110 transition-all duration-300">
                <i className="fa-solid fa-camera-retro"></i>
              </div>
              <h3 className="font-serif text-2xl text-stone-700 mb-2">Capture your meal</h3>
              <p className="text-stone-400 font-medium mb-8 max-w-sm">
                Snap a photo of your food. Intelligent analysis will determine macronutrients and wellness score.
              </p>
              <label className="bg-stone-900 text-white px-8 py-4 rounded-xl font-bold cursor-pointer shadow-lg hover:bg-black transition-all hover:-translate-y-1">
                Upload Photo
                <input type="file" accept="image/*" className="hidden" onChange={handleImageUpload} disabled={loading} />
              </label>
            </div>
          )}
        </Card>

        {/* Analysis Result Section */}
        <div className="flex flex-col gap-6">
          {loading ? (
            <Card className="flex-1 flex flex-col items-center justify-center text-center animate-pulse min-h-[400px]">
              <div className="w-20 h-20 bg-stone-100 rounded-full mb-6 relative overflow-hidden">
                <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white/50 to-transparent translate-x-[-100%] animate-[shimmer_1.5s_infinite]"></div>
              </div>
              <h3 className="text-2xl font-serif text-stone-800 mb-2">Decoding Macros...</h3>
              <p className="text-stone-400 text-sm max-w-xs">Zenith is calculating the metabolic impact of your choice.</p>
            </Card>
          ) : pendingResult ? (
            <Card className={`flex-1 space-y-8 animate-in slide-in-from-right-4 min-h-[400px] border-2 ${pendingResult.wellnessScore < 5 ? 'border-rose-200 bg-rose-50/10' : 'border-stone-100'}`}>
              <div className="flex items-center justify-between border-b border-stone-100 pb-6">
                <div>
                  <h3 className="text-xl font-bold text-stone-900 uppercase tracking-wide">Analysis Result</h3>
                  {pendingResult.wellnessScore < 5 && (
                    <div className="flex items-center gap-2 mt-2 text-rose-500 animate-bounce">
                      <i className="fa-solid fa-triangle-exclamation"></i>
                      <span className="text-xs font-black uppercase tracking-widest">Metabolic Warning</span>
                    </div>
                  )}
                </div>
                <div className={`flex flex-col items-end`}>
                  <div className={`text-3xl font-serif font-bold ${pendingResult.wellnessScore < 5 ? 'text-rose-600' : 'text-emerald-600'}`}>
                    {pendingResult.wellnessScore}<span className="text-lg text-stone-300">/10</span>
                  </div>
                  <span className="text-[10px] font-bold uppercase tracking-widest text-stone-400">Wellness Score</span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                {[
                  { label: 'Protein', val: pendingResult.macros.protein, unit: 'g', color: 'bg-indigo-400' },
                  { label: 'Carbs', val: pendingResult.macros.carbs, unit: 'g', color: 'bg-amber-400' },
                  { label: 'Fats', val: pendingResult.macros.fats, unit: 'g', color: 'bg-stone-400' },
                  { label: 'Energy', val: pendingResult.macros.calories, unit: 'kcal', color: 'bg-emerald-400' },
                ].map(m => (
                  <div key={m.label} className="bg-stone-50 p-4 rounded-xl border border-stone-100">
                    <div className="flex items-center gap-2 mb-2">
                      <div className={`w-2 h-2 rounded-full ${m.color}`}></div>
                      <span className="text-[10px] font-bold uppercase text-stone-400 tracking-widest">{m.label}</span>
                    </div>
                    <p className="text-2xl font-serif text-stone-800">{m.val}<span className="text-xs font-bold text-stone-400 ml-1 font-sans">{m.unit}</span></p>
                  </div>
                ))}
              </div>

              <div className="bg-white p-6 rounded-xl border border-stone-100 shadow-sm relative">
                <i className="fa-solid fa-quote-left text-4xl text-stone-100 absolute top-4 left-4 -z-10"></i>
                <p className="text-stone-600 italic leading-relaxed z-10 relative">"{pendingResult.summary}"</p>
              </div>

              <div className="flex gap-4 pt-4">
                <Button
                  variant="secondary"
                  onClick={() => { setPendingResult(null); setPreview(null); }}
                  className="flex-1 bg-white hover:bg-stone-100 text-stone-500"
                >
                  Discard
                </Button>
                <Button
                  onClick={confirmAndLogMeal}
                  className="flex-[2] shadow-xl shadow-brand-500/20"
                >
                  Confirm & Log
                </Button>
              </div>
            </Card>
          ) : (
            <Card className="flex-1 flex flex-col items-center justify-center text-center min-h-[400px]">
              <div className="w-16 h-16 bg-stone-50 rounded-full flex items-center justify-center text-stone-300 text-2xl mb-4">
                <i className="fa-solid fa-arrow-left"></i>
              </div>
              <h3 className="font-bold text-stone-400 mb-1">Waiting for upload</h3>
              <p className="text-stone-300 text-sm max-w-xs">Upload your meal on the left to specific metabolic analysis.</p>
            </Card>
          )}
        </div>
      </div>

      {/* History Section */}
      <div className="mt-16 space-y-6">
        <div className="flex items-center justify-between border-b border-stone-100 pb-4">
          <div>
            <h3 className="text-2xl font-serif text-stone-900">Consumption History</h3>
            <p className="text-sm text-stone-400 mt-1">Showing records for {new Date(selectedDate).toLocaleDateString()}</p>
          </div>
          {history.length > 0 && (
            <button
              onClick={clearHistory}
              className="text-xs font-bold text-stone-400 hover:text-rose-500 transition-colors uppercase tracking-widest"
            >
              Clear Day
            </button>
          )}
        </div>

        {history.length === 0 ? (
          <div className="bg-stone-50 rounded-3xl p-12 text-center border-2 border-dashed border-stone-200">
            <i className="fa-solid fa-utensils text-3xl text-stone-300 mb-4 block"></i>
            <p className="text-stone-500 font-medium">No meals logged for this date.</p>
            <p className="text-stone-400 text-sm mt-1">Select another date or log a meal above.</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {history.map((item) => (
              <Card
                key={item.id}
                onClick={() => setSelectedHistoryItem(item)}
                className={`!p-0 relative overflow-hidden group cursor-pointer hover:-translate-y-1 transition-transform duration-300 ${item.wellnessScore < 5 ? 'border-rose-200' : 'border-stone-100'}`}
              >
                <div className="p-6">
                  <div className="flex justify-between items-start mb-4">
                    <div className="text-[10px] font-bold text-stone-400 uppercase tracking-widest">
                      {new Date(item.date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    </div>
                    <div className={`px-2 py-1 rounded-lg text-[10px] font-black uppercase tracking-wider ${item.wellnessScore < 5 ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'}`}>
                      Score: {item.wellnessScore}
                    </div>
                  </div>

                  <div className="flex items-start gap-4 mb-6">
                    {item.imagePreview ? (
                      <img src={item.imagePreview} className="w-16 h-16 rounded-xl object-cover shadow-sm bg-stone-100" alt="Thumbnail" />
                    ) : (
                      <div className="w-16 h-16 rounded-xl bg-stone-100 flex items-center justify-center text-stone-300 text-xl">
                        <i className="fa-solid fa-bowl-food"></i>
                      </div>
                    )}
                    <div>
                      {/* Handle fallback for missing analysis/foodItems */}
                      <h4 className="font-bold text-stone-800 line-clamp-2 leading-tight">
                        {item.foodItems?.[0] || 'Logged Meal'}
                        {item.foodItems?.length > 1 && <span className="text-stone-400 font-normal text-sm">+{item.foodItems.length - 1} more</span>}
                      </h4>
                      <p className="text-xs text-stone-500 mt-1">{new Date(item.date).toLocaleDateString([], { month: 'short', day: 'numeric' })}</p>
                    </div>
                  </div>

                  <div className="flex gap-2 p-3 bg-stone-50 rounded-xl">
                    <div className="flex-1 text-center border-r border-stone-200">
                      <p className="text-[9px] font-bold text-stone-400 uppercase tracking-widest">Cals</p>
                      <p className="text-sm font-bold text-stone-800">{item.macros?.calories || 0}</p>
                    </div>
                    <div className="flex-1 text-center">
                      <p className="text-[9px] font-bold text-stone-400 uppercase tracking-widest">Prot</p>
                      <p className="text-sm font-bold text-stone-800">{item.macros?.protein || 0}g</p>
                    </div>
                  </div>
                </div>

                {/* Visual indicator bar at bottom */}
                <div className={`h-1.5 w-full ${item.wellnessScore < 5 ? 'bg-rose-500' : 'bg-brand-500'}`}></div>
              </Card>
            ))}
          </div>
        )}
      </div>

      {/* History Detail Modal */}
      {selectedHistoryItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-stone-900/60 backdrop-blur-sm animate-in fade-in duration-300">
          <Card className="w-full max-w-3xl !p-0 overflow-hidden relative shadow-2xl animate-in zoom-in-95 duration-300">
            <button
              onClick={() => setSelectedHistoryItem(null)}
              className="absolute top-4 right-4 w-10 h-10 bg-white/10 backdrop-blur-md rounded-full flex items-center justify-center text-white hover:bg-white/20 transition-colors z-10 shadow-lg"
            >
              <i className="fa-solid fa-xmark"></i>
            </button>

            <div className="grid grid-cols-1 md:grid-cols-2">
              <div className="h-64 md:h-auto bg-stone-900 relative">
                {selectedHistoryItem.imagePreview ? (
                  <>
                    <img src={selectedHistoryItem.imagePreview} className="w-full h-full object-cover opacity-90" alt="Meal" />
                    <div className="absolute inset-0 bg-gradient-to-t from-stone-900 via-transparent to-transparent"></div>
                  </>
                ) : (
                  <div className="w-full h-full flex items-center justify-center bg-stone-800 text-stone-700">
                    <i className="fa-solid fa-image text-6xl"></i>
                  </div>
                )}
                <div className="absolute bottom-0 left-0 w-full p-8 md:p-10">
                  <h2 className="text-2xl md:text-3xl font-serif text-white leading-tight mb-2 shadow-black drop-shadow-md">
                    {selectedHistoryItem.foodItems?.[0] || 'Meal'}
                  </h2>
                  {selectedHistoryItem.foodItems?.length > 1 && (
                    <p className="text-stone-300 text-sm">
                      with {selectedHistoryItem.foodItems.slice(1).join(', ')}
                    </p>
                  )}
                </div>
              </div>

              <div className="p-8 space-y-8 bg-white overflow-y-auto max-h-[60vh] md:max-h-auto">
                <div>
                  <div className="flex items-center gap-3 mb-6">
                    <span className={`px-3 py-1 rounded-lg text-xs font-bold uppercase tracking-wider ${selectedHistoryItem.wellnessScore < 5 ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600'}`}>
                      Wellness Score: {selectedHistoryItem.wellnessScore}/10
                    </span>
                    <span className="text-xs text-stone-400 font-bold uppercase tracking-wider">
                      {new Date(selectedHistoryItem.date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    </span>
                  </div>

                  <div className="grid grid-cols-2 gap-4 mb-8">
                    {[
                      { label: 'Protein', val: selectedHistoryItem.macros?.protein || 0, unit: 'g' },
                      { label: 'Carbs', val: selectedHistoryItem.macros?.carbs || 0, unit: 'g' },
                      { label: 'Fats', val: selectedHistoryItem.macros?.fats || 0, unit: 'g' },
                      { label: 'Calories', val: selectedHistoryItem.macros?.calories || 0, unit: 'kcal' },
                    ].map(m => (
                      <div key={m.label} className="bg-stone-50 p-4 rounded-xl border border-stone-100">
                        <p className="text-[10px] font-bold uppercase text-stone-400 tracking-widest mb-1">{m.label}</p>
                        <p className="text-xl font-serif text-stone-800">{m.val}<span className="text-xs font-bold text-stone-400 ml-1 font-sans">{m.unit}</span></p>
                      </div>
                    ))}
                  </div>

                  <div className="space-y-3">
                    <h4 className="text-xs font-bold uppercase text-stone-400 tracking-widest">Intelligent Analysis</h4>
                    <div className={`p-6 rounded-xl border relative ${selectedHistoryItem.wellnessScore < 5 ? 'bg-rose-50 border-rose-100 text-rose-900' : 'bg-brand-50 border-brand-100 text-brand-900'}`}>
                      <i className="fa-solid fa-sparkles absolute top-4 right-4 text-lg opacity-20"></i>
                      <p className="text-sm leading-relaxed italic">"{selectedHistoryItem.summary || 'No analysis available'}"</p>
                    </div>
                  </div>
                </div>

                <Button
                  onClick={() => setSelectedHistoryItem(null)}
                  className="w-full"
                >
                  Close Analysis
                </Button>
              </div>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
};

export default MealTracker;

