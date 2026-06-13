
import React, { useState, useEffect } from 'react';
import { UserStats, Mood, DailyGoals, AppTab, CycleStats, CyclePhase, Program, User, UserRole } from '../types';
import { db } from '../services/db';
import { generatePrepGuide, getPersonalizedGreeting } from '../services/geminiService';
import { api } from '../services/api';
import {
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, AreaChart, Area, LineChart, Line
} from 'recharts';
import NotificationCenter from './NotificationCenter';
import CountdownTimer from './ui/CountdownTimer';
import LiveActivityFeed from './ui/LiveActivityFeed';
import MemberCount from './ui/MemberCount';
import DailyBriefing from './ui/DailyBriefing';
import AICoachDemo from './ui/AICoachDemo';
import VideoPlayer from './ui/VideoPlayer';

interface DashboardProps {
  user: User;
  stats: UserStats;
  onMoodCheckIn: (mood: Mood) => void;
  onUpdateGoals: (goals: DailyGoals) => void;
  onNavigate?: (tab: AppTab) => void;
  onOpenPayment?: (program: Program) => void;
  defaultProgram?: Program;
  programs: Program[];
  userRole?: UserRole;
}

// ... (Constants kept same)

const PHASE_INFO: Record<CyclePhase, { label: string; color: string; icon: string; description: string }> = {
  menstrual: {
    label: 'Menstrual Phase',
    color: 'text-rose-400',
    icon: 'fa-droplet',
    description: 'Rest & Restore'
  },
  follicular: {
    label: 'Follicular Phase',
    color: 'text-amber-400',
    icon: 'fa-seedling',
    description: 'Rise & Shine'
  },
  ovulation: {
    label: 'Ovulation Phase',
    color: 'text-emerald-400',
    icon: 'fa-sun',
    description: 'Peak Energy'
  },
  luteal: {
    label: 'Luteal Phase',
    color: 'text-indigo-400',
    icon: 'fa-moon',
    description: 'Wind Down'
  },
};

