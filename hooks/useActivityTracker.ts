import { useEffect, useRef } from 'react';
import { api } from '../services/api';
import { AppTab } from '../types';

export const useActivityTracker = (activeTab: AppTab, userId?: string) => {
    const previousTab = useRef<AppTab | null>(null);

    useEffect(() => {
        if (!userId) return;
        if (activeTab === previousTab.current) return;

        // Log page view
        api.post('/activity/log', {
            action_type: 'page_view',
            description: `Navigated to ${activeTab}`,
            metadata: { tab: activeTab, prev: previousTab.current }
        }).catch(err => console.error("Tracking error", err));

        previousTab.current = activeTab;

    }, [activeTab, userId]);
};
