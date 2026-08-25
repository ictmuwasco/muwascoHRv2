import { useState } from 'react'
import { BellRing, BellOff, Loader2, Smartphone, MonitorSmartphone } from 'lucide-react'
import toast from 'react-hot-toast'
import Card from '../../ui/Card'
import {
  isPushSupported,
  getPermissionState,
  wasPermissionDenied,
  enablePushForThisDevice,
  disablePushForThisDevice,
} from '../../../utils/pushNotifications'
import { notificationService } from '../../../api/services/notificationService'

/**
 * @typedef {Object} PushDevicesCardProps
 * @property {Array<{id:number, device_name:string, platform:string|null, last_used_at:string|null, created_at:string|null}>} devices
 * @property {boolean} hasVapid
 * @property {(devices: any[]) => void} onDevicesChange
 */

/**
 * Web Push section: browser capability + permission status,
 * per-device enable/disable and the employee's registered devices.
 *
 * @param {PushDevicesCardProps} props
 */
const PushDevicesCard = ({ devices, hasVapid, onDevicesChange }) => {
  const [busy, setBusy] = useState(false)
  const supported = isPushSupported()
  const permission = getPermissionState()
  const subscribedHere = devices.length >= 0 && !wasPermissionDenied() && permission === 'granted'

  const blocked = !supported || !hasVapid || wasPermissionDenied() || permission === 'denied'

  const handleEnable = async () => {
    setBusy(true)
    try {
      const outcome = await enablePushForThisDevice()
      if (outcome.ok) {
        toast.success(outcome.message)
        const list = await notificationService.listDevices()
        onDevicesChange(list?.data?.devices ?? [])
      } else {
        toast.error(outcome.message)
      }
    } catch {
      toast.error('Could not enable notifications. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  const handleDisable = async () => {
    setBusy(true)
    try {
      const outcome = await disablePushForThisDevice()
      if (outcome.ok) {
        toast.success(outcome.message)
        const list = await notificationService.listDevices()
        onDevicesChange(list?.data?.devices ?? [])
      } else {
        toast.error(outcome.message)
      }
    } catch {
      toast.error('Could not disable notifications. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card className="p-6">
      <div className="flex items-start gap-4">
        <div className={`p-3 rounded-full ${devices.length > 0 ? 'bg-green-500' : 'bg-gray-400'}`}>
          <BellRing className="h-6 w-6 text-white" />
        </div>
        <div className="flex-1">
          <h3 className="text-lg font-semibold text-gray-900">Web Push Notifications</h3>
          <p className="text-sm text-gray-500 mt-1">
            Get a reminder on this device&apos;s browser when you have not clocked in
            by the scheduled time.
          </p>

          {!supported && (
            <p className="mt-3 text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded-md p-3">
              This browser does not support push notifications. You can still use
              SMS reminders and clock in normally.
            </p>
          )}
          {supported && !hasVapid && (
            <p className="mt-3 text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded-md p-3">
              Push is not configured yet (server missing VAPID keys).
            </p>
          )}
          {supported && permission === 'denied' && (
            <p className="mt-3 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-3">
              Notifications are blocked for this site. Allow them in your browser
              settings, then reload.
            </p>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-3">
            <button
              type="button"
              className="btn-primary inline-flex items-center gap-2"
              disabled={busy || blocked}
              onClick={handleEnable}
            >
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <MonitorSmartphone className="h-4 w-4" />}
              Enable on this device
            </button>
            <button
              type="button"
              className="btn-secondary inline-flex items-center gap-2"
              disabled={busy}
              onClick={handleDisable}
            >
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <BellOff className="h-4 w-4" />}
              Disable here
            </button>
            {subscribedHere && permission === 'granted' && (
              <span className="text-xs text-green-700 bg-green-50 px-2 py-1 rounded-full border border-green-200">
                This device: permission granted
              </span>
            )}
          </div>

          {/* Registered devices */}
          <div className="mt-5">
            <h4 className="text-sm font-medium text-gray-700 mb-2">Registered devices</h4>
            {devices.length === 0 ? (
              <p className="text-sm text-gray-500">No devices yet.</p>
            ) : (
              <ul className="divide-y divide-gray-100 border border-gray-100 rounded-md">
                {devices.map((device) => (
                  <li key={device.id} className="flex items-center justify-between px-3 py-2 text-sm">
                    <span className="flex items-center gap-2 text-gray-700">
                      <Smartphone className="h-4 w-4 text-gray-400" />
                      {device.device_name}
                      {device.platform ? ` · ${device.platform}` : ''}
                    </span>
                    <span className="text-xs text-gray-400">
                      {device.last_used_at
                        ? `Last used ${new Date(device.last_used_at).toLocaleString()}`
                        : 'Never used'}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>
    </Card>
  )
}

export default PushDevicesCard