const Dashboard: React.FC<DashboardProps> = ({ user, stats, onMoodCheckIn, onUpdateGoals, onNavigate, onOpenPayment, defaultProgram, programs, userRole }) => {
  const userId = user.id;
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [tempGoals, setTempGoals] = useState<DailyGoals>(stats.goals);
  const [timeLeft, setTimeLeft] = useState({ days: 0, hours: 0, mins: 0, secs: 0 });
  const [cycleStats, setCycleStats] = useState<CycleStats | null>(null);
  const [showVideoModal, setShowVideoModal] = useState(false);
  const [checkedItems, setCheckedItems] = useState<boolean[]>([true, false, false, true]);
  const [showAllActivity, setShowAllActivity] = useState(false);
  const [downloadingGuide, setDownloadingGuide] = useState(false);
  const [prepGuide, setPrepGuide] = useState<any>(null);
  const [showPrepGuideModal, setShowPrepGuideModal] = useState(false);

  // New Feature Widget States
  const [smartGreeting, setSmartGreeting] = useState<string>('');
  const [habitStats, setHabitStats] = useState<{ active_habits: number; today_completed: number; best_streak: number } | null>(null);
  const [goalStats, setGoalStats] = useState<{ active_goals: number; completed_goals: number; completion_rate: number } | null>(null);
  const [gamificationData, setGamificationData] = useState<{ xp: number; level: number; rank: number } | null>(null);
  const [showQuickActions, setShowQuickActions] = useState(false);
  const [activeEnrollment, setActiveEnrollment] = useState<any>(null);
  const [enrollmentLoading, setEnrollmentLoading] = useState(true);
  const [totalEnrolledUsers, setTotalEnrolledUsers] = useState<number | null>(null);

  // Compute countdown from enrollment start_date
  useEffect(() => {
    const checkEnrollment = async () => {
      try {
        const enrollments = await api.get<any[]>('/enrollment/mine');
        if (enrollments && enrollments.length > 0) {
          setActiveEnrollment(enrollments[0]);
        }
      } catch (err) {
        console.error("Failed to load enrollment:", err);
      } finally {
        setEnrollmentLoading(false);
      }
    };
    checkEnrollment();
  }, []);

  // Only load AI greeting and other data AFTER we confirm user has active access
  // This prevents using AI tokens for free users before they see the paywall
  useEffect(() => {
    // Don't load if still checking enrollment or if user doesn't have access
    if (enrollmentLoading) return;
    const isVeteranUser = stats.persona === 'veteran';
    const hasAccess = !!activeEnrollment || isVeteranUser;
    if (!hasAccess) return;

    const loadData = async () => {
      // Load Cycle Stats
      const userLogs = await db.getCycleLogs(userId);

      if (userLogs.length > 0) {
        const sortedLogs = [...userLogs].sort((a, b) => new Date(b.startDate).getTime() - new Date(a.startDate).getTime());
        const lastPeriod = sortedLogs[0];
        const avgCycle = 28;
        const lastDate = new Date(lastPeriod.startDate);
        const day = Math.floor((new Date().getTime() - lastDate.getTime()) / (1000 * 60 * 60 * 24)) + 1;

        let phase: CyclePhase = 'luteal';
        if (day <= 5) phase = 'menstrual';
        else if (day <= 13) phase = 'follicular';
        else if (day <= 16) phase = 'ovulation';

        setCycleStats({
          averageCycleLength: 28,
          averagePeriodLength: 5,
          currentPhase: phase,
          dayOfCycle: day,
          nextPeriodDate: new Date(lastDate.getTime() + 28 * 24 * 60 * 60 * 1000).toISOString(),
        });
      }

      // Load AI Greeting (only for users with access)
      try {
        const greeting = await getPersonalizedGreeting(userId);
        if (greeting) setSmartGreeting(greeting);
      } catch (err) {
        console.error("Failed to load smart greeting:", err);
      }

      // Load Habit Stats
      try {
        const hStats = await api.get<any>(`/habits/stats/${userId}`);
        if (hStats) setHabitStats(hStats);
      } catch (err) {
        console.error("Failed to load habit stats:", err);
      }

      // Load Goal Stats
      try {
        const gStats = await api.get<any>(`/goals/stats/${userId}`);
        if (gStats) setGoalStats(gStats);
      } catch (err) {
        console.error("Failed to load goal stats:", err);
      }

      // Load Gamification Data
      try {
        const gData = await api.get<any>(`/gamification/summary/${userId}`);
        if (gData) setGamificationData(gData);
      } catch (err) {
        console.error("Failed to load gamification data:", err);
      }
    };
    loadData();
  }, [enrollmentLoading, activeEnrollment, stats.persona, userId]);

  // Fetch total enrolled users for social proof
  useEffect(() => {
    fetch('/api/stats')
      .then(res => res.json())
      .then(data => {
        if (data.total_enrolled_users && data.total_enrolled_users > 0) {
          setTotalEnrolledUsers(data.total_enrolled_users);
        }
      })
      .catch(() => {});
  }, []);

  // Real countdown timer from enrollment start_date
  useEffect(() => {
    if (!activeEnrollment?.start_date) return;
    const targetDate = new Date(activeEnrollment.start_date);
    const timer = setInterval(() => {
      const now = new Date();
      const diff = targetDate.getTime() - now.getTime();
      if (diff <= 0) {
        setTimeLeft({ days: 0, hours: 0, mins: 0, secs: 0 });
        clearInterval(timer);
        return;
      }
      const days = Math.floor(diff / (1000 * 60 * 60 * 24));
      const hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
      const mins = Math.floor((diff / (1000 * 60)) % 60);
      const secs = Math.floor((diff / 1000) % 60);
      setTimeLeft({ days, hours, mins, secs });
    }, 1000);
    return () => clearInterval(timer);
  }, [activeEnrollment?.start_date]);

  const moodEmojis: Record<Mood, string> = {
    ecstatic: '🤩', happy: '😊', neutral: '😐', tired: '😫', stressed: '😰', down: '😔'
  };

  const currentMood = stats.moodHistory[stats.moodHistory.length - 1]?.mood;

  // Calculate days until next period
  const getDaysUntil = () => {
    if (!cycleStats) return 0;
    const diff = new Date(cycleStats.nextPeriodDate).getTime() - new Date().getTime();
    return Math.max(0, Math.ceil(diff / (1000 * 60 * 60 * 24)));
  };

  // Helper to quantify mood
  const getMoodScore = (m: Mood | undefined): number => {
    if (!m) return 50;
    const scores: Record<Mood, number> = {
      ecstatic: 95, happy: 85, neutral: 60, tired: 40, stressed: 30, down: 20
    };
    return scores[m] || 50;
  }

  // Generate 7-day trend data
  const combinedData = React.useMemo(() => {
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const today = new Date().getDay();
    const data = [];

    // Generate previous 6 days of synthetic "history" that converges towards current stats
    // In a real app, this would query historical DailyLogs
    for (let i = 6; i >= 0; i--) {
      const dIndex = (today - i + 7) % 7;
      // Synthetic variance
      const variance = Math.sin(i) * 20;

      let focus = i === 0 ? stats.focusMinutes : Math.max(40, stats.focusMinutes - (i * 10) + variance);
      let wellness = i === 0 ? getMoodScore(currentMood) : Math.min(100, Math.max(30, getMoodScore(currentMood) - (variance / 2)));

      data.push({
        day: days[dIndex],
        focus: Math.round(focus),
        wellness: Math.round(wellness),
      });
    }
    return data;
  }, [stats.focusMinutes, currentMood]);

  // Insight Logic
  const correlationInsight = React.useMemo(() => {
    const trend = combinedData[combinedData.length - 1].focus > combinedData[0].focus ? 'rising' : 'stable';
    if (getMoodScore(currentMood) > 70 && stats.focusMinutes > 60) return "Optimal State: High focus is fueling your wellness.";
    if (getMoodScore(currentMood) < 50 && stats.focusMinutes > 120) return "Risk Warning: High output is draining your energy. Consider a break.";
    return "Balanced: Your activity levels are sustainable.";
  }, [combinedData, currentMood, stats.focusMinutes]);

  // Determine Access Level based on Real Enrollment Data
  const isVeteran = stats.persona === 'veteran';
  const hasActiveAccess = !!activeEnrollment || isVeteran;

  // Show Sales View if no access (and not just loading)
  const showSalesView = !enrollmentLoading && !hasActiveAccess;

  // Show Waiting Room if enrolled but start date is future
  const showWaitingRoom = activeEnrollment && new Date(activeEnrollment.start_date) > new Date();

  // Get a rotating featured cohort from programs
  const featuredCohort = programs.length > 0
    ? programs[Math.floor(Date.now() / (1000 * 60 * 60)) % programs.length] // Rotate hourly
    : defaultProgram;
  const featuredPrice = featuredCohort?.price ? (featuredCohort.price / 100).toFixed(0) : '49';
  const featuredTitle = featuredCohort?.title || '21-Day Wellness Program';

  if (showSalesView) {
    return (
      <div className="space-y-8 animate-in fade-in duration-700">
        <header>
          <h1 className="text-4xl font-black text-slate-900 tracking-tight">Unlock Your Potential</h1>
          <p className="text-slate-500 mt-2">You're currently in <b>Free Access</b> mode. Start a challenge to unlock deep diagnostics.</p>
        </header>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
          <div className="bg-white p-10 rounded-[2.5rem] border border-slate-100 shadow-sm flex flex-col justify-center">
            <div className="w-16 h-16 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center text-2xl mb-6">
              <i className="fa-solid fa-rocket"></i>
            </div>
            <h2 className="text-2xl font-black text-slate-900 mb-4">Start Your {featuredTitle}</h2>
            <p className="text-slate-500 mb-8 leading-relaxed">{featuredCohort?.description || `${totalEnrolledUsers ? `Join ${totalEnrolledUsers.toLocaleString()}+ members` : 'Join thousands of members'} in the next cohort. Get a personalized protocol and 1-on-1 Intelligent accountability.`}</p>
            <button
              onClick={() => featuredCohort && onOpenPayment?.(featuredCohort)}
              className="bg-indigo-600 text-white py-4 px-8 rounded-2xl font-black shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all w-fit"
            >
              Claim Your Spot - ₦{featuredPrice}
            </button>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="bg-slate-100/50 p-6 rounded-3xl border border-slate-100 flex flex-col items-center justify-center text-center opacity-60 grayscale">
              <i className="fa-solid fa-lock text-slate-300 text-xl mb-2"></i>
              <p className="text-[10px] font-black uppercase text-slate-400">Macro Tracker</p>
            </div>
            <div className="bg-slate-100/50 p-6 rounded-3xl border border-slate-100 flex flex-col items-center justify-center text-center opacity-60 grayscale">
              <i className="fa-solid fa-lock text-slate-300 text-xl mb-2"></i>
              <p className="text-[10px] font-black uppercase text-slate-400">PCOS Score</p>
            </div>
            <div className="bg-slate-100/50 p-6 rounded-3xl border border-slate-100 flex flex-col items-center justify-center text-center opacity-60 grayscale">
              <i className="fa-solid fa-lock text-slate-300 text-xl mb-2"></i>
              <p className="text-[10px] font-black uppercase text-slate-400">Cycle Phase</p>
            </div>
            <div className="bg-slate-100/50 p-6 rounded-3xl border border-slate-100 flex flex-col items-center justify-center text-center opacity-60 grayscale">
              <i className="fa-solid fa-lock text-slate-300 text-xl mb-2"></i>
              <p className="text-[10px] font-black uppercase text-slate-400">Symptom Logs</p>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
          <div className="bg-slate-900 p-10 rounded-[2.5rem] text-white overflow-hidden relative">
            <div className="relative z-10">
              <h3 className="text-2xl font-black mb-4">Limited Feature: Meal Scan</h3>
              <p className="text-slate-400 mb-6 max-w-md">Free users can scan 1 meal per day. Upgrade to unlimited for real-time PCOS alignment scoring.</p>
              <button onClick={() => onNavigate?.(AppTab.MEAL_TRACKER)} className="text-indigo-400 font-black text-sm hover:underline">Try Scanner Now</button>
            </div>
            <i className="fa-solid fa-camera absolute -right-8 -bottom-8 text-[12rem] text-white/5"></i>
          </div>

          <div className="bg-white p-10 rounded-[2.5rem] border border-slate-100 shadow-sm relative overflow-hidden group">
            <div className="flex items-center justify-between mb-6">
              <div className="flex items-center gap-2">
                <div className="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
                <span className="text-[10px] font-black uppercase text-slate-400 tracking-widest">Live Experience</span>
              </div>
              <span className="text-[10px] font-black text-indigo-500 bg-indigo-50 px-3 py-1 rounded-full uppercase">Zenith IQ Demo</span>
            </div>
            <h3 className="text-xl font-black text-slate-900 mb-4">Meet Your Smart Coach</h3>
            <div className="bg-slate-50 rounded-3xl p-4 mb-6">
              <AICoachDemo userName={user.name} />
            </div>
            <p className="text-xs text-slate-500 leading-relaxed mb-6">Our proprietary clinical intelligence analyzes 142 biological markers in real-time. Upgrade to start your full diagnostic.</p>
            <button
              onClick={() => defaultProgram && onOpenPayment?.(defaultProgram)}
              className="w-full py-3 bg-slate-900 text-white rounded-2xl font-black text-sm shadow-xl hover:bg-slate-800 transition-all"
            >
              Start Protocol to Unlock IQ
            </button>
          </div>
        </div>
      </div>
    );
  }


  const phaseCurrent = cycleStats ? PHASE_INFO[cycleStats.currentPhase] : PHASE_INFO.menstrual;

  if (showWaitingRoom) {
    return (
      <div className="space-y-8 animate-in slide-in-from-right-4 duration-700">
        <LiveActivityFeed />
        <header className="flex flex-col md:flex-row md:items-center justify-between gap-6">
          <div>
            <div className="flex items-center gap-2 mb-2">
              <span className="bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest">Pre-Launch Phase</span>
              <MemberCount variant="badge" />
            </div>
            <h1 className="text-4xl font-black text-slate-900 tracking-tight">The Waiting Room</h1>
            <p className="text-slate-500 font-medium mt-1">Protocol starts soon. Prepare your physiology.</p>
          </div>
          <div className="bg-white px-8 py-5 rounded-[2rem] border border-slate-100 shadow-xl shadow-indigo-100/10 flex flex-col items-center">
            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Cohort Starts In</p>
            <CountdownTimer targetDate={new Date(activeEnrollment.start_date)} size="lg" variant="banner" />
          </div>
        </header>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <div className="lg:col-span-2 space-y-8">
            <div className="bg-indigo-600 p-10 rounded-[3rem] text-white shadow-2xl shadow-indigo-200">
              <h2 className="text-3xl font-black mb-4">Step 1: Metabolic Priming</h2>
              <p className="text-indigo-100 mb-8 leading-relaxed max-w-xl">We start the Metabolic Reset in {timeLeft.days > 0 ? `${timeLeft.days} days` : 'less than a day'}. This week's goal is to audit your pantry and remove inflammatory triggers. Your Smart Coach is available for specific food questions.</p>
              <div className="flex flex-wrap gap-4">
                <button
                  onClick={async () => {
                    setShowPrepGuideModal(true);
                    if (!prepGuide) {
                      setDownloadingGuide(true);
                      try {
                        const guide = await generatePrepGuide({ id: userId, name: 'User', email: '', persona: stats.persona || 'newbie' }, stats, defaultProgram?.id || 'pcos_1');
                        setPrepGuide(guide);
                      } catch (e) {
                        console.error('Failed to generate guide', e);
                      } finally {
                        setDownloadingGuide(false);
                      }
                    }
                  }}
                  disabled={downloadingGuide && !showPrepGuideModal}
                  className="bg-white text-indigo-600 px-8 py-4 rounded-2xl font-black text-sm shadow-lg hover:translate-y-[-2px] transition-all disabled:opacity-50"
                >
                  {downloadingGuide ? 'Generating...' : 'View Prep Guide'}
                </button>
                <button
                  onClick={() => setShowVideoModal(true)}
                  className="bg-indigo-500 text-white px-8 py-4 rounded-2xl font-black text-sm border border-indigo-400 hover:bg-indigo-400 transition-all"
                >
                  Watch Onboarding
                </button>
              </div>
            </div>

            <div className="bg-white p-10 rounded-[3rem] border border-slate-100 shadow-sm">
              <div className="flex items-center justify-between mb-8">
                <h3 className="text-xl font-black text-slate-800">Pre-Launch Checklist</h3>
                <span className="text-[10px] font-black text-indigo-500 bg-indigo-50 px-3 py-1 rounded-full uppercase">2/4 Complete</span>
              </div>
              <div className="space-y-4">
                {[
                  { label: 'Metabolic Baseline Quiz', icon: 'fa-clipboard-question', action: () => { } },
                  { label: 'Connect Continuous Glucose Monitor', icon: 'fa-plug-circle-bolt', action: () => { } },
                  { label: 'Log First Menstrual Cycle Dates', icon: 'fa-droplet', action: () => onNavigate?.(AppTab.CYCLE_TRACKER) },
                  { label: 'Join Private Cohort Slack', icon: 'fa-hashtag', action: () => window.open('https://slack.com', '_blank') },
                ].map((item, i) => {
                  const isDone = checkedItems[i] || (i === 2 && !!cycleStats);
                  return (
                    <div
                      key={i}
                      onClick={() => {
                        if (!isDone) {
                          item.action?.();
                          const newChecked = [...checkedItems];
                          newChecked[i] = true;
                          setCheckedItems(newChecked);
                        }
                      }}
                      className="flex items-center gap-4 p-5 rounded-3xl bg-slate-50 border border-slate-100 hover:border-slate-200 transition-all cursor-pointer group"
                    >
                      <div className={`w-10 h-10 rounded-2xl flex items-center justify-center transition-all ${isDone ? 'bg-emerald-500 text-white' : 'bg-white border-2 border-slate-100 text-slate-300'}`}>
                        <i className={`fa-solid ${isDone ? 'fa-check' : item.icon} text-sm`}></i>
                      </div>
                      <div className="flex-1">
                        <span className={`text-sm font-bold ${isDone ? 'text-slate-400 line-through' : 'text-slate-700'}`}>{item.label}</span>
                        {!isDone && <p className="text-[10px] text-slate-400 mt-0.5 font-bold uppercase tracking-tighter">High Priority</p>}
                      </div>
                      <i className="fa-solid fa-chevron-right text-slate-200 group-hover:translate-x-1 transition-transform"></i>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
        <div className="bg-emerald-50 p-8 rounded-[3rem] border border-emerald-100 relative overflow-hidden">
          <p className="text-[10px] font-black text-emerald-600 uppercase tracking-widest mb-2">Metabolic Fact</p>
          <p className="text-sm text-emerald-900 font-bold leading-relaxed relative z-10">"High morning protein reduces insulin spikes by up to 30% for the rest of the day."</p>
          <i className="fa-solid fa-seedling absolute -right-4 -bottom-4 text-6xl text-emerald-100/50"></i>
        </div>
      </div>
    );
  }

  // Active / Veteran View
  return (
    <div className="space-y-8 animate-in slide-in-from-bottom-4 duration-500 relative pb-12">
      <header className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div>
          <div className="flex items-center gap-2 mb-2">
            <span className="px-3 py-1 bg-indigo-600 text-white text-[10px] font-black rounded-full tracking-widest uppercase">
              {isVeteran ? 'Veteran Status' : 'Active Cohort'}
            </span>
            <span className="text-slate-400 text-xs font-bold">
              {isVeteran ? 'All Systems Optimized' : 'Metabolic Reset Phase 1 • Day 4 of 21'}
            </span>
          </div>
          <h1 className="text-4xl font-black text-slate-900 tracking-tight">Performance Portal</h1>
        </div>

        <div className="flex items-center gap-4">
          <NotificationCenter />
          <div className="bg-white p-4 rounded-[1.5rem] border border-slate-100 shadow-sm flex items-center gap-4 max-w-md">
            <div className="w-12 h-12 bg-indigo-50 rounded-full flex items-center justify-center text-indigo-600 flex-shrink-0 animate-pulse">
              <i className="fa-solid fa-brain"></i>
            </div>
            <div>
              <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Zenith Insight</p>
              <p className="text-xs text-slate-600 leading-tight">
                {isVeteran
                  ? "Systems stable. You've hit peak metabolic flow 4 days this week. Suggesting a 48h 'Restorative Block' to prevent adaptation plateau."
                  : phaseCurrent.description + " Focus on protein."}
              </p>
            </div>
          </div>
        </div>
      </header>

      <DailyBriefing
        user={user}
        program={(activeEnrollment ? programs.find(p => p.id === activeEnrollment.program_id) : null) || defaultProgram || programs[0]}
        stats={stats}
      />

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {/* Cycle Phase Card */}
        <div className="bg-slate-900 p-8 rounded-[2.5rem] text-white relative overflow-hidden group">
          <div className="relative z-10">
            <p className={`font-bold uppercase tracking-widest text-[10px] mb-4 ${phaseCurrent.color}`}>Cycle Phase</p>
            <div className="flex items-baseline gap-2">
              <h3 className="text-2xl font-black">{cycleStats ? phaseCurrent.label : 'No Data'}</h3>
            </div>
            {cycleStats && (
              <p className="text-xs text-slate-400 mt-2 font-medium">Day {cycleStats.dayOfCycle} of {cycleStats.averageCycleLength}</p>
            )}
            {!cycleStats && (
              <p className="text-xs text-slate-400 mt-2 font-medium">Log period to track</p>
            )}
          </div>
          <i className={`fa-solid ${phaseCurrent.icon} absolute -bottom-4 -right-4 text-8xl text-white/5 pointer-events-none group-hover:rotate-12 transition-transform`}></i>
        </div>

        {/* Next Period Card */}
        <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm relative group overflow-hidden">
          <p className="font-bold text-slate-400 uppercase tracking-widest text-[10px] mb-4">Next Period</p>
          <div className="flex items-baseline gap-1">
            <p className="text-4xl font-black text-slate-900">{cycleStats ? getDaysUntil() : '--'}</p>
            <span className="text-slate-400 font-bold text-sm">days</span>
          </div>
          <div className="h-2 w-full bg-slate-100 rounded-full mt-6 overflow-hidden">
            <div className="h-full bg-rose-500 transition-all duration-1000" style={{ width: cycleStats ? `${(cycleStats.dayOfCycle / cycleStats.averageCycleLength) * 100}%` : '0%' }}></div>
          </div>
          <p className="text-[10px] font-black text-rose-500 mt-3 uppercase tracking-tighter">
            {cycleStats ? 'Prediction Active' : 'Needs Data'}
          </p>
        </div>

        {/* Fertility Status Card */}
        <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm group">
          <p className="font-bold text-slate-400 uppercase tracking-widest text-[10px] mb-4">Fertility Status</p>
          <div className="flex items-center gap-3">
            <div className={`w-12 h-12 rounded-2xl flex items-center justify-center text-xl ${cycleStats?.currentPhase === 'ovulation' ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-50 text-slate-300'
              }`}>
              <i className="fa-solid fa-person-breastfeeding"></i>
            </div>
            <div>
              <p className="font-black text-slate-900 text-lg">
                {cycleStats?.currentPhase === 'ovulation' ? 'High' : 'Low'}
              </p>
              <p className="text-xs text-slate-400 font-bold">Chance</p>
            </div>
          </div>
          <p className="text-[9px] text-slate-400 mt-4 leading-relaxed">
            From Day {cycleStats?.fertileWindowStart ? new Date(cycleStats.fertileWindowStart).getDate() : '?'} to {cycleStats?.fertileWindowEnd ? new Date(cycleStats.fertileWindowEnd).getDate() : '?'}
          </p>
        </div>

        {/* Mood Card */}
        <div className="bg-indigo-50 p-8 rounded-[2.5rem] border border-indigo-100 flex flex-col justify-between">
          <div>
            <p className="font-bold text-indigo-400 uppercase tracking-widest text-[10px] mb-4">Biomarker: Mood</p>
            <p className="text-5xl">{moodEmojis[currentMood] || '👋'}</p>
          </div>
          <button
            onClick={() => onMoodCheckIn('happy')}
            className="text-[10px] font-black text-indigo-600 uppercase tracking-widest mt-6 hover:translate-x-1 transition-transform flex items-center gap-2"
          >
            Log Mood <i className="fa-solid fa-arrow-right"></i>
          </button>
        </div>
      </div>

      {/* Feature Widgets Row */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        {/* AI Greeting Widget */}
        <div className="bg-gradient-to-br from-indigo-600 to-purple-600 p-6 rounded-[2rem] text-white relative overflow-hidden lg:col-span-2">
          <div className="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -mr-16 -mt-16"></div>
          <div className="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full -ml-12 -mb-12"></div>
          <div className="relative z-10">
            <div className="flex items-center gap-2 mb-3">
              <div className="w-8 h-8 bg-white/20 rounded-xl flex items-center justify-center">
                <i className="fa-solid fa-wand-magic-sparkles text-sm"></i>
              </div>
              <p className="text-[10px] font-bold uppercase tracking-widest text-white/70">Zenith Smart</p>
            </div>
            <p className="text-lg font-bold leading-relaxed">
              {smartGreeting || `Good ${new Date().getHours() < 12 ? 'morning' : new Date().getHours() < 17 ? 'afternoon' : 'evening'}! Ready to optimize your wellness today?`}
            </p>
          </div>
        </div>

        {/* Goals Widget */}
        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm group hover:shadow-md transition-all">
          <div className="flex items-center justify-between mb-4">
            <div className="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center text-emerald-600">
              <i className="fa-solid fa-bullseye"></i>
            </div>
            <div className="text-right">
              <p className="text-2xl font-black text-slate-900">{goalStats?.active_goals || 0}</p>
              <p className="text-[10px] font-bold text-slate-400 uppercase">Active Goals</p>
            </div>
          </div>
          <div className="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden mb-3">
            <div className="h-full bg-emerald-500 transition-all duration-700" style={{ width: `${goalStats?.completion_rate || 0}%` }}></div>
          </div>
          <button
            onClick={() => onNavigate?.(AppTab.GOALS)}
            className="text-[10px] font-black text-emerald-600 uppercase tracking-widest hover:translate-x-1 transition-transform flex items-center gap-2"
          >
            View Goals <i className="fa-solid fa-arrow-right"></i>
          </button>
        </div>

        {/* Habits Widget */}
        <div className="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm group hover:shadow-md transition-all">
          <div className="flex items-center justify-between mb-4">
            <div className="w-10 h-10 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600">
              <i className="fa-solid fa-fire"></i>
            </div>
            <div className="text-right">
              <p className="text-2xl font-black text-slate-900">{habitStats?.today_completed || 0}/{habitStats?.active_habits || 0}</p>
              <p className="text-[10px] font-bold text-slate-400 uppercase">Today</p>
            </div>
          </div>
          <div className="flex items-center gap-2 mb-3">
            <i className="fa-solid fa-bolt text-amber-500 text-xs"></i>
            <p className="text-xs font-bold text-slate-600">{habitStats?.best_streak || 0} day best streak</p>
          </div>
          <button
            onClick={() => onNavigate?.(AppTab.HABITS)}
            className="text-[10px] font-black text-amber-600 uppercase tracking-widest hover:translate-x-1 transition-transform flex items-center gap-2"
          >
            Track Habits <i className="fa-solid fa-arrow-right"></i>
          </button>
        </div>
      </div>

      {/* Gamification & Leaderboard Row */}
      <div className="bg-gradient-to-r from-slate-900 to-slate-800 p-6 md:p-8 rounded-[2.5rem] relative overflow-hidden">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(99,102,241,0.15),transparent_50%)]"></div>
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_70%_80%,rgba(168,85,247,0.15),transparent_50%)]"></div>

        <div className="relative z-10 flex flex-col md:flex-row items-center justify-between gap-6">
          <div className="flex items-center gap-6">
            {/* Level Badge */}
            <div className="relative">
              <div className="w-20 h-20 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-500/30">
                <span className="text-3xl font-black text-white">{gamificationData?.level || 1}</span>
              </div>
              <div className="absolute -top-2 -right-2 w-8 h-8 bg-amber-400 rounded-lg flex items-center justify-center shadow-md">
                <i className="fa-solid fa-crown text-white text-sm"></i>
              </div>
            </div>

            <div>
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">XP Progress</p>
              <p className="text-2xl font-black text-white">{gamificationData?.xp?.toLocaleString() || 0} XP</p>
              <div className="h-2 w-40 bg-slate-700 rounded-full mt-2 overflow-hidden">
                <div className="h-full bg-gradient-to-r from-indigo-500 to-purple-500" style={{ width: `${(gamificationData?.xp || 0) % 1000 / 10}%` }}></div>
              </div>
            </div>
          </div>

          {/* Global Rank */}
          <div className="flex items-center gap-4">
            <div className="text-center px-6 py-3 bg-white/5 rounded-2xl border border-white/10">
              <p className="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1">Global Rank</p>
              <p className="text-3xl font-black text-white">#{gamificationData?.rank || '--'}</p>
            </div>
            <button
              onClick={() => onNavigate?.(AppTab.GAMIFICATION)}
              className="w-12 h-12 bg-indigo-600 rounded-xl flex items-center justify-center text-white hover:bg-indigo-500 transition-all shadow-lg shadow-indigo-600/30"
            >
              <i className="fa-solid fa-trophy"></i>
            </button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2 bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm">
          <h3 className="text-xl font-bold text-slate-800 mb-8">Performance Convergence</h3>
          <div className="h-80 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={combinedData}>
                <defs>
                  <linearGradient id="colorFocus" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#4f46e5" stopOpacity={0.15} />
                    <stop offset="95%" stopColor="#4f46e5" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                <XAxis dataKey="day" axisLine={false} tickLine={false} tick={{ fill: '#94a3b8', fontSize: 10, fontWeight: 700 }} dy={15} />
                <YAxis hide />
                <Tooltip />
                <Area type="monotone" dataKey="focus" stroke="#4f46e5" strokeWidth={4} fillOpacity={1} fill="url(#colorFocus)" />
                <Line type="monotone" dataKey="wellness" stroke="#10b981" strokeWidth={3} dot={{ r: 4, fill: '#10b981' }} />
              </AreaChart>
            </ResponsiveContainer>
          </div>

          <div className="mt-6 bg-indigo-50 rounded-2xl p-5 flex items-start gap-3 border border-indigo-100">
            <div className="w-8 h-8 rounded-full bg-white flex items-center justify-center text-indigo-500 shadow-sm shrink-0">
              <i className="fa-solid fa-wand-magic-sparkles text-sm"></i>
            </div>
            <div>
              <p className="text-[10px] font-black uppercase text-indigo-400 tracking-widest mb-1">Zenith Smart Analysis</p>
              <p className="text-sm font-bold text-slate-700 leading-relaxed">{correlationInsight}</p>
            </div>
          </div>
        </div>

        <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm flex flex-col">
          <h3 className="text-xl font-bold text-slate-800 mb-6">Cohort Roadmap</h3>
          <div className="space-y-6 flex-1 overflow-y-auto pr-2">
            {[
              { day: 'Day 1-3', title: 'Metabolic Priming', status: 'complete', icon: 'fa-check' },
              { day: 'Day 4-7', title: 'Fat Adaptation', status: 'active', icon: 'fa-fire' },
              { day: 'Day 8-14', title: 'Cognitive Peak', status: 'locked', icon: 'fa-brain' },
              { day: 'Day 15-21', title: 'Sustained Flow', status: 'locked', icon: 'fa-infinity' },
            ].map((step, idx) => (
              <div key={idx} className={`flex items-start gap-4 relative ${step.status === 'locked' ? 'opacity-40' : ''}`}>
                {idx < 3 && <div className="absolute left-5 top-10 w-[2px] h-8 bg-slate-100"></div>}
                <div className={`w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0 border-2 ${step.status === 'complete' ? 'bg-emerald-50 border-emerald-500 text-emerald-500' :
                  step.status === 'active' ? 'bg-indigo-600 border-indigo-600 text-white shadow-lg shadow-indigo-100 animate-pulse' :
                    'bg-white border-slate-100 text-slate-300'
                  }`}>
                  <i className={`fa-solid ${step.icon} text-sm`}></i>
                </div>
                <div>
                  <p className="text-[10px] font-black uppercase text-slate-400 tracking-widest">{step.day}</p>
                  <h4 className="font-bold text-slate-800 text-sm">{step.title}</h4>
                </div>
              </div>
            ))}
          </div>

          <div className="mt-8 p-6 bg-slate-50 rounded-3xl border border-slate-100 group cursor-pointer hover:bg-slate-100 transition-colors">
            <div className="flex items-center gap-3 mb-3">
              <i className="fa-solid fa-crown text-amber-500"></i>
              <span className="text-[10px] font-black uppercase text-amber-600 tracking-widest">Next Level</span>
            </div>
            <p className="text-xs font-bold text-slate-800 mb-1">Unlock "Skin Deep" Protocol</p>
            <p className="text-[10px] text-slate-500">Combine reset with Acne Defense for 40% off.</p>
          </div>
        </div>
      </div>

      {userRole === 'admin' && (
        <div className="fixed bottom-24 md:bottom-8 right-6 md:right-8 z-40">
          <button
            onClick={() => setIsModalOpen(true)}
            className="w-16 h-16 bg-slate-900 text-white rounded-full flex items-center justify-center shadow-2xl hover:scale-110 active:scale-95 transition-all group"
          >
            <i className="fa-solid fa-plus text-xl group-hover:rotate-90 transition-transform"></i>
          </button>
        </div>
      )}

      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in duration-300">
          <div className="bg-white w-full max-w-lg rounded-[3rem] shadow-2xl border border-white/20 overflow-hidden">
            <div className="p-10 border-b border-slate-50">
              <h2 className="text-3xl font-black text-slate-900">Adjust Protocol</h2>
            </div>
            <div className="p-10 space-y-8">
              <div className="space-y-4">
                <div className="flex justify-between items-center">
                  <label className="text-[10px] font-black uppercase text-slate-400 tracking-widest">Deep Work Block (m)</label>
                  <span className="text-sm font-black text-indigo-600">{tempGoals.focusMinutes}m</span>
                </div>
                <input
                  type="range" min="30" max="480" step="15"
                  value={tempGoals.focusMinutes}
                  onChange={e => setTempGoals({ ...tempGoals, focusMinutes: +e.target.value })}
                  className="w-full h-2 bg-slate-100 rounded-full appearance-none accent-indigo-600"
                />
              </div>
            </div>
            <div className="p-8 bg-slate-50 flex gap-4">
              <button onClick={() => setIsModalOpen(false)} className="flex-1 py-4 font-bold text-slate-500">Close</button>
              <button
                onClick={() => { onUpdateGoals(tempGoals); setIsModalOpen(false); }}
                className="flex-1 py-4 bg-indigo-600 text-white rounded-2xl font-black shadow-xl"
              >
                Apply Strategy
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Video Modal */}
      {showVideoModal && (
        <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4" onClick={() => setShowVideoModal(false)}>
          <div className="bg-white rounded-3xl overflow-hidden max-w-2xl w-full shadow-2xl" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between p-6 border-b border-slate-100">
              <h3 className="text-xl font-black text-slate-900">Onboarding Video</h3>
              <button onClick={() => setShowVideoModal(false)} className="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center hover:bg-slate-200">
                <i className="fa-solid fa-times text-slate-600"></i>
              </button>
            </div>
            <div className="p-4">
              <VideoPlayer
                src="https://assets.mixkit.co/videos/preview/mixkit-woman-doing-yoga-outdoors-1560-large.mp4"
                poster="https://images.unsplash.com/photo-1544367567-0f2fcb009e0b?auto=format&fit=crop&q=80&w=1000"
                duration="5min"
              />
            </div>
            <div className="p-6">
              <p className="text-slate-500 text-sm">This onboarding video will guide you through the program structure, expectations, and how to get the most out of your metabolic reset journey.</p>
            </div>
          </div>
        </div>
      )}

      {/* Prep Guide Modal */}
      {showPrepGuideModal && (
        <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4" onClick={() => setShowPrepGuideModal(false)}>
          <div className="bg-white rounded-3xl overflow-hidden max-w-2xl w-full shadow-2xl max-h-[85vh] overflow-y-auto" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between p-6 border-b border-slate-100 sticky top-0 bg-white z-10">
              <div>
                <h3 className="text-xl font-black text-slate-900">Your Prep Guide</h3>
                <p className="text-xs text-slate-400 font-bold">7-Day Metabolic Priming Plan</p>
              </div>
              <button onClick={() => setShowPrepGuideModal(false)} className="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center hover:bg-slate-200">
                <i className="fa-solid fa-times text-slate-600"></i>
              </button>
            </div>
            <div className="p-8">
              {downloadingGuide ? (
                <div className="flex flex-col items-center justify-center py-16">
                  <div className="w-12 h-12 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin mb-4"></div>
                  <p className="text-sm font-bold text-slate-500">Generating your personalized prep guide...</p>
                </div>
              ) : prepGuide ? (
                <div className="space-y-8">
                  <div className="bg-indigo-50 p-6 rounded-2xl border border-indigo-100">
                    <p className="text-[10px] font-black text-indigo-400 uppercase tracking-widest mb-2">Overview</p>
                    <p className="text-slate-800 font-medium leading-relaxed">{prepGuide.overview}</p>
                  </div>

                  {prepGuide.dailyTasks?.length > 0 && (
                    <div>
                      <h4 className="text-sm font-black text-slate-900 uppercase tracking-wider mb-4">Daily Tasks</h4>
                      <div className="space-y-4">
                        {prepGuide.dailyTasks.map((day: any, i: number) => (
                          <div key={i} className="bg-slate-50 p-5 rounded-2xl border border-slate-100">
                            <div className="flex items-center gap-3 mb-3">
                              <span className="w-8 h-8 bg-indigo-600 text-white rounded-xl flex items-center justify-center text-xs font-black">{day.day}</span>
                              <span className="font-bold text-slate-800">{day.title}</span>
                            </div>
                            <ul className="space-y-1.5 ml-11">
                              {day.tasks?.map((task: string, j: number) => (
                                <li key={j} className="text-sm text-slate-600 flex items-start gap-2">
                                  <i className="fa-solid fa-circle-check text-slate-300 text-xs mt-1 flex-shrink-0"></i>
                                  {task}
                                </li>
                              ))}
                            </ul>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {prepGuide.shoppingList?.length > 0 && (
                    <div>
                      <h4 className="text-sm font-black text-slate-900 uppercase tracking-wider mb-4">Shopping List</h4>
                      <div className="flex flex-wrap gap-2">
                        {prepGuide.shoppingList.map((item: string, i: number) => (
                          <span key={i} className="px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold border border-emerald-100">{item}</span>
                        ))}
                      </div>
                    </div>
                  )}

                  {prepGuide.tips?.length > 0 && (
                    <div>
                      <h4 className="text-sm font-black text-slate-900 uppercase tracking-wider mb-4">Tips</h4>
                      <ul className="space-y-2">
                        {prepGuide.tips.map((tip: string, i: number) => (
                          <li key={i} className="text-sm text-slate-600 flex items-start gap-2">
                            <i className="fa-solid fa-lightbulb text-amber-400 mt-0.5 flex-shrink-0"></i>
                            {tip}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}

                  {prepGuide.weeklyGoals?.length > 0 && (
                    <div>
                      <h4 className="text-sm font-black text-slate-900 uppercase tracking-wider mb-4">Weekly Goals</h4>
                      <div className="space-y-2">
                        {prepGuide.weeklyGoals.map((goal: string, i: number) => (
                          <div key={i} className="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100">
                            <i className="fa-solid fa-bullseye text-indigo-500"></i>
                            <span className="text-sm font-bold text-slate-700">{goal}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              ) : (
                <div className="py-12 text-center">
                  <div className="w-16 h-16 bg-amber-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i className="fa-solid fa-triangle-exclamation text-amber-500 text-2xl"></i>
                  </div>
                  <p className="text-slate-500 font-bold">Could not generate prep guide. Please try again.</p>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Quick Actions FAB */}
      <div className="fixed bottom-24 right-6 z-40">
        {/* Expanded Menu */}
        {showQuickActions && (
          <div className="absolute bottom-16 right-0 flex flex-col gap-3 animate-in slide-in-from-bottom-4 duration-300">
            <button
              onClick={() => { onNavigate?.(AppTab.MEAL_TRACKER); setShowQuickActions(false); }}
              className="w-12 h-12 bg-green-600 text-white rounded-2xl shadow-lg hover:scale-110 transition-transform flex items-center justify-center group"
              title="Log Meal"
            >
              <i className="fa-solid fa-utensils"></i>
            </button>
            <button
              onClick={() => { onNavigate?.(AppTab.WELLNESS); setShowQuickActions(false); }}
              className="w-12 h-12 bg-cyan-600 text-white rounded-2xl shadow-lg hover:scale-110 transition-transform flex items-center justify-center"
              title="Log Wellness"
            >
              <i className="fa-solid fa-droplet"></i>
            </button>
            <button
              onClick={() => { onMoodCheckIn('happy'); setShowQuickActions(false); }}
              className="w-12 h-12 bg-amber-600 text-white rounded-2xl shadow-lg hover:scale-110 transition-transform flex items-center justify-center"
              title="Log Mood"
            >
              <i className="fa-solid fa-face-smile"></i>
            </button>
            <button
              onClick={() => { onNavigate?.(AppTab.HABITS); setShowQuickActions(false); }}
              className="w-12 h-12 bg-purple-600 text-white rounded-2xl shadow-lg hover:scale-110 transition-transform flex items-center justify-center"
              title="Check Habits"
            >
              <i className="fa-solid fa-check"></i>
            </button>
          </div>
        )}

        {/* Main FAB Button */}
        <button
          onClick={() => setShowQuickActions(!showQuickActions)}
          className={`w-14 h-14 rounded-2xl shadow-xl flex items-center justify-center text-white text-xl transition-all duration-300 ${showQuickActions
            ? 'bg-slate-800 rotate-45'
            : 'bg-gradient-to-br from-indigo-600 to-purple-600 hover:scale-110'
            }`}
        >
          <i className="fa-solid fa-plus"></i>
        </button>
      </div>
    </div>
  );
};

export default Dashboard;
