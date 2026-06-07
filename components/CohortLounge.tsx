
import React, { useState, useEffect } from 'react';
import { Program, UserStats, User, CohortProgress, CohortTask } from '../types';
import { getCohortMorningBriefing, getCohortPerformanceAnalysis } from '../services/geminiService';
import { db } from '../services/db';
import { api } from '../services/api';

interface CohortLoungeProps {
   user: User;
   stats: UserStats;
   program: Program;
   onClose: () => void;
}

const CohortLounge: React.FC<CohortLoungeProps> = ({ user, stats, program, onClose }) => {
   const [loading, setLoading] = useState(true);
   const [analyzing, setAnalyzing] = useState(false);
   const [progress, setProgress] = useState<CohortProgress | null>(null);
   const [briefing, setBriefing] = useState<any>(null);
   const [analysis, setAnalysis] = useState<any>(null);
   const [activeView, setActiveView] = useState<'mission' | 'intelligence' | 'lounge'>('mission');
   const [lastCompletedId, setLastCompletedId] = useState<string | null>(null);
   const [messages, setMessages] = useState<any[]>([]);
   const [newMessage, setNewMessage] = useState('');
   const [cohortPulse, setCohortPulse] = useState<{ avg_mastery: number, calibration: number }>({ avg_mastery: 0, calibration: 0 });
   const [userRank, setUserRank] = useState<number>(0);
   const [aiError, setAiError] = useState<string | null>(null);

   // Poll for messages
   useEffect(() => {
      if (activeView !== 'lounge') return;

      const fetchMessages = async () => {
         try {
            const msgs = await api.get<any[]>(`/cohort/messages?program_id=${program.id}`);
            setMessages(msgs);
         } catch (err) {
            console.error("Failed to fetch messages", err);
         }
      };

      fetchMessages();
      const interval = setInterval(fetchMessages, 3000);
      return () => clearInterval(interval);
   }, [activeView, program.id]);

   const handleSendMessage = async () => {
      if (!newMessage.trim()) return;

      try {
         await api.post('/cohort/messages', {
            program_id: program.id,
            user_id: user.id,
            user_name: user.name,
            user_rank: (user as any).persona?.toUpperCase() || 'NEWBIE', // Assuming persona is roughly rank
            message: newMessage
         });
         setNewMessage('');
         // Optimistic updat or wait for poll? 
         // Poll is fast (3s), but let's encourage immediate feedback by refetching instantly
         const msgs = await api.get<any[]>(`/cohort/messages?program_id=${program.id}`);
         setMessages(msgs);
      } catch (err) {
         console.error("Failed to send", err);
      }
   };

   // Determine theme color based on category
   const catColor = {
      productivity: 'indigo',
      wellness: 'emerald',
      pcos: 'rose',
      skin: 'amber'
   }[program.category] || 'slate';

   useEffect(() => {
      const initCohort = async () => {
         setLoading(true);
         setAiError(null);
         let saved = await db.getCohortProgress(user.id, program.id);

         try {
            const aiBrief = await getCohortMorningBriefing(user.id, program, stats);
            setBriefing(aiBrief);

            if (!saved) {
               saved = {
                  programId: program.id,
                  currentDay: 1,
                  tasks: (aiBrief?.tasks || []).map((t: any, i: number) => ({
                     id: `task_${i}`,
                     label: t.label || 'Task',
                     completed: false,
                     difficulty: t.difficulty || 'med'
                  })),
                  performanceScore: 0,
                  aiFeedback: "Protocol initialized. Performance score at baseline."
               };
               // Add default tasks if AI returned empty
               if (saved.tasks.length === 0) {
                  saved.tasks = [
                     { id: 'task_0', label: 'Complete morning routine', completed: false, difficulty: 'low' },
                     { id: 'task_1', label: 'Log daily metrics', completed: false, difficulty: 'low' },
                     { id: 'task_2', label: 'Review program objectives', completed: false, difficulty: 'med' }
                  ];
               }
               await db.saveCohortProgress(user.id, saved);
            }

            setProgress(saved);
            setLoading(false);

            // Fetch cohort pulse metrics
            try {
               const pulse = await api.get<any>(`/leaderboard/cohort-pulse?program_id=${program.id}&user_id=${user.id}`);
               setCohortPulse(pulse);
            } catch (err) {
               console.error("Failed to fetch cohort pulse", err);
            }

            // Fetch user rank
            try {
               const rankData = await api.get<any>(`/leaderboard/rank?user_id=${user.id}`);
               setUserRank(rankData.rank || 0);
            } catch (err) {
               console.error("Failed to fetch user rank", err);
            }

            // Trigger initial AI analysis
            if (saved) {
               try {
                  const aiAnalysis = await getCohortPerformanceAnalysis(user.id, program, saved);
                  if (aiAnalysis) setAnalysis(aiAnalysis);
               } catch (analysisErr) {
                  console.error("Analysis Error:", analysisErr);
               }
            }
         } catch (err: any) {
            console.error("Lounge Init Error:", err);
            setAiError(err.message || 'Failed to connect to AI service');
            // Still load any saved progress even if AI fails
            if (saved) {
               setProgress(saved);
            }
            setLoading(false);
         }
      };

      initCohort();
   }, [user.id, program, stats]);

   const toggleTask = async (taskId: string) => {
      if (!progress) return;

      const taskIndex = progress.tasks.findIndex(t => t.id === taskId);
      const becomingCompleted = !progress.tasks[taskIndex].completed;

      if (becomingCompleted) {
         setLastCompletedId(taskId);
         // Brief delay to allow completion animation to play out
         setTimeout(() => setLastCompletedId(null), 1500);
      }

      const updatedTasks = progress.tasks.map(t =>
         t.id === taskId ? { ...t, completed: !t.completed } : t
      );

      const completedCount = updatedTasks.filter(t => t.completed).length;
      const newScore = Math.round((completedCount / updatedTasks.length) * 100);

      const updatedProgress = {
         ...progress,
         tasks: updatedTasks,
         performanceScore: newScore
      };

      setProgress(updatedProgress);
      await db.saveCohortProgress(user.id, updatedProgress);

      // Auto-update analysis when all tasks are done or every few changes
      if (completedCount === updatedTasks.length || (becomingCompleted && completedCount % 2 === 0)) {
         setAnalyzing(true);
         try {
            const aiAnalysis = await getCohortPerformanceAnalysis(user.id, program, updatedProgress);
            if (aiAnalysis) setAnalysis(aiAnalysis);
         } catch (err) {
            console.error("Analysis update error:", err);
         }
         setAnalyzing(false);
      }
   };

   const getDifficultyPips = (difficulty: string) => {
      const count = difficulty === 'high' ? 3 : difficulty === 'med' ? 2 : 1;
      const colorClass = difficulty === 'high' ? 'bg-rose-500' : difficulty === 'med' ? 'bg-amber-500' : 'bg-emerald-500';

      return (
         <div className="flex gap-1">
            {[...Array(3)].map((_, i) => (
               <div
                  key={i}
                  className={`w-1.5 h-1.5 rounded-full transition-all duration-500 ${i < count ? colorClass : 'bg-slate-100'}`}
               />
            ))}
         </div>
      );
   };

   if (loading) {
      return (
         <div className="min-h-[600px] flex flex-col items-center justify-center text-center space-y-8 animate-in fade-in duration-500">
            <div className="relative">
               <div className={`w-28 h-28 border-4 border-slate-100 rounded-full`}></div>
               <div className={`absolute inset-0 w-28 h-28 border-4 border-${catColor}-600 border-t-transparent rounded-full animate-spin`}></div>
               <div className="absolute inset-0 flex items-center justify-center">
                  <i className={`fa-solid fa-satellite text-${catColor}-600 text-2xl animate-pulse`}></i>
               </div>
            </div>
            <div className="space-y-3">
               <h2 className="text-2xl font-black text-slate-900 tracking-tight uppercase">Calibrating Node Transmission</h2>
               <p className="text-slate-500 max-w-xs mx-auto font-medium leading-relaxed">
                  Connecting to the Smart Orchestrator to retrieve your unique metabolic and productivity trajectory...
               </p>
            </div>
         </div>
      );
   }

   return (
      <div className="space-y-8 animate-in fade-in slide-in-from-bottom-8 duration-1000 pb-24">
         {/* AI Error Alert */}
         {aiError && (
            <div className="bg-amber-50 border-2 border-amber-200 rounded-2xl p-4 flex items-center gap-4">
               <div className="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center flex-shrink-0">
                  <i className="fa-solid fa-robot text-amber-600"></i>
               </div>
               <div className="flex-1">
                  <p className="text-sm font-bold text-amber-800">Smart Connection Issue</p>
                  <p className="text-xs text-amber-600">{aiError}</p>
               </div>
               <button
                  onClick={() => {
                     setAiError(null);
                     setLoading(true);
                     window.location.reload();
                  }}
                  className="px-4 py-2 bg-amber-600 text-white rounded-xl text-xs font-bold hover:bg-amber-700 transition-all"
               >
                  Retry
               </button>
            </div>
         )}
         {/* Immersive Performance Header */}
         <div className="bg-white p-6 md:p-12 rounded-[2.5rem] md:rounded-[3.5rem] border border-slate-100 shadow-2xl shadow-slate-200/40 relative overflow-hidden group">
            <div className={`absolute top-0 right-0 w-[40rem] h-[40rem] bg-${catColor}-50/50 rounded-full -mr-64 -mt-64 mix-blend-multiply opacity-50 transition-transform duration-1000 group-hover:scale-110`}></div>

            <div className="relative z-10 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6 lg:gap-10">
               <div className="flex items-start gap-4 md:gap-8">
                  <button
                     onClick={onClose}
                     className="w-12 h-12 md:w-16 md:h-16 bg-slate-50 rounded-2xl md:rounded-[1.5rem] border border-slate-100 text-slate-400 hover:text-slate-900 hover:border-slate-300 transition-all shadow-sm flex items-center justify-center group/back"
                  >
                     <i className="fa-solid fa-arrow-left text-lg md:text-xl group-hover/back:-translate-x-1 transition-transform"></i>
                  </button>
                  <div className="space-y-2 md:space-y-3">
                     <div className="flex items-center gap-2 md:gap-3">
                        <span className={`px-2 md:px-4 py-1 rounded-full text-[8px] md:text-[10px] font-black uppercase tracking-[0.15em] bg-${catColor}-600 text-white shadow-lg shadow-${catColor}-200`}>
                           Mission Active
                        </span>
                        <span className="text-slate-400 text-[10px] md:text-xs font-black tracking-widest uppercase">
                           Day {progress?.currentDay} <span className="text-slate-200 mx-1">/</span> 21
                        </span>
                     </div>
                     <h1 className="text-2xl md:text-4xl lg:text-5xl font-black text-slate-900 tracking-tighter leading-tight md:leading-[0.9]">{program.title}</h1>
                     <p className="text-slate-400 font-medium text-sm md:text-lg max-w-xl line-clamp-2 md:line-clamp-none">{program.description}</p>
                  </div>
               </div>

               <div className="grid grid-cols-2 gap-3 md:gap-4 w-full lg:w-auto">
                  <div className="bg-slate-50/80 backdrop-blur-sm px-4 md:px-10 py-3 md:py-6 rounded-2xl md:rounded-[2.5rem] border border-slate-100 flex flex-col items-center justify-center text-center">
                     <p className="text-[8px] md:text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Smart Score</p>
                     <div className="flex items-baseline gap-1">
                        <p className={`text-xl md:text-4xl font-black text-${catColor}-600`}>{progress?.performanceScore}</p>
                        <span className="text-slate-400 font-bold text-xs md:text-base">%</span>
                     </div>
                  </div>
                  <div className={`bg-${catColor}-600 px-4 md:px-10 py-3 md:py-6 rounded-2xl md:rounded-[2.5rem] shadow-xl shadow-${catColor}-200 flex flex-col items-center justify-center text-center text-white`}>
                     <p className="text-[8px] md:text-[10px] font-black uppercase tracking-widest mb-1 opacity-70">Global Rank</p>
                     <p className="text-xl md:text-4xl font-black">#{userRank || '-'}</p>
                  </div>
               </div>
            </div>

            {/* Tactical Navigation Tabs */}
            <div className="relative z-10 flex flex-wrap gap-2 mt-8 md:mt-12 bg-slate-50/50 p-1.5 md:p-2 rounded-2xl md:rounded-3xl w-fit border border-slate-100">
               {[
                  { id: 'mission', label: 'Control', icon: 'fa-shuttle-space' },
                  { id: 'intelligence', label: 'Zenith IQ', icon: 'fa-brain-circuit' },
                  { id: 'lounge', label: 'Network', icon: 'fa-network-wired' },
               ].map((tab) => (
                  <button
                     key={tab.id}
                     onClick={() => setActiveView(tab.id as any)}
                     className={`flex items-center gap-2 md:gap-3 px-4 md:px-8 py-2.5 md:py-4 rounded-xl md:rounded-2xl text-[8px] md:text-[10px] font-black uppercase tracking-widest transition-all ${activeView === tab.id
                        ? `bg-white text-${catColor}-600 shadow-xl border border-slate-100`
                        : 'text-slate-400 hover:text-slate-600 hover:bg-white/50'
                        }`}
                  >
                     <i className={`fa-solid ${tab.icon} text-xs md:text-sm`}></i>
                     <span className="hidden sm:inline">{tab.label}</span>
                     <span className="sm:hidden">{tab.id === 'mission' ? 'Control' : tab.id === 'intelligence' ? 'IQ' : 'Net'}</span>
                  </button>
               ))}
            </div>
         </div>

         <div className="grid grid-cols-1 lg:grid-cols-4 gap-6 md:gap-10">
            <div className="lg:col-span-3 space-y-6 md:space-y-10">
               {activeView === 'mission' && (
                  <div className="space-y-6 md:space-y-10 animate-in fade-in slide-in-from-left-4 duration-500">
                     {/* AI Morning Directive Card - Sleeker and Mobile Optimized */}
                     <div className={`bg-slate-900 p-6 md:p-10 lg:p-12 rounded-[2rem] md:rounded-[3.5rem] lg:rounded-[4rem] text-white shadow-2xl relative overflow-hidden group`}>
                        <div className={`absolute top-0 right-0 w-full h-full bg-gradient-to-br from-${catColor}-600/20 to-transparent`}></div>

                        <div className="relative z-10 space-y-6 md:space-y-10">
                           <div className="flex items-center gap-3 md:gap-5">
                              <div className={`w-10 h-10 md:w-14 md:h-14 bg-${catColor}-600 rounded-2xl md:rounded-3xl flex items-center justify-center shadow-2xl shadow-${catColor}-500/30`}>
                                 <i className="fa-solid fa-satellite-dish md:text-xl animate-pulse"></i>
                              </div>
                              <div>
                                 <span className="text-[8px] md:text-[10px] font-black uppercase tracking-[0.2em] md:tracking-[0.3em] text-indigo-400 block mb-0.5 md:mb-1">Priority Directive</span>
                                 <span className="text-[10px] md:text-sm font-bold text-slate-400">Zenith Analysis: Complete</span>
                              </div>
                           </div>

                           <h2 className="text-xl md:text-3xl lg:text-4xl font-black leading-tight md:leading-[1.1] max-w-3xl tracking-tight">
                              {briefing?.briefing || "Analyzing metabolic vectors for optimal daily calibration..."}
                           </h2>

                           <div className="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-8 pt-2 md:pt-4">
                              <div className="p-5 md:p-8 bg-white/5 rounded-2xl md:rounded-[2.5rem] border border-white/10 backdrop-blur-xl group/tip hover:bg-white/10 transition-all">
                                 <div className="flex items-center gap-2 md:gap-3 mb-3 md:mb-4">
                                    <i className="fa-solid fa-microchip text-indigo-400 text-xs md:text-base"></i>
                                    <p className="text-[8px] md:text-[10px] font-black uppercase tracking-widest text-slate-400">Optimization</p>
                                 </div>
                                 <p className="text-sm md:text-lg lg:text-xl font-bold leading-relaxed text-slate-200">
                                    "{briefing?.performanceTip || "Awaiting real-time biometric synchronization..."}"
                                 </p>
                              </div>

                              <div className="bg-gradient-to-br from-indigo-500/10 to-transparent p-5 md:p-8 rounded-2xl md:rounded-[2.5rem] border border-white/10 flex flex-col justify-center">
                                 <div className="flex justify-between items-center mb-3 md:mb-6">
                                    <span className="text-[8px] md:text-[10px] font-black uppercase tracking-widest text-indigo-400">Stability Index</span>
                                    <span className="text-[10px] font-bold text-emerald-400">Live</span>
                                 </div>
                                 <div className="flex items-baseline gap-1 md:gap-2 mb-2 md:mb-4">
                                    <span className="text-3xl md:text-5xl font-black">{progress?.performanceScore}</span>
                                    <span className="text-lg md:text-2xl font-bold text-slate-500">%</span>
                                 </div>
                                 <div className="h-1.5 md:h-2 w-full bg-white/5 rounded-full overflow-hidden">
                                    <div className={`h-full bg-${catColor}-500 shadow-[0_0_15px_rgba(79,70,229,0.5)] transition-all duration-1000`} style={{ width: `${progress?.performanceScore}%` }}></div>
                                 </div>
                              </div>
                           </div>
                        </div>
                        <i className={`fa-solid fa-dna absolute -right-16 -bottom-16 md:-right-24 md:-bottom-24 text-[15rem] md:text-[35rem] text-white/5 rotate-45 pointer-events-none`}></i>
                     </div>

                     {/* Protocol Matrix (Tasks) */}
                     <div className="bg-white p-6 md:p-12 rounded-[2.5rem] md:rounded-[4rem] border border-slate-100 shadow-sm">
                        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 md:gap-6 mb-8 md:mb-12">
                           <div>
                              <h3 className="text-xl md:text-3xl font-black text-slate-900 tracking-tight uppercase">Operational Protocol</h3>
                              <p className="text-xs md:text-slate-400 font-medium mt-1">Execute these high-leverage tasks to ensure cohort alignment.</p>
                           </div>
                           <div className="bg-slate-50 px-4 md:px-6 py-2 md:py-3 rounded-xl md:rounded-2xl border border-slate-100 flex items-center gap-3 md:gap-4 w-fit">
                              <p className="text-[8px] md:text-[10px] font-black text-slate-400 uppercase tracking-widest">Tasks Met</p>
                              <div className="flex items-baseline gap-1">
                                 <span className={`text-xl md:text-2xl font-black text-${catColor}-600`}>{progress?.tasks.filter(t => t.completed).length}</span>
                                 <span className="text-slate-300 font-bold text-xs md:text-sm">/ {progress?.tasks.length}</span>
                              </div>
                           </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                           {progress?.tasks.map((task) => {
                              const isSyncing = lastCompletedId === task.id;
                              return (
                                 <div
                                    key={task.id}
                                    onClick={() => toggleTask(task.id)}
                                    className={`group p-6 md:p-8 rounded-3xl md:rounded-[3rem] border-2 transition-all cursor-pointer relative overflow-hidden active:scale-95 ${task.completed
                                       ? 'bg-slate-50 border-slate-100'
                                       : `bg-white border-slate-100 hover:border-${catColor}-500 hover:shadow-2xl hover:shadow-${catColor}-100/50`
                                       } ${isSyncing ? `ring-4 ring-${catColor}-400/30 bg-${catColor}-50` : ''}`}
                                 >
                                    {/* Success Pulse Effect */}
                                    {isSyncing && (
                                       <div className="absolute inset-0 bg-gradient-to-r from-transparent via-white/40 to-transparent animate-shimmer pointer-events-none"></div>
                                    )}

                                    <div className="flex items-center gap-4 md:gap-6 relative z-10">
                                       <div className={`w-12 h-12 md:w-16 md:h-16 rounded-2xl md:rounded-[1.75rem] flex items-center justify-center transition-all flex-shrink-0 ${task.completed
                                          ? `bg-${catColor}-600 text-white shadow-lg ${isSyncing ? 'animate-bounce' : ''}`
                                          : 'bg-slate-50 text-slate-300 border border-slate-100 group-hover:bg-white group-hover:scale-110'
                                          }`}>
                                          {task.completed ? <i className="fa-solid fa-check text-xl md:text-2xl"></i> : <i className="fa-solid fa-bolt-lightning md:text-lg"></i>}
                                       </div>
                                       <div className="flex-1">
                                          <p className={`text-sm md:text-xl font-black leading-tight mb-1 md:mb-2 transition-all ${task.completed ? 'text-slate-400 line-through opacity-60' : 'text-slate-800'}`}>
                                             {task.label}
                                          </p>
                                          <div className="flex items-center gap-2 md:gap-4">
                                             <div className="flex items-center gap-1.5 md:gap-2">
                                                <span className="text-[8px] md:text-[9px] font-black uppercase tracking-widest text-slate-400">Complexity</span>
                                                {getDifficultyPips(task.difficulty)}
                                             </div>
                                             <div className="w-[1px] h-3 bg-slate-200"></div>
                                             {!task.completed ? (
                                                <span className={`text-[8px] md:text-[9px] font-black text-${catColor}-600 animate-pulse`}>+25 XP</span>
                                             ) : (
                                                <span className={`text-[8px] md:text-[9px] font-black text-emerald-500 flex items-center gap-1`}>
                                                   <i className="fa-solid fa-circle-check"></i> SYNCED
                                                </span>
                                             )}
                                          </div>
                                       </div>
                                    </div>

                                    {/* Bottom Progress Indicator */}
                                    <div className={`absolute left-0 bottom-0 h-1 md:h-1.5 transition-all duration-700 ${task.completed ? `bg-${catColor}-600 w-full` : 'bg-slate-100 w-0'}`}></div>
                                 </div>
                              );
                           })}
                        </div>
                     </div>
                  </div>
               )}

               {activeView === 'intelligence' && (
                  <div className="space-y-6 md:space-y-10 animate-in fade-in slide-in-from-right-4 duration-500">
                     <div className="bg-white p-6 md:p-12 rounded-3xl md:rounded-[4rem] border border-slate-100 shadow-sm relative overflow-hidden min-h-[500px] md:min-h-[600px]">
                        <div className={`absolute top-0 right-0 w-full h-full bg-gradient-to-br from-indigo-50/20 to-transparent`}></div>

                        <div className="relative z-10 space-y-8 md:space-y-12">
                           <header className="flex flex-col md:flex-row items-start md:items-center justify-between gap-6 md:gap-8">
                              <div>
                                 <div className="flex items-center gap-3 md:gap-4 mb-3">
                                    <div className="w-10 h-10 md:w-12 md:h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white md:text-xl shadow-xl shadow-indigo-200">
                                       <i className="fa-solid fa-brain-circuit"></i>
                                    </div>
                                    <h3 className="text-xl md:text-2xl font-black tracking-tight uppercase">Cognitive Analysis</h3>
                                 </div>
                                 <p className="text-slate-400 font-medium text-xs md:text-base max-w-lg leading-relaxed">
                                    Zenith Intelligence Engine is synthesizing your current biometric performance against the {program.category} objectives.
                                 </p>
                              </div>
                              <div className="flex items-center gap-3 md:gap-5 bg-slate-900 p-4 md:p-6 rounded-2xl md:rounded-[2rem] border border-white/10 shadow-2xl">
                                 <div className="text-right">
                                    <p className="text-[8px] md:text-[9px] font-black text-indigo-400 uppercase tracking-widest mb-1">State Vector</p>
                                    <p className="text-lg md:text-2xl font-black text-emerald-400 uppercase tracking-tighter">
                                       {analysis?.status || 'Processing'}
                                    </p>
                                 </div>
                                 <div className="w-10 h-10 md:w-12 md:h-12 rounded-xl bg-white/5 flex items-center justify-center text-indigo-400">
                                    {analyzing ? <i className="fa-solid fa-atom animate-spin"></i> : <i className="fa-solid fa-shield-check"></i>}
                                 </div>
                              </div>
                           </header>

                           <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 md:gap-10">
                              <div className="space-y-6 md:space-y-8">
                                 <h4 className="text-[8px] md:text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] pl-1">Target Mastery</h4>
                                 <div className="space-y-6 md:space-y-8">
                                    {(analysis?.masteryScores || program.objectives?.map(o => ({ objective: o, score: 35 })) || []).map((m: any, i: number) => (
                                       <div key={i} className="group">
                                          <div className="flex justify-between items-end mb-2 md:mb-3 px-1">
                                             <span className="text-[10px] md:text-sm font-black text-slate-700 uppercase tracking-tight">{m.objective}</span>
                                             <span className={`text-[10px] md:text-sm font-black text-${catColor}-600`}>{m.score}%</span>
                                          </div>
                                          <div className="h-2 md:h-2.5 w-full bg-slate-100 rounded-full overflow-hidden shadow-inner">
                                             <div className={`h-full bg-${catColor}-600 shadow-[0_0_15px_rgba(79,70,229,0.3)] transition-all duration-1000 group-hover:scale-x-[1.02]`} style={{ width: `${m.score}%` }}></div>
                                          </div>
                                       </div>
                                    ))}
                                 </div>
                              </div>

                              <div className="bg-slate-50 p-6 md:p-10 rounded-3xl md:rounded-[3.5rem] border border-slate-100 space-y-6 md:space-y-8 flex flex-col justify-center relative group">
                                 <div className="absolute top-6 md:top-8 right-6 md:right-8 text-indigo-100 opacity-50 md:opacity-100">
                                    <i className="fa-solid fa-quote-right text-4xl md:text-6xl"></i>
                                 </div>
                                 <h4 className="text-[8px] md:text-[10px] font-black text-indigo-500 uppercase tracking-[0.3em] relative z-10">Smart Insight</h4>
                                 <p className="text-base md:text-2xl font-bold text-slate-800 leading-snug md:leading-[1.4] italic relative z-10">
                                    "{analysis?.insight || 'Zenith Engine is currently parsing your datasets...'}"
                                 </p>
                                 <div className="pt-6 md:pt-8 border-t border-slate-200 mt-2 md:mt-4 relative z-10">
                                    <div className="flex items-center gap-2 md:gap-3 mb-2 md:mb-3">
                                       <i className="fa-solid fa-arrow-trend-up text-emerald-500 text-xs md:text-sm"></i>
                                       <p className="text-[8px] md:text-[10px] font-black text-slate-400 uppercase tracking-widest">Adjustment</p>
                                    </div>
                                    <p className="text-sm md:text-lg font-black text-slate-900 leading-tight">
                                       {analysis?.recommendation || 'Maintain current baseline protocol synchronization.'}
                                    </p>
                                 </div>
                              </div>
                           </div>
                        </div>
                        <i className={`fa-solid fa-microchip absolute -right-16 -bottom-16 md:-right-24 md:-bottom-24 text-[15rem] md:text-[35rem] text-slate-50/50 pointer-events-none`}></i>
                     </div>
                  </div>
               )}





               {activeView === 'lounge' && (
                  <div className="bg-white h-[600px] md:h-[750px] rounded-3xl md:rounded-[4rem] border border-slate-100 shadow-sm flex flex-col overflow-hidden animate-in fade-in slide-in-from-bottom-4">
                     <div className="p-6 md:p-10 border-b border-slate-50 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 md:gap-6">
                        <div className="flex items-center gap-4 md:gap-5">
                           <div className="w-12 h-12 md:w-16 md:h-16 bg-slate-900 rounded-2xl md:rounded-[1.75rem] flex items-center justify-center text-white md:text-2xl shadow-2xl">
                              <i className="fa-solid fa-users-viewfinder"></i>
                           </div>
                           <div>
                              <h3 className="text-lg md:text-2xl font-black text-slate-900 tracking-tight uppercase">Cohort Network</h3>
                              <div className="flex items-center gap-2 md:gap-3 mt-0.5 md:mt-1">
                                 <span className="w-2 h-2 md:w-2.5 md:h-2.5 bg-emerald-500 rounded-full animate-pulse"></span>
                                 <span className="text-[8px] md:text-[10px] font-black text-slate-400 uppercase tracking-widest">{messages.length > 0 ? messages.length : 14} Active Nodes</span>
                              </div>
                           </div>
                        </div>
                        <div className="flex gap-2">
                           <button className="text-[8px] md:text-[9px] font-black text-slate-500 uppercase tracking-widest px-3 md:px-5 py-2 md:py-3 bg-slate-50 rounded-xl md:rounded-2xl border border-slate-100 hover:bg-white transition-all">Archive</button>
                           <button className="text-[8px] md:text-[9px] font-black text-indigo-600 uppercase tracking-widest px-3 md:px-5 py-2 md:py-3 bg-indigo-50 rounded-xl md:rounded-2xl border border-indigo-100 hover:bg-white transition-all">Pinned</button>
                        </div>
                     </div>

                     <div className="flex-1 bg-slate-50/30 p-6 md:p-10 space-y-6 md:space-y-8 overflow-y-auto custom-scrollbar">
                        <div className="flex justify-center mb-2">
                           <span className="px-4 md:px-6 py-1.5 md:py-2 bg-white rounded-full text-[8px] md:text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] md:tracking-[0.3em] border border-slate-100 shadow-sm">
                              Day {progress?.currentDay || 1} Inter-Node Comms
                           </span>
                        </div>

                        {messages.length === 0 && (
                           <div className="text-center py-10 opacity-50">
                              <p className="text-sm font-bold text-slate-400">Secure channel initialized. Be the first to transmit.</p>
                           </div>
                        )}

                        {messages.map((m, i) => {
                           const isMe = m.user_id === user.id;
                           const roleColor = m.user_rank === 'VETERAN' ? 'bg-rose-50 text-rose-600' : m.user_rank === 'ACTIVE' ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-50 text-slate-600';

                           return (
                              <div key={i} className={`flex gap-4 md:gap-6 group ${isMe ? 'flex-row-reverse' : ''}`}>
                                 <div className="relative flex-shrink-0">
                                    <div className="w-10 h-10 md:w-14 md:h-14 rounded-xl md:rounded-3xl bg-slate-200 border-2 border-white shadow-xl flex items-center justify-center font-black text-slate-400">
                                       {m.user_name.charAt(0)}
                                    </div>
                                 </div>
                                 <div className={`p-5 md:p-8 rounded-[1.5rem] md:rounded-[3rem] shadow-sm border max-w-[85%] transition-shadow relative ${isMe ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-slate-100'}`}>
                                    <div className={`flex items-center gap-2 md:gap-3 mb-1 md:mb-2 ${isMe ? 'justify-end' : ''}`}>
                                       <p className={`text-[8px] md:text-[10px] font-black uppercase tracking-widest ${isMe ? 'text-indigo-200' : 'text-slate-900'}`}>{m.user_name}</p>
                                       {!isMe && <span className={`text-[7px] md:text-[8px] font-black px-1.5 md:px-2 py-0.5 rounded-full ${roleColor}`}>{m.user_rank}</span>}
                                    </div>
                                    <p className={`text-xs md:text-base font-medium leading-relaxed ${isMe ? 'text-indigo-50' : 'text-slate-700'}`}>{m.message}</p>
                                 </div>
                              </div>
                           );
                        })}

                        <div className="flex flex-col items-center py-10 md:py-16 opacity-30">
                           <div className="w-12 h-12 md:w-16 md:h-16 bg-slate-100 rounded-full flex items-center justify-center mb-4 md:mb-6">
                              <i className="fa-solid fa-lock text-xl md:text-2xl text-slate-400"></i>
                           </div>
                           <p className="text-[8px] md:text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] md:tracking-[0.4em]">End of History</p>
                        </div>
                     </div>

                     <div className="p-6 md:p-8 bg-white border-t border-slate-50 flex gap-3 md:gap-5">
                        <div className="flex-1 relative">
                           <input
                              type="text"
                              value={newMessage}
                              onChange={(e) => setNewMessage(e.target.value)}
                              onKeyDown={(e) => e.key === 'Enter' && handleSendMessage()}
                              placeholder="Broadcast feedback..."
                              className="w-full bg-slate-50 border border-slate-100 rounded-2xl md:rounded-[2rem] pl-6 md:pl-8 pr-12 md:pr-16 py-3.5 md:py-5 text-xs md:text-sm focus:outline-none focus:ring-4 focus:ring-indigo-500/10 transition-all font-semibold"
                           />
                           <button
                              onClick={handleSendMessage}
                              className="absolute right-2.5 top-2.5 w-8 h-8 md:w-10 md:h-10 bg-indigo-600 text-white rounded-xl md:rounded-2xl flex items-center justify-center hover:scale-105 active:scale-95 transition-all shadow-lg"
                           >
                              <i className="fa-solid fa-bolt-auto text-[10px]"></i>
                           </button>
                        </div>
                        <button className="w-11 h-11 md:w-14 md:h-14 bg-slate-900 text-white rounded-xl md:rounded-[1.5rem] flex items-center justify-center hover:bg-black transition-all shadow-2xl">
                           <i className="fa-solid fa-paper-plane text-xs md:text-sm"></i>
                        </button>
                     </div>
                  </div>
               )}
            </div>

            {/* Intelligence Mission Sidebar */}
            <div className="space-y-6 md:space-y-10">
               {/* Biometric Performance Pulse */}
               <div className="bg-white p-6 md:p-10 rounded-3xl md:rounded-[3.5rem] border border-slate-100 shadow-sm group">
                  <h3 className="text-[8px] md:text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] md:tracking-[0.3em] mb-6 md:mb-8">Cohort Pulse Index</h3>
                  <div className="space-y-6 md:space-y-10">
                     <div className="flex items-center justify-between">
                        <div className="space-y-0.5 md:space-y-1">
                           <p className="text-2xl md:text-4xl font-black text-slate-900 tracking-tighter">{progress?.performanceScore}%</p>
                           <p className="text-[8px] md:text-[9px] font-black text-slate-400 uppercase tracking-widest">Mastery</p>
                        </div>
                        <div className="text-right space-y-0.5 md:space-y-1">
                           <p className="text-2xl md:text-4xl font-black text-indigo-600 tracking-tighter">{cohortPulse.avg_mastery || 0}%</p>
                           <p className="text-[8px] md:text-[9px] font-black text-slate-400 uppercase tracking-widest">Global</p>
                        </div>
                     </div>

                     <div className="h-3 md:h-4 w-full bg-slate-50 rounded-full overflow-hidden flex shadow-inner">
                        <div className={`h-full bg-${catColor}-600 shadow-[0_0_15px_rgba(79,70,229,0.3)] transition-all duration-1000 group-hover:scale-x-[1.05] origin-left`} style={{ width: `${progress?.performanceScore}%` }}></div>
                     </div>

                     <div className="p-4 md:p-6 bg-slate-50/80 rounded-2xl md:rounded-3xl border border-slate-100 flex items-start gap-3 md:gap-4">
                        <div className={`w-7 h-7 md:w-8 md:h-8 rounded-lg md:rounded-xl ${cohortPulse.calibration >= 0 ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'} flex items-center justify-center flex-shrink-0 text-[10px] md:text-xs shadow-sm`}>
                           <i className={`fa-solid ${cohortPulse.calibration >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'}`}></i>
                        </div>
                        <p className="text-[10px] md:text-[11px] text-slate-500 leading-relaxed font-bold">
                           {cohortPulse.calibration >= 0
                              ? <>Stable calibration. You are <span className="text-emerald-600">{cohortPulse.calibration}% above</span> median.</>
                              : <>Below baseline. You are <span className="text-rose-600">{Math.abs(cohortPulse.calibration)}% below</span> median.</>}
                        </p>
                     </div>
                  </div>
               </div>

               {/* Objective Mastery Checklist */}
               <div className={`bg-${catColor}-600/5 p-6 md:p-10 rounded-3xl md:rounded-[4rem] border border-${catColor}-100/50 relative overflow-hidden`}>
                  <h3 className={`text-[8px] md:text-[10px] font-black text-${catColor}-700 uppercase tracking-[0.2em] md:tracking-[0.3em] mb-6 md:mb-8`}>Objectives</h3>
                  <div className="space-y-3 md:space-y-4">
                     {program.objectives?.map((obj, i) => {
                        const isComplete = (analysis?.masteryScores?.[i]?.score || 0) > 80;
                        return (
                           <div key={i} className={`flex items-center gap-3 md:gap-5 p-3.5 md:p-5 rounded-2xl md:rounded-[2rem] bg-white border-2 transition-all ${isComplete ? `border-${catColor}-500 shadow-xl shadow-${catColor}-100/30` : 'border-slate-50'}`}>
                              <div className={`w-8 h-8 md:w-10 md:h-10 rounded-xl md:rounded-2xl flex items-center justify-center text-xs md:text-sm flex-shrink-0 ${isComplete ? `bg-${catColor}-600 text-white shadow-lg` : 'bg-slate-100 text-slate-300'}`}>
                                 {isComplete ? <i className="fa-solid fa-check"></i> : <i className="fa-solid fa-circle-notch animate-spin-slow"></i>}
                              </div>
                              <span className={`text-[10px] md:text-xs font-black leading-tight uppercase tracking-tight ${isComplete ? 'text-slate-900' : 'text-slate-400'}`}>
                                 {obj}
                              </span>
                           </div>
                        );
                     })}
                  </div>
                  <i className={`fa-solid fa-shield-halved absolute -right-8 -bottom-8 text-7xl md:text-9xl text-${catColor}-600/5 pointer-events-none`}></i>
               </div>

               {/* Direct Support Node */}
               <div className="bg-slate-900 p-6 md:p-10 rounded-3xl md:rounded-[3.5rem] text-white relative overflow-hidden group cursor-pointer hover:bg-black transition-all shadow-2xl">
                  <div className="relative z-10">
                     <div className="flex items-center gap-3 md:gap-4 mb-4 md:mb-5">
                        <div className="w-8 h-8 md:w-10 md:h-10 bg-indigo-600 rounded-xl md:rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-500/30">
                           <i className="fa-solid fa-headset text-xs"></i>
                        </div>
                        <span className="text-[8px] md:text-[10px] font-black uppercase tracking-[0.2em] text-indigo-400">Tactical Support</span>
                     </div>
                     <p className="text-xs md:text-sm font-bold text-slate-400 leading-relaxed mb-4 md:mb-6">
                        Synchronize with a Specialist for immediate calibration.
                     </p>
                     <button className="w-full py-3 md:py-4 bg-white/10 border border-white/10 rounded-xl md:rounded-2xl text-[8px] md:text-[10px] font-black uppercase tracking-widest hover:bg-white hover:text-slate-900 transition-all">
                        Initialize
                     </button>
                  </div>
                  <i className="fa-solid fa-satellite absolute -right-8 -bottom-8 text-7xl md:text-[12rem] text-white/5 group-hover:rotate-12 transition-transform duration-700"></i>
               </div>
            </div>
         </div>
      </div>
   );
};

export default CohortLounge;
