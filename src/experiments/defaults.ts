import type { ExperimentConfigs } from './types';

export const DEFAULT_CONFIGS: ExperimentConfigs = {
  trust_hero_v1: {
    headline: 'Stop Guessing. <br /> <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-400">Start Synchronizing.</span>',
    subhead: 'Most productivity plans fail because they fight your biology. Zenith is the first <strong>Human Performance Operating System</strong> that aligns your work with your metabolic, hormonal, and cognitive cycles.',
  },
  quiz_email_gate_v1: {
    showEmailGate: true,
    gateTitle: 'Almost there, {profile}!',
    gateDescription: 'Enter your details to unlock your personalized protocol.',
    urgencyEnabled: true,
  },
  checkout_layout_v1: {
    layout: 'split',
    showSummaryLeft: true,
    showTestimonial: true,
    showCurrencySelector: true,
  },
  pricing_anchor_v1: {
    showDiscount: true,
    showCompareAt: true,
    badge: '50% Off',
    discountPercent: 50,
  },
  order_bump_copy_v1: {
    showBump: true,
    bumpTitle: 'Full Access Pass',
    bumpDescription: 'Video + Community + Smart coaching',
    bumpPrice: 0,
    guaranteeText: 'Ironclad Protection',
    guaranteeSubtext: "100% Refund if you don't see results in 30 days.",
  },
  signup_steps_v1: {
    skipWelcome: false,
    showProgressBar: true,
    welcomeTitle: 'Welcome to <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-emerald-400">Zenith</span>',
    welcomeSubtitle: "You're about to join 2,400+ members optimizing their biology for peak performance and lasting wellness.",
  },
};
