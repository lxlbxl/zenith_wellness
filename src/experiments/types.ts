export interface TrustHeroV1Config {
  headline: string;
  subhead: string;
}

export interface QuizEmailGateV1Config {
  showEmailGate: boolean;
  gateTitle: string;
  gateDescription: string;
  urgencyEnabled: boolean;
}

export interface CheckoutLayoutV1Config {
  layout: 'split' | 'full';
  showSummaryLeft: boolean;
  showTestimonial: boolean;
  showCurrencySelector: boolean;
}

export interface PricingAnchorV1Config {
  showDiscount: boolean;
  showCompareAt: boolean;
  badge: string;
  discountPercent: number;
}

export interface OrderBumpCopyV1Config {
  showBump: boolean;
  bumpTitle: string;
  bumpDescription: string;
  bumpPrice: number;
  guaranteeText: string;
  guaranteeSubtext: string;
}

export interface SignupStepsV1Config {
  skipWelcome: boolean;
  showProgressBar: boolean;
  welcomeTitle: string;
  welcomeSubtitle: string;
}

export interface ExperimentConfigs {
  trust_hero_v1: TrustHeroV1Config;
  quiz_email_gate_v1: QuizEmailGateV1Config;
  checkout_layout_v1: CheckoutLayoutV1Config;
  pricing_anchor_v1: PricingAnchorV1Config;
  order_bump_copy_v1: OrderBumpCopyV1Config;
  signup_steps_v1: SignupStepsV1Config;
}

export type ExperimentKey = keyof ExperimentConfigs;

export type ExperimentVariantConfig<K extends ExperimentKey> = ExperimentConfigs[K];
