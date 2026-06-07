
import React, { useState, useEffect } from 'react';
import { api } from '../services/api';
import { User } from '../types';
import MultiStepForm from './ui/MultiStepForm';
import Button from './ui/Button';
import PrivacyCenter from './PrivacyCenter';

interface ProfileSettingsProps {
    user: User;
    onClose: () => void;
    onLogout: () => void;
    onUserUpdate?: (user: User) => void;
}

interface UserSettings {
    units: 'metric' | 'imperial';
    timezone: string;
    notifications: {
        email: boolean;
        push: boolean;
        mealReminders: boolean;
        periodReminders: boolean;
    };
}

const timezones = [
    'UTC', 'Africa/Lagos', 'Africa/Cairo', 'Africa/Johannesburg',
    'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'Europe/London', 'Europe/Paris', 'Europe/Berlin',
    'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo',
    'Australia/Sydney', 'Pacific/Auckland'
];

const ProfileSettings: React.FC<ProfileSettingsProps> = ({ user, onClose, onLogout, onUserUpdate }) => {
    const [loading, setLoading] = useState(false);

    // Form States
    const [name, setName] = useState(user.name);

    const [passwords, setPasswords] = useState({
        current: '',
        new: '',
        confirm: ''
    });

    const [settings, setSettings] = useState<UserSettings>({
        units: 'metric',
        timezone: 'UTC',
        notifications: {
            email: true,
            push: true,
            mealReminders: true,
            periodReminders: true
        }
    });

    useEffect(() => {
        const fetchSettings = async () => {
            try {
                const data = await api.get<UserSettings>(`/users/${user.id}/settings`);
                if (data) setSettings(data);
            } catch (err) {
                console.error('Failed to fetch settings', err);
            }
        };
        fetchSettings();
    }, [user.id]);

    const handleSaveAll = async () => {
        setLoading(true);
        try {
            // Update Profile
            if (name !== user.name) {
                await api.put(`/users/${user.id}/profile`, { name });
                if (onUserUpdate) onUserUpdate({ ...user, name });
            }

            // Update Settings
            await api.put(`/users/${user.id}/settings`, settings);

            // Update Password if provided
            if (passwords.new && passwords.new === passwords.confirm) {
                await api.post(`/users/${user.id}/password`, {
                    current_password: passwords.current,
                    new_password: passwords.new,
                    confirm_password: passwords.confirm
                });
            }

            onClose();
        } catch (err) {
            console.error('Failed to save settings', err);
            alert('Failed to save some changes. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const steps = [
        {
            title: 'Profile Basics',
            description: 'Update your personal information.',
            component: (
                <div className="space-y-6">
                    <div className="flex items-center gap-4">
                        <div className="w-16 h-16 bg-gradient-to-br from-brand-500 to-emerald-600 rounded-full flex items-center justify-center text-white text-2xl font-serif">
                            {name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                            <p className="font-bold text-stone-800 text-lg">{name}</p>
                            <p className="text-sm text-stone-500">{user.email}</p>
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Display Name</label>
                        <input
                            type="text"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className="w-full px-5 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 transition-all"
                        />
                    </div>
                </div>
            )
        },
        {
            title: 'Preferences',
            description: 'Customize your Zenith experience.',
            component: (
                <div className="space-y-6">
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Measurement Units</label>
                        <div className="flex gap-3">
                            {(['metric', 'imperial'] as const).map((unit) => (
                                <button
                                    key={unit}
                                    onClick={() => setSettings(s => ({ ...s, units: unit }))}
                                    className={`flex-1 py-3 rounded-xl font-bold transition-all ${settings.units === unit
                                        ? 'bg-brand-600 text-white shadow-lg shadow-brand-200'
                                        : 'bg-stone-50 text-stone-500 hover:bg-stone-100'
                                        }`}
                                >
                                    {unit === 'metric' ? 'Metric' : 'Imperial'}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-bold text-stone-400 uppercase tracking-wider mb-2">Timezone</label>
                        <select
                            value={settings.timezone}
                            onChange={(e) => setSettings(s => ({ ...s, timezone: e.target.value }))}
                            className="w-full px-5 py-4 bg-stone-50 border border-stone-200 rounded-xl font-medium focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        >
                            {timezones.map(tz => (
                                <option key={tz} value={tz}>{tz}</option>
                            ))}
                        </select>
                    </div>
                </div>
            )
        },
        {
            title: 'Security',
            description: 'Manage your password and security.',
            component: (
                <div className="space-y-4">
                    <div className="p-4 bg-amber-50 border border-amber-100 rounded-xl text-amber-800 text-sm mb-4">
                        <i className="fa-solid fa-lock mr-2"></i>
                        Leave blank to keep current password.
                    </div>
                    <div>
                        <input
                            type="password"
                            placeholder="Current Password"
                            value={passwords.current}
                            onChange={(e) => setPasswords(p => ({ ...p, current: e.target.value }))}
                            className="w-full px-5 py-3 bg-stone-50 border border-stone-200 rounded-xl mb-3"
                        />
                        <input
                            type="password"
                            placeholder="New Password"
                            value={passwords.new}
                            onChange={(e) => setPasswords(p => ({ ...p, new: e.target.value }))}
                            className="w-full px-5 py-3 bg-stone-50 border border-stone-200 rounded-xl mb-3"
                        />
                        <input
                            type="password"
                            placeholder="Confirm New Password"
                            value={passwords.confirm}
                            onChange={(e) => setPasswords(p => ({ ...p, confirm: e.target.value }))}
                            className="w-full px-5 py-3 bg-stone-50 border border-stone-200 rounded-xl"
                        />
                    </div>
                </div>
            )
        },

        {
            title: 'Privacy & Data',
            description: 'Manage your data and consents.',
            component: <PrivacyCenter />
        }
    ];

    return (
        <div className="fixed inset-0 z-50 bg-stone-900/80 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
            <div className="w-full max-w-2xl">
                <div className="flex justify-end mb-4">
                    <Button variant="ghost" className="text-white hover:bg-white/10" onClick={onClose}>
                        <i className="fa-solid fa-xmark mr-2"></i> Close
                    </Button>
                </div>
                <MultiStepForm
                    steps={steps}
                    onComplete={handleSaveAll}
                    onCancel={onClose}
                    submitLabel={loading ? 'Saving...' : 'Save All Changes'}
                />
                <div className="mt-8 text-center">
                    <button onClick={onLogout} className="text-stone-400 hover:text-rose-400 text-xs font-bold uppercase tracking-widest transition-colors">
                        Sign Out
                    </button>
                </div>
            </div>
        </div>
    );
};

export default ProfileSettings;

