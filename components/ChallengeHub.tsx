import React, { useState, useMemo } from 'react';
import { Program, UserStats } from '../types';
import Card from './ui/Card';
import Button from './ui/Button';
import PaymentModal from './PaymentModal';
import CountdownTimer from './ui/CountdownTimer';
import SpotsRemaining from './ui/SpotsRemaining';
import LiveActivityFeed from './ui/LiveActivityFeed';
import TestimonialCarousel from './ui/TestimonialCarousel';
import ResultsGallery from './ui/ResultsGallery';
import SalesPagePCOS from './sales/SalesPagePCOS';
import SalesPageExecutive from './sales/SalesPageExecutive';
import SalesPageAesthetic from './sales/SalesPageAesthetic';
import SalesPageTrust from './sales/SalesPageTrust';
// BioSync and Cohort typically map to general pages, assuming Trust is Weight Loss (Metabolic)

interface ChallengeHubProps {
  userId: string;
  userEmail?: string;
  userName?: string;
  programs: Program[];
  stats: UserStats;
  onOpenCohort: (id: string) => void;
  onPurchaseSuccess?: () => void;
}

const ChallengeHub: React.FC<ChallengeHubProps> = ({ userId, userEmail, userName, programs, stats, onOpenCohort, onPurchaseSuccess }) => {
  const [upsellProgram, setUpsellProgram] = useState<Program | null>(null);
  const [isPaymentOpen, setIsPaymentOpen] = useState(false);
  const [_showAllChallenges, setShowAllChallenges] = useState(false);

  // Initialize a mock deadline for the "offer expires" timer (48 hours from now)
  const deadline = useMemo(() => new Date(Date.now() + 48 * 60 * 60 * 1000), []);

  const isCohortActive = (startDate: string) => {
    const now = new Date();
    const start = new Date(startDate);
    return now >= start;
  };

  const handleProgramClick = (p: Program) => {
    const isPurchased = stats.purchasedProgramIds.includes(p.id);
    if (!isPurchased) {
      setUpsellProgram(p);
    } else if (isCohortActive(p.startDate)) {
      onOpenCohort(p.id);
    }
  };

  const handlePaymentSuccess = () => {
    setIsPaymentOpen(false);
    setUpsellProgram(null);
    if (onPurchaseSuccess) onPurchaseSuccess();
  };

  const getPersonalizedOffer = () => {
    if (!upsellProgram) return null;

    const focusAchievement = stats.focusMinutes / stats.goals.focusMinutes;
    const isCrushingFocus = focusAchievement >= 0.8;
    const isPersonaLead = stats.persona === 'lead';

    let reason = "Unlock your full potential with this protocol.";
    let discount = 30;

    if (isCrushingFocus) {
      reason = `Since you're crushing your focus goals with ${stats.focusMinutes}m today, you've earned a performance bonus!`;
      discount = 45;
    } else if (isPersonaLead) {
      reason = "Welcome to the Zenith ecosystem! Start your first transformation today.";
      discount = 40;
    } else if (stats.dailyStreak > 3) {
      reason = `Maintaining a ${stats.dailyStreak}-day streak is elite. Keep the momentum with this protocol.`;
      discount = 35;
    }

    const originalPrice = upsellProgram.price || 8900; // Use program price from API
    const discountedPrice = Math.floor(originalPrice * (1 - discount / 100));

    return { reason, discount, originalPrice, discountedPrice };
  };

  const offer = getPersonalizedOffer();
  const isFreeLead = stats.persona === 'lead';

  return (
    <div className="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-700 relative">
      <LiveActivityFeed />

      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-3xl font-serif text-stone-900 tracking-tight">Challenge Ecosystem</h2>
          <p className="text-stone-500 mt-1">
            {isFreeLead ? 'Join your first cohort to unlock the Smart Orchestrator.' : 'Access your active and upcoming protocols.'}
          </p>
        </div>
        {isFreeLead && (
          <div className="flex flex-col items-end gap-2">
            <div className="bg-amber-50 text-amber-700 px-6 py-3 rounded-2xl border border-amber-200 shadow-sm font-bold text-xs uppercase tracking-wider flex items-center gap-3">
              <span>LIMITED TIME: 40% OFF FIRST CHALLENGE</span>
              <div className="h-4 w-[1px] bg-amber-200"></div>
              <CountdownTimer targetDate={deadline} size="sm" variant="inline" />
            </div>
            <p className="text-[10px] font-bold text-rose-500 uppercase tracking-widest animate-pulse">Offer Expires Soon</p>
          </div>
        )}
      </div>

      <div className="bg-gradient-to-br from-indigo-50 to-white p-8 rounded-3xl border border-indigo-100 relative overflow-hidden">
        <div className="absolute top-0 right-0 p-8 opacity-5">
          <i className="fa-solid fa-users text-9xl text-indigo-900"></i>
        </div>
        <div className="relative z-10 text-center mb-6">
          <h3 className="font-serif text-2xl text-stone-900">Trusted by over 2,000 members</h3>
          <p className="text-stone-500 text-sm">Real results from the Zenith Protocol.</p>
        </div>
        <TestimonialCarousel />
      </div>

      <ResultsGallery />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {programs.map(p => {
          const isPurchased = stats.purchasedProgramIds.includes(p.id);
          const active = isCohortActive(p.startDate);

          return (
            <Card
              key={p.id}
              onClick={() => handleProgramClick(p)}
              className={`!p-0 overflow-hidden flex flex-col group cursor-pointer border-transparent hover:border-brand-200 hover:shadow-xl transition-all duration-500 ${!isPurchased ? 'ring-offset-4 hover:ring-2 ring-brand-100' : ''}`}
            >
              <div className="h-64 relative overflow-hidden">
                <img src={p.image} className="w-full h-full object-cover transition-transform group-hover:scale-105 duration-1000" alt={p.title} />

                <div className="absolute inset-0 bg-gradient-to-t from-stone-900/60 to-transparent"></div>

                {/* Scarcity Badge */}
                {!isPurchased && (
                  <div className="absolute top-4 right-4 z-20">
                    <SpotsRemaining cohortId={p.id} />
                  </div>
                )}

                {!isPurchased && (
                  <div className="absolute inset-0 bg-stone-900/40 backdrop-blur-[2px] flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity p-6 text-center duration-500">
                    <p className="text-white font-serif text-2xl mb-4 italic">Master your biology.</p>
                    <Button variant="primary" className="shadow-2xl !bg-white !text-stone-900 !border-white hover:!bg-stone-50">
                      Unlock Protocol
                    </Button>
                  </div>
                )}

                {isPurchased && (
                  <div className="absolute bottom-6 left-6 right-6">
                    <div className="flex items-center justify-between">
                      <span className={`px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider ${active ? 'bg-emerald-500 text-white shadow-lg shadow-emerald-900/20' : 'bg-white/20 text-white backdrop-blur-md'}`}>
                        {active ? 'Live Now' : 'Starts Soon'}
                      </span>
                      <i className="fa-solid fa-arrow-right text-white opacity-80 group-hover:transform group-hover:translate-x-2 transition-transform duration-300"></i>
                    </div>
                  </div>
                )}

                {!isPurchased && (
                  <div className="absolute bottom-6 left-6 text-white">
                    <p className="font-bold text-lg">{p.title}</p>
                    <p className="text-white/80 text-sm">{p.category}</p>
                  </div>
                )}
              </div>

              <div className="p-6 flex-1 flex flex-col justify-between">
                <div>
                  <h3 className="font-serif text-xl text-stone-900 mb-2">{p.title}</h3>
                  <p className="text-stone-500 text-sm leading-relaxed mb-4">{p.description}</p>

                  <div className="flex flex-wrap gap-2 mb-4">
                    {p.tags?.map(t => (
                      <span key={t} className="px-2 py-1 bg-stone-100 text-stone-600 rounded-md text-[10px] uppercase font-bold tracking-wide">{t}</span>
                    ))}
                  </div>
                </div>

                <div className="flex items-center justify-between border-t border-stone-100 pt-4 mt-2">
                  <div className="flex items-center gap-2 text-stone-400 text-xs">
                    <i className="fa-regular fa-clock"></i>
                    <span>{p.duration}</span>
                  </div>
                  {!isPurchased && (
                    <span className="text-stone-900 font-bold">
                      ${(p.price / 100).toFixed(0)}
                    </span>
                  )}
                </div>
              </div>
            </Card>
          );
        })}
      </div>

      {upsellProgram && !isPaymentOpen && (
        <div className="fixed inset-0 z-[60] bg-white overflow-y-auto animate-in slide-in-from-bottom duration-500">
          <button
            onClick={() => setUpsellProgram(null)}
            className="fixed top-6 right-6 z-[70] w-10 h-10 bg-black/10 hover:bg-black/20 backdrop-blur-md rounded-full flex items-center justify-center text-stone-800 transition-colors"
          >
            <i className="fa-solid fa-xmark"></i>
          </button>

          {upsellProgram.id.startsWith('pcos') && <SalesPagePCOS user={{ ...stats, id: userId } as any} onPurchase={() => setIsPaymentOpen(true)} price={upsellProgram.price} />}
          {upsellProgram.id.startsWith('prod') && <SalesPageExecutive user={{ ...stats, id: userId } as any} onPurchase={() => setIsPaymentOpen(true)} price={upsellProgram.price} />}
          {upsellProgram.id.startsWith('skin') && <SalesPageAesthetic user={{ ...stats, id: userId } as any} onPurchase={() => setIsPaymentOpen(true)} price={upsellProgram.price} />}
          {upsellProgram.id.startsWith('weight') && <SalesPageTrust onLogin={() => { }} onPurchase={() => setIsPaymentOpen(true)} user={{ ...stats, id: userId } as any} price={upsellProgram.price} />}

          {/* Fallback for others */}
          {!['pcos', 'prod', 'skin', 'weight'].some(k => upsellProgram.id.startsWith(k)) && (
            <div className="p-20 text-center">
              <h2 className="text-3xl font-bold">Program Details Coming Soon</h2>
              <Button onClick={() => setIsPaymentOpen(true)} className="mt-8">Proceed to Checkout</Button>
            </div>
          )}
        </div>
      )}

      {isPaymentOpen && upsellProgram && (
        <PaymentModal
          userId={userId}
          userEmail={userEmail}
          userName={userName}
          program={upsellProgram}
          price={upsellProgram.price || offer?.originalPrice || 9900}
          discount={offer?.discount || 0}
          onClose={() => setIsPaymentOpen(false)}
          onSuccess={handlePaymentSuccess}
        />
      )}

      {/* Sticky Bottom CTA for Leads */}
      {isFreeLead && !upsellProgram && (
        <div className="fixed bottom-24 left-1/2 -translate-x-1/2 z-[40] w-full max-w-lg px-4 animate-in slide-in-from-bottom-8 duration-500">
          <div className="bg-stone-900 border border-white/10 p-5 rounded-[2rem] shadow-2xl flex items-center justify-between gap-4">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-brand-500 rounded-2xl flex items-center justify-center text-white text-xl">
                <i className="fa-solid fa-crown"></i>
              </div>
              <div>
                <p className="text-white font-bold text-sm">Join the 2026 Cohort</p>
                <p className="text-stone-400 text-[10px] font-bold uppercase tracking-widest">Early Bird: 40% OFF</p>
              </div>
            </div>
            <Button
              onClick={() => setUpsellProgram(programs[0])}
              className="!bg-white !text-stone-900 !border-white !py-2 !px-6 animate-pulse"
            >
              Join Now
            </Button>
          </div>
        </div>
      )}
    </div>
  );
};

export default ChallengeHub;
