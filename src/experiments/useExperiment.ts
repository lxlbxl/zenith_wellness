import { useExperimentContext } from './ExperimentProvider';
import type { ExperimentConfigs, ExperimentKey } from './types';
import { DEFAULT_CONFIGS } from './defaults';

export function useExperiment<K extends ExperimentKey>(key: K): {
  variant: string;
  config: ExperimentConfigs[K];
  isReady: boolean;
} {
  const ctx = useExperimentContext();
  const assignment = ctx.getAssignment(key);
  const defaults = DEFAULT_CONFIGS[key] as ExperimentConfigs[K];

  if (!ctx.isReady) {
    return { variant: 'control', config: defaults, isReady: false };
  }

  if (!assignment) {
    return { variant: 'control', config: defaults, isReady: true };
  }

  const merged = { ...defaults, ...(assignment.config as Partial<ExperimentConfigs[K]>) };

  return {
    variant: assignment.variant,
    config: merged,
    isReady: true,
  };
}
