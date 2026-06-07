
import React, { useState, useEffect } from 'react';
import ErrorBoundary from './components/ErrorBoundary';
import { AppTab, UserStats, Mood, DailyGoals, Program, UserPersona, User } from './types';
import Layout from './components/Layout';
import Dashboard from './components/Dashboard';
import CoachPanel from './components/CoachPanel';
import ChallengeHub from './components/ChallengeHub';
import MealTracker from './components/MealTracker';
import Login from './components/Login';
import CohortLounge from './components/CohortLounge';
import CycleTracker from './components/CycleTracker';
import Resources from './components/Resources';
import AdminPanel from './components/AdminPanel';
import ProfileSettings from './components/ProfileSettings';
import RoutinesAndHabits from './components/RoutinesAndHabits';
import GoalManager from './components/GoalManager';
import Journal from './components/Journal';
import WellnessLogger from './components/WellnessLogger';
import GamificationHub from './components/GamificationHub';
import NotificationCenter from './components/NotificationCenter';
import MobileMenu from './components/MobileMenu';
import AIChatWidget from './components/ui/AIChatWidget';
import XPOverlay from './components/ui/XPOverlay';
import { db } from './services/db';
import { api } from './services/api';
import SalesPageTrust from './components/sales/SalesPageTrust';
import SalesPageBioSync from './components/sales/SalesPageBioSync';
import SalesPageCohort from './components/sales/SalesPageCohort';
import QuizFunnel from './components/funnels/QuizFunnel';
import ChallengeLanding from './components/funnels/ChallengeLanding';
import SignupFlow from './components/funnels/SignupFlow';
import { useActivityTracker } from './hooks/useActivityTracker';

// Fallback programs if API fails
const FALLBACK_PROGRAMS: Program[] = [
  {
    id: 'prod_1',
    title: 'Productivity Power',
    description: 'Master deep work protocols.',
    startDate: '2023-01-01',
    category: 'productivity',
    image: 'https://picsum.photos/seed/prod/400/300',
    objectives: ['Deep Work Flow', 'Email Management', 'Peak Cognitive Window'],
    price: 8900,
    duration: 21
  },
  {
    id: 'weight_1',
    title: 'Metabolic Reset',
    description: 'Next cohort starts soon.',
    startDate: new Date(Date.now() + 1036800000).toISOString(),
    category: 'wellness',
    image: 'https://picsum.photos/seed/weight/400/300',
    objectives: ['Basal Metabolic Rate Optimization', 'Muscle Preservation', 'Fat Oxidation'],
    price: 8900,
    duration: 21
  },
];

// Transform backend cohort data to frontend Program format
const transformCohortToProgram = (cohort: any): Program => ({
  id: cohort.id,
  title: cohort.title,
  description: cohort.description || '',
  startDate: cohort.start_date,
  endDate: cohort.end_date,
  category: cohort.category || 'wellness',
  image: cohort.image_url || cohort.image || `https://picsum.photos/seed/${cohort.id}/400/300`,
  objectives: cohort.objectives ? (typeof cohort.objectives === 'string' ? JSON.parse(cohort.objectives) : cohort.objectives) : [],
  price: cohort.price || 8900,
  duration: cohort.duration_days || 21,
  maxParticipants: cohort.max_participants || 100,
  participantCount: cohort.participant_count || 0,
  isPublic: cohort.is_public === 1 || cohort.is_public === true
});

const DEFAULT_STATS: UserStats = {
  focusMinutes: 120,
  completedTasks: 8,
  moodHistory: [{ date: new Date().toISOString(), mood: 'happy' }],
  dailyStreak: 5,
  goals: { focusMinutes: 180, completedTasks: 12, targetMood: 'ecstatic' },
  purchasedProgramIds: ['prod_1'],
  macros: { protein: 45, carbs: 120, fats: 30, calories: 1250 },
  cohortProgress: {}
};

