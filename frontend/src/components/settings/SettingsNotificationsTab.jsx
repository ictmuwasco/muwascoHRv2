import { useCallback, useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { notificationService } from '../../api/services/notificationService'
import { getPermissionState, wasPermissionDenied } from '../../utils/pushNotifications'

import PushDevicesCard from './notifications/PushDevicesCard'
import SmsPreferencesCard from './notifications/SmsPreferencesCard'

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
}

/**
 * Settings > Notifications tab.
 *
 * Loads the effective preference view + registered push devices from
 * the backend (the server owns every eligibility/permission decision;
 * this UI only reflects and saves employee choices).
 */
const NotificationsTab = () => {
  const [prefs, setPrefs] = useState(DEFAULT_PREFS)
  const [devices, setDevices] = useState([])
  const [hasVapid, setHasVapid] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  // Re-read permission state when the tab regains focus so a user who
  // unblocks notifications in browser settings sees it without reload.
  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const [prefsRes, devicesRes] = await Promise.all([
        notificationService.getPreferences(),
        notificationService.listDevices(),
      ])
      if (prefsRes?.data) setPrefs(prefsRes.data)
      if (devicesRes?.data) {
        setDevices(devicesRes.data.devices ?? [])
        setHasVapid(devicesRes.data.has_vapid ?? false)
      }
    } catch {
      toast.error('Could not load notification settings.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
    const onVisible = () => {
      if (document.visibilityState === 'visible' && !wasPermissionDenied() && getPermissionState() !== 'unsupported') {
        void refresh()
      }
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => document.removeEventListener('visibilitychange', onVisible)
  }, [refresh])

  const handleSmsChange = async (enabled) => {
    setSaving(true)
    try {
      const response = await notificationService.savePreferences({
        push_enabled: prefs.push_enabled,
        sms_enabled: enabled,
      })
      if (response.success) {
        setPrefs((prev) => ({ ...prev, sms_enabled: enabled }))
        toast.success(response.message || 'Preferences saved.')
      } else {
        toast.error(response.message || 'Could not save preferences.')
      }
    } catch {
      toast.error('Could not save preferences.')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div className="card h-48 animate-pulse" />
        <div className="card h-48 animate-pulse" />
      </div>
    )
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
      <PushDevicesCard
        devices={devices}
        hasVapid={hasVapid}
        onDevicesChange={setDevices}
      />
      <SmsPreferencesCard
        prefs={prefs}
        smsEnabled={prefs.sms_enabled}
        onChangeSms={handleSmsChange}
        saving={saving}
      />
    </div>
  )
}

export default NotificationsTab


