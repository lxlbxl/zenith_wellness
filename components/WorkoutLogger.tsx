import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { User, Exercise, WorkoutLog } from '../types';

interface WorkoutLoggerProps {
    user: User;
}

const WorkoutLogger: React.FC<WorkoutLoggerProps> = ({ user }) => {
    const [exercises, setExercises] = useState<Exercise[]>([]);
    const [history, setHistory] = useState<WorkoutLog[]>([]);
    const [loading, setLoading] = useState(true);
    const [showLogForm, setShowLogForm] = useState(false);

    // Form State
    const [selectedExercise, setSelectedExercise] = useState<string>('');
    const [customExercise, setCustomExercise] = useState('');
    const [duration, setDuration] = useState<number>(30);
    const [sets, setSets] = useState<number>(0);
    const [reps, setReps] = useState<number>(0);
    const [weight, setWeight] = useState<number>(0);
    const [distance, setDistance] = useState<number>(0);
    const [notes, setNotes] = useState('');

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        setLoading(true);
        try {
            const [exList, histList] = await Promise.all([
                api.get<Exercise[]>('/workouts/exercises'),
                api.get<WorkoutLog[]>(`/workouts/history/${user.id}`)
            ]);
            setExercises(exList || []);
            setHistory(histList || []);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleLogWorkout = async (e: React.FormEvent) => {
        e.preventDefault();
        
        const exerciseObj = exercises.find(ex => ex.id === selectedExercise);
        const name = exerciseObj ? exerciseObj.name : customExercise;
        const type = exerciseObj ? exerciseObj.category : 'strength'; // default

        try {
            await api.post('/workouts/log', {
                exercise_id: selectedExercise || null,
                exercise_name: name,
                workout_type: type,
                duration_minutes: duration,
                sets: sets || null,
                reps: reps || null,
                weight_kg: weight || null,
                distance_km: distance || null,
                notes: notes,
                workout_date: new Date().toISOString().split('T')[0]
            });
            setShowLogForm(false);
            resetForm();
            loadData();
        } catch (err) {
            console.error('Failed to log workout', err);
        }
    };

    const resetForm = () => {
        setSelectedExercise('');
        setCustomExercise('');
        setDuration(30);
        setSets(0);
        setReps(0);
        setWeight(0);
        setDistance(0);
        setNotes('');
    };

    if (loading) return <div className="p-8 text-center"><i className="fa-solid fa-spinner fa-spin text-2xl text-indigo-500"></i></div>;

    return (
        <div className="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
            <div className="flex justify-between items-center">
                <div>
                    <h2 className="text-3xl font-black text-slate-900 tracking-tight">Workouts</h2>
                    <p className="text-slate-500 mt-1">Track your strength, cardio, and movement.</p>
                </div>
                <button
                    onClick={() => setShowLogForm(true)}
                    className="bg-indigo-600 text-white px-5 py-3 rounded-xl font-bold hover:bg-indigo-700 transition shadow-lg shadow-indigo-200"
                >
                    <i className="fa-solid fa-plus mr-2"></i>
                    Log Workout
                </button>
            </div>

            {/* Log Modal / Form Area */}
            {showLogForm && (
                <div className="bg-white p-6 rounded-[2rem] border border-indigo-100 shadow-xl relative">
                    <button 
                        onClick={() => setShowLogForm(false)}
                        className="absolute top-6 right-6 text-slate-400 hover:text-slate-600"
                    >
                        <i className="fa-solid fa-xmark text-xl"></i>
                    </button>

                    <h3 className="text-xl font-bold mb-6">Log Activity</h3>
                    <form onSubmit={handleLogWorkout} className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Exercise</label>
                                <select 
                                    className="w-full p-3 bg-slate-50 rounded-xl font-bold text-slate-700 border-none focus:ring-2 focus:ring-indigo-500"
                                    value={selectedExercise}
                                    onChange={(e) => setSelectedExercise(e.target.value)}
                                >
                                    <option value="">-- Custom / Other --</option>
                                    {exercises.map(ex => (
                                        <option key={ex.id} value={ex.id}>{ex.name} ({ex.category})</option>
                                    ))}
                                </select>
                            </div>
                            
                            {!selectedExercise && (
                                <div>
                                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Custom Name</label>
                                    <input 
                                        type="text" 
                                        className="w-full p-3 bg-slate-50 rounded-xl font-bold border-none focus:ring-2 focus:ring-indigo-500"
                                        placeholder="e.g., Rock Climbing"
                                        value={customExercise}
                                        onChange={e => setCustomExercise(e.target.value)}
                                    />
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Duration (min)</label>
                                <input 
                                    type="number" 
                                    className="w-full p-3 bg-slate-50 rounded-xl font-bold border-none focus:ring-2 focus:ring-indigo-500"
                                    value={duration}
                                    onChange={e => setDuration(parseInt(e.target.value))}
                                />
                            </div>
                        </div>

                        {/* Dynamic fields based on type implies strict categorization, but for flexible UI we can just show optional fields */}
                        <div className="p-4 bg-slate-50 rounded-2xl grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Sets</label>
                                <input type="number" className="w-full p-2 rounded-lg border-none" value={sets} onChange={e => setSets(parseInt(e.target.value))} placeholder="0" />
                            </div>
                            <div>
                                <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Reps</label>
                                <input type="number" className="w-full p-2 rounded-lg border-none" value={reps} onChange={e => setReps(parseInt(e.target.value))} placeholder="0" />
                            </div>
                            <div>
                                <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Weight (kg)</label>
                                <input type="number" className="w-full p-2 rounded-lg border-none" value={weight} onChange={e => setWeight(parseFloat(e.target.value))} placeholder="0" />
                            </div>
                            <div>
                                <label className="block text-[10px] font-bold text-slate-400 uppercase mb-1">Distance (km)</label>
                                <input type="number" className="w-full p-2 rounded-lg border-none" value={distance} onChange={e => setDistance(parseFloat(e.target.value))} placeholder="0" />
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Notes</label>
                            <textarea 
                                className="w-full p-3 bg-slate-50 rounded-xl font-medium border-none focus:ring-2 focus:ring-indigo-500"
                                rows={2}
                                value={notes}
                                onChange={e => setNotes(e.target.value)}
                                placeholder="How did it feel?"
                            ></textarea>
                        </div>

                        <div className="flex justify-end pt-2">
                            <button type="submit" className="bg-indigo-600 text-white px-8 py-3 rounded-xl font-bold hover:bg-indigo-700 transition">
                                Save Log
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Recent Logs List */}
            <div className="grid gap-4">
                {history.map(log => (
                    <div key={log.id} className="bg-white p-5 rounded-3xl border border-slate-100 flex items-center justify-between hover:shadow-md transition">
                        <div className="flex items-center gap-4">
                            <div className="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl">
                                <i className={`fa-solid ${
                                    log.workout_type === 'cardio' ? 'fa-person-running' : 
                                    log.workout_type === 'flexibility' ? 'fa-person-praying' : 
                                    'fa-dumbbell'
                                }`}></i>
                            </div>
                            <div>
                                <h4 className="font-bold text-slate-900">{log.exercise_name}</h4>
                                <p className="text-xs text-slate-400 font-bold uppercase tracking-wide">
                                    {log.duration_minutes} min • {new Date(log.workout_date).toLocaleDateString()}
                                </p>
                            </div>
                        </div>
                        <div className="text-right">
                            {log.sets ? (
                                <p className="text-sm font-bold text-slate-600">{log.sets} x {log.reps} @ {log.weight_kg}kg</p>
                            ) : log.distance_km ? (
                                <p className="text-sm font-bold text-slate-600">{log.distance_km} km</p>
                            ) : (
                                <p className="text-sm font-bold text-slate-600">{log.calories_burned} cal</p>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default WorkoutLogger;
