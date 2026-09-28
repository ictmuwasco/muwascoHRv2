import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Bell, ExternalLink } from 'lucide-react';
import toast from 'react-hot-toast';
import { notificationService } from '../../api/services/notificationService';
import { getPermissionState, wasPermissionDenied } from '../../utils/pushNotifications';

import PushDevicesCard from './notifications/PushDevicesCard';
import SmsPreferencesCard from './notifications/SmsPreferencesCard';
import Card from '../ui/Card';

const DEFAULT_PREFS = {
  push_enabled: true,
  sms_enabled: true,
  effective_push_enabled: true,
  effective_sms_enabled: true,
  reminders_mandatory: false,
  reminder_time: '08:00',
  sms_fallback_delay_minutes: 15,
  phone_masked: null,
  has_active_push: false,
};

/**
 * Entry point to the in-app inbox.
 *
 * The inbox itself lives at /notifications and is reached from the header bell;
 * this tile is the settings-side way in, so the preferences tab is the one
 * place that explains where the messages actually appear.
 */
const InboxTile = ({ unreadCount }) => (
  <Link to="/notifications" className="block focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 rounded-lg">
    <Card className="p-6 flex items-center justify-between gap-4 hover:border-primary-300 dark:hover:border-primary-700 transition-colors">
      <div className="flex items-center gap-4 min-w-0">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400">
          <Bell className="h-5 w-5" />
        </span>
        <div className="min-w-0">
          <p className="font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            Your notifications
            {unreadCount > 0 && (
              <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-bold">
                {unreadCount > 99 ? '99+' : unreadCount}
              </span>
            )}
          </p>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {unreadCount > 0
              ? `${unreadCount} unread - open the inbox to review them.`
              : 'Review everything you have been notified about.'}
          </p>
        </div>
      </div>
      <ExternalLink className="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
    </Card>
  </Link>
);

/**
 * Settings > Notifications tab.
 *
 * Loads the effective preference view + registered push devices from
 * the backend (the server owns every eligibility/permission decision;
 * this UI only reflects and saves employee choices).
 */
const NotificationsTab = () => {
  const [prefs, setPrefs] = useState(DEFAULT_PREFS);
  const [devices, setDevices] = useState([]);
  const [hasVapid, setHasVapid] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);

  // Re-read permission state when the tab regains focus so a user who
  // unblocks notifications in browser settings sees it without reload.
  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const [prefsRes, devicesRes] = await Promise.all([
        notificationService.getPreferences(),
        notificationService.listDevices(),
      ]);
      if (prefsRes?.data) setPrefs(prefsRes.data);
      if (devicesRes?.data) {
        setDevices(devicesRes.data.devices ?? []);
        setHasVapid(devicesRes.data.has_vapid ?? false);
      }
      // Drives the "View inbox" tile's unread count. A failure here must not
      // surface an error: it is decorative, and the preferences above are what
      // this tab is actually for.
      try {
        const unreadRes = await notificationService.listUnread(1);
        setUnreadCount(Number(unreadRes?.data?.unread_count ?? 0));
      } catch {
        setUnreadCount(0);
      }
    } catch {
      toast.error('Could not load notification settings.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
    const onVisible = () => {
      if (
        document.visibilityState === 'visible' &&
        !wasPermissionDenied() &&
        getPermissionState() !== 'unsupported'
      ) {
        void refresh();
      }
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [refresh]);

  const handleSmsChange = async (enabled) => {
    setSaving(true);
    try {
      const response = await notificationService.savePreferences({
        push_enabled: prefs.push_enabled,
        sms_enabled: enabled,
      });
      if (response.success) {
        setPrefs((prev) => ({ ...prev, sms_enabled: enabled }));
        toast.success(response.message || 'Preferences saved.');
      } else {
        toast.error(response.message || 'Could not save preferences.');
      }
    } catch {
      toast.error('Could not save preferences.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="space-y-6">
        <InboxTile unreadCount={0} />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div className="card h-48 animate-pulse" />
          <div className="card h-48 animate-pulse" />
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <InboxTile unreadCount={unreadCount} />
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <PushDevicesCard devices={devices} hasVapid={hasVapid} onDevicesChange={setDevices} />
        <SmsPreferencesCard
          prefs={prefs}
          smsEnabled={prefs.sms_enabled}
          onChangeSms={handleSmsChange}
          saving={saving}
        />
      </div>
    </div>
  );
};

export default NotificationsTab;