const App: React.FC = () => {
  const [user, setUser] = useState<User | null>(null);
  const [activeTab, setActiveTab] = useState<AppTab>(AppTab.DASHBOARD);
  const [stats, setStats] = useState<UserStats>(DEFAULT_STATS);
  const [activeCohortId, setActiveCohortId] = useState<string | null>(null);
  const [programs, setPrograms] = useState<Program[]>(FALLBACK_PROGRAMS);
  const [programsLoading, setProgramsLoading] = useState(true);

  // URL-based variant detection for direct links (e.g., /#signup, /#quiz)
  const getInitialVariant = (): 'login' | 'trust' | 'biosync' | 'cohort' | 'quiz' | 'challenge' | 'signup' => {
    const hash = window.location.hash.replace('#', '');
    const validVariants = ['login', 'trust', 'biosync', 'cohort', 'quiz', 'challenge', 'signup'];
    if (validVariants.includes(hash)) {
      return hash as any;
    }
    return 'trust'; // Default
  };

  const [landingVariant, setLandingVariant] = useState<'login' | 'trust' | 'biosync' | 'cohort' | 'quiz' | 'challenge' | 'signup'>(getInitialVariant);

  useActivityTracker(activeTab, user?.id);

  // Fetch cohorts from API
  const fetchCohorts = async () => {
    try {
      const response = await api.get<{ cohorts: any[] }>('/cohorts');
      if (response.cohorts && response.cohorts.length > 0) {
        const transformedPrograms = response.cohorts.map(transformCohortToProgram);
        setPrograms(transformedPrograms);
      }
    } catch (err) {
      console.warn('Failed to fetch cohorts, using fallback:', err);
      // Keep using FALLBACK_PROGRAMS
    } finally {
      setProgramsLoading(false);
    }
  };

  // Initial fetch on mount
  useEffect(() => {
    fetchCohorts();
  }, []);

  // Refetch when navigating to Challenges tab (to get newly created cohorts)
  useEffect(() => {
    if (activeTab === AppTab.CHALLENGES && user) {
      fetchCohorts();
    }
  }, [activeTab]);

  useEffect(() => {
    const session = db.getSession();
    if (session) {
      setUser(session);
      db.getStats(session.id).then(savedStats => {
        if (savedStats) setStats(savedStats);
      });
    }
  }, []);

  useEffect(() => {
    if (user) {
      db.saveStats(user.id, stats).catch(console.error);
    }
  }, [stats, user]);

  const handleLogout = () => {
    db.clearSession();
    setUser(null);
    setLandingVariant('login'); // Show login page for returning users
  };

  const handleSwitchToLogin = () => {
    setLandingVariant('login');
  };

  if (!user) {
    return (
      <>
        {landingVariant === 'login' && <Login onLogin={setUser} onSwitchToSales={() => setLandingVariant('trust')} />}
        {landingVariant === 'trust' && <SalesPageTrust onLogin={setUser} onSwitchToLogin={handleSwitchToLogin} />}
        {landingVariant === 'biosync' && <SalesPageBioSync onLogin={setUser} onSwitchToLogin={handleSwitchToLogin} />}
        {landingVariant === 'cohort' && <SalesPageCohort onLogin={setUser} onSwitchToLogin={handleSwitchToLogin} />}
        {landingVariant === 'quiz' && <QuizFunnel onComplete={(result, email) => console.log('Quiz completed:', result, email)} onLogin={setUser} />}
        {landingVariant === 'challenge' && <ChallengeLanding onRegister={setUser} onSwitchToLogin={handleSwitchToLogin} />}
        {landingVariant === 'signup' && <SignupFlow onComplete={setUser} onSwitchToLogin={handleSwitchToLogin} />}

        {/* Variant switcher for development only */}
        {import.meta.env.DEV && (
          <div className="fixed bottom-4 left-4 z-50 flex gap-2 bg-black/80 p-2 rounded-lg backdrop-blur-sm">
            <button onClick={() => setLandingVariant('signup')} className={`px-3 py-1 rounded text-xs font-bold ${landingVariant === 'signup' ? 'bg-teal-500 text-white' : 'text-teal-400'}`}>Signup</button>
            <button onClick={() => setLandingVariant('quiz')} className={`px-3 py-1 rounded text-xs font-bold ${landingVariant === 'quiz' ? 'bg-purple-500 text-white' : 'text-purple-400'}`}>Quiz</button>
            <button onClick={() => setLandingVariant('challenge')} className={`px-3 py-1 rounded text-xs font-bold ${landingVariant === 'challenge' ? 'bg-emerald-500 text-white' : 'text-emerald-400'}`}>Challenge</button>
            <button onClick={() => setLandingVariant('trust')} className={`px-3 py-1 rounded text-xs font-bold ${landingVariant === 'trust' ? 'bg-blue-500 text-white' : 'text-blue-400'}`}>Trust</button>
            <button onClick={() => setLandingVariant('login')} className={`px-3 py-1 rounded text-xs font-bold ${landingVariant === 'login' ? 'bg-white text-black' : 'text-white'}`}>Login</button>
          </div>
        )}
      </>
    );
  }

  const handleMoodCheckIn = (mood: Mood) => {
    setStats(prev => ({
      ...prev,
      moodHistory: [...prev.moodHistory, { date: new Date().toISOString(), mood }]
    }));
  };

  const handleUpdateGoals = (newGoals: DailyGoals) => {
    setStats(prev => ({ ...prev, goals: newGoals }));
  };

  const handleMealLogged = (macros: any) => {
    setStats(prev => ({
      ...prev,
      macros: {
        protein: prev.macros.protein + macros.protein,
        carbs: prev.macros.carbs + macros.carbs,
        fats: prev.macros.fats + macros.fats,
        calories: prev.macros.calories + macros.calories,
      }
    }));
  };

  const handleTogglePersona = (p: UserPersona) => {
    let purchased = ['prod_1'];
    if (p === 'lead') purchased = [];
    if (p === 'newbie') purchased = ['weight_1'];
    if (p === 'active') purchased = ['prod_1'];
    if (p === 'veteran') purchased = ['prod_1', 'skin_1', 'pcos_1'];

    db.updateUserPersona(user.email, p);
    const updatedUser = db.getSession();
    if (updatedUser) {
      setUser(updatedUser);
      setStats(prev => ({
        ...prev,
        purchasedProgramIds: purchased
      }));
    }
  };

  const handleOpenCohort = (id: string) => {
    setActiveCohortId(id);
    setActiveTab(AppTab.COHORT_LOUNGE);
  };

  return (
    <Layout
      activeTab={activeTab}
      setActiveTab={setActiveTab}
      user={user}
      stats={stats}
      programs={programs}
      onLogout={handleLogout}
    >
      <ErrorBoundary>
        {/* Global Notifications */}
        <div className="absolute top-4 right-4 z-40 md:top-8 md:right-8">
          <NotificationCenter user={user} />
        </div>

        <div className="space-y-8 pb-20">
          {/* Protocol Override - Admin Only Debug Tool */}
          {activeTab !== AppTab.COHORT_LOUNGE && user.role === 'admin' && (
            <div className="flex items-center gap-2 bg-amber-50 p-2 rounded-2xl border border-amber-200 shadow-sm w-fit mb-4 mx-auto md:mx-0">
              <span className="text-[9px] font-black uppercase text-amber-600 px-2 tracking-widest">
                <i className="fa-solid fa-flask mr-1"></i>Debug Mode:
              </span>
              {(['lead', 'newbie', 'active', 'veteran'] as UserPersona[]).map(p => (
                <button
                  key={p}
                  onClick={() => handleTogglePersona(p)}
                  className={`px-3 py-1 rounded-xl text-[10px] font-bold uppercase transition-all ${user.persona === p ? 'bg-amber-600 text-white shadow-md' : 'text-amber-700 hover:bg-amber-100'}`}
                >
                  {p}
                </button>
              ))}
            </div>
          )}

          <div className="animate-in fade-in duration-500">
            {activeTab === AppTab.DASHBOARD && (
              <Dashboard
                user={user}
                stats={{ ...stats, persona: user.persona }}
                onMoodCheckIn={handleMoodCheckIn}
                onUpdateGoals={handleUpdateGoals}
                onNavigate={setActiveTab}
                onOpenPayment={(program) => {
                  setActiveTab(AppTab.CHALLENGES);
                }}
                defaultProgram={programs.find(p => p.id === 'weight_1') || programs[0]}
                programs={programs}
                userRole={user.role}
              />
            )}

            {activeTab === AppTab.CHALLENGES && (
              <ChallengeHub
                userId={user.id}
                userEmail={user.email}
                userName={user.name}
                programs={programs}
                stats={{ ...stats, persona: user.persona }}
                onOpenCohort={handleOpenCohort}
                onPurchaseSuccess={() => db.getStats(user.id).then(s => s && setStats(s))}
              />
            )}

            {activeTab === AppTab.MEAL_TRACKER && (
              <MealTracker
                userId={user.id}
                stats={{ ...stats, persona: user.persona }}
                onMealLogged={handleMealLogged}
              />
            )}

            {activeTab === AppTab.COACH && (
              <CoachPanel user={user} stats={{ ...stats, persona: user.persona }} />
            )}

            {activeTab === AppTab.COHORT_LOUNGE && activeCohortId && (
              <CohortLounge
                user={user}
                stats={stats}
                program={programs.find(p => p.id === activeCohortId)!}
                onClose={() => setActiveTab(AppTab.CHALLENGES)}
              />
            )}

            {activeTab === AppTab.ROUTINES && (
              <RoutinesAndHabits
                user={user}
                currentCyclePhase={stats.cycleStats?.currentPhase}
              />
            )}

            {activeTab === AppTab.GOALS && (
              <GoalManager user={user} onNavigate={setActiveTab} />
            )}

            {activeTab === AppTab.JOURNAL && (
              <Journal user={user} />
            )}

            {activeTab === AppTab.WELLNESS && (
              <WellnessLogger user={user} />
            )}

            {activeTab === AppTab.GAMIFICATION && (
              <GamificationHub user={user} />
            )}

            {activeTab === AppTab.CYCLE_TRACKER && (
              <CycleTracker userId={user.id} />
            )}

            {activeTab === AppTab.MENU && (
              <MobileMenu onNavigate={setActiveTab} userRole={user.role} />
            )}

            {activeTab === AppTab.RESOURCES && (
              <Resources userId={user.id} stats={{ ...stats, persona: user.persona }} />
            )}

            {activeTab === AppTab.ADMIN && user.role === 'admin' && (
              <AdminPanel />
            )}

            {activeTab === AppTab.SETTINGS && (
              <ProfileSettings
                user={user}
                onClose={() => setActiveTab(AppTab.DASHBOARD)}
                onLogout={handleLogout}
                onUserUpdate={(updatedUser) => setUser(updatedUser)}
              />
            )}
          </div>
        </div>
      </ErrorBoundary>
      <AIChatWidget
        userId={user.id}
        isFreeTier={user.persona !== 'active' && user.persona !== 'veteran'}
        onUpgrade={() => setActiveTab(AppTab.CHALLENGES)}
      />
      <XPOverlay />
    </Layout>
  );
};

export default App;
