import { useCallback, useEffect, useState } from 'react';
import { BellRing, BellOff, Loader2, Smartphone, MonitorSmartphone, CheckCircle2, AlertTriangle } from 'lucide-react';
import toast from 'react-hot-toast';
import Card from '../../ui/Card';
import {
  isPushSupported,
  getPermissionState,
  wasPermissionDenied,
  enablePushForThisDevice,
  disablePushForThisDevice,
  getUsableSubscription,
} from '../../../utils/pushNotifications';
import { notificationService } from '../../../api/services/notificationService';

/**
 * @typedef {Object} PushDevicesCardProps
 * @property {Array<{id:number, device_name:string, platform:string|null, last_used_at:string|null, created_at:string|null}>} devices
 * @property {boolean|null} hasVapid true/false from the server, null when unknown
 * @property {(devices: any[]) => void} onDevicesChange
 */

/**
 * Web Push section: browser capability + permission status,
 * per-device enable/disable and the employee's registered devices.
 *
 * @param {PushDevicesCardProps} props
 */
const PushDevicesCard = ({ devices, hasVapid, onDevicesChange }) => {
  const [busy, setBusy] = useState(false);
  // 'checking' until the real state is known, so the UI never asserts a status
  // it has not verified.
  const [state, setState] = useState({
    status: 'checking',
    vapidKey: '',
    endpoint: null,
  });

  const supported = isPushSupported();
  const permission = getPermissionState();

  const reloadDevices = useCallback(async () => {
    const list = await notificationService.listDevices();
    onDevicesChange(list?.data?.devices ?? []);
  }, [onDevicesChange]);

  /**
   * Resolve what is ACTUALLY true on this device, rather than inferring it.
   *
   * The previous check was `devices.length >= 0 && permission === 'granted'`,
   * and `devices.length >= 0` is true for every array that has ever existed -
   * so it reduced to "the browser permission is granted" and the badge claimed
   * "This device: permission granted" even with nothing registered. That is
   * exactly the contradiction the employee hit: the badge said enabled while
   * the list below it said "No devices yet".
   */
  const inspect = useCallback(async () => {
    if (!supported) {
      setState({ status: 'unsupported', vapidKey: '', endpoint: null });
      return;
    }
    let vapidKey = '';
    try {
      const res = await notificationService.getVapidPublicKey();
      vapidKey = res?.data?.public_key ?? '';
    } catch {
      vapidKey = '';
    }

    let endpoint = null;
    if (vapidKey) {
      const sub = await getUsableSubscription(vapidKey);
      endpoint = sub?.endpoint ?? null;
    }

    // Distinct, honest states instead of one optimistic guess.
    //
    // `hasVapid` is tri-state: true/false come from a real server response,
    // null means the request failed. Treating null as "server unconfigured"
    // is a false alarm, and a false alarm is exactly the kind of unverified
    // claim this card exists to stop making.
    let status = 'off';
    if (hasVapid === false) status = 'server-unconfigured';
    else if (hasVapid === null) status = 'server-unknown';
    else if (permission === 'denied' || wasPermissionDenied()) status = 'blocked';
    else if (permission === 'default') status = 'not-asked';
    else if (endpoint) status = devices.length > 0 ? 'on' : 'on-unregistered';

    setState({ status, vapidKey, endpoint });
  }, [supported, hasVapid, permission, devices.length]);

  useEffect(() => {
    void inspect();
  }, [inspect]);

  // Re-verify when the tab regains focus: the browser permission or the
  // subscription can change in another tab, and a stale badge is exactly the
  // failure mode being fixed here.
  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') void inspect();
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [inspect]);

  const handleEnable = async () => {
    setBusy(true);
    try {
      const outcome = await enablePushForThisDevice();
      if (!outcome.ok) {
        toast.error(outcome.message);
      } else {
        toast.success(outcome.message);
      }
      // Re-read from the SERVER rather than trusting the local state, so the
      // badge only turns green once the row is genuinely persisted.
      await reloadDevices();
      await inspect();
      if (outcome.ok) {
        const res = await notificationService.listDevices();
        if (!res?.data?.devices?.length) {
          toast.error(
            'The browser accepted it, but the server has no record. Check the server VAPID configuration.',
          );
        }
      }
    } catch {
      toast.error('Could not enable notifications. Please try again.');
    } finally {
      setBusy(false);
    }
  };

  const handleDisable = async () => {
    setBusy(true);
    try {
      const outcome = await disablePushForThisDevice();
      if (outcome.ok) toast.success(outcome.message);
      else toast.error(outcome.message);
      await reloadDevices();
      await inspect();
    } catch {
      toast.error('Could not disable notifications. Please try again.');
    } finally {
      setBusy(false);
    }
  };

  const status = state.status;
  const blocked =
    !supported || hasVapid === false || wasPermissionDenied() || permission === 'denied';
  // Enabling is pointless once this device already holds a usable
  // subscription; offering a no-op button is how a stale state gets ignored.
  const alreadyOn = status === 'on' || status === 'on-unregistered';

  const badge = {
    'on': { tone: 'green', text: 'Active on this device' },
    'on-unregistered': {
      tone: 'amber',
      text: 'Browser has it, but the server has no record — press Enable to re-register',
    },
    'not-asked': { tone: 'gray', text: 'Permission not requested yet' },
    'server-unconfigured': { tone: 'amber', text: 'Server push not configured' },
    'server-unknown': { tone: 'gray', text: 'Could not verify server push config' },
    blocked: { tone: 'red', text: 'Blocked for this site' },
  }[status] ?? null;

  return (
    <Card className="p-6">
      <div className="flex items-start gap-4">
        <div className={`p-3 rounded-full ${devices.length > 0 ? 'bg-green-500' : 'bg-gray-400'}`}>
          <BellRing className="h-6 w-6 text-white" />
        </div>
        <div className="flex-1">
          <h3 className="text-lg font-semibold text-gray-900">Web Push Notifications</h3>
          <p className="text-sm text-gray-500 mt-1">
            Get a reminder on this device&apos;s browser when you have not clocked in by the
            scheduled time.
          </p>

          {!supported && (
            <p className="mt-3 text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded-md p-3">
              This browser does not support push notifications. You can still use SMS reminders and
              clock in normally.
            </p>
          )}
          {supported && hasVapid === false && (
            <p className="mt-3 text-sm text-amber-600 bg-amber-50 border border-amber-200 rounded-md p-3">
              Push is not configured yet (server missing VAPID keys).
            </p>
          )}
          {supported && hasVapid === null && (
            <p className="mt-3 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-md p-3">
              Could not reach the server to confirm the push configuration. Reload the page to try
              again.
            </p>
          )}
          {supported && permission === 'denied' && (
            <p className="mt-3 text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-3">
              Notifications are blocked for this site. Allow them in your browser settings, then
              reload.
            </p>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-3">
            {alreadyOn ? (
              // Once this device holds a usable subscription the only
              // meaningful action is to turn it off. Offering "Enable" here
              // implied there was a choice to make when there is not.
              <button
                type="button"
                className="btn-secondary inline-flex items-center gap-2"
                disabled={busy}
                onClick={handleDisable}
              >
                {busy ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <BellOff className="h-4 w-4" />
                )}
                Disable on this device
              </button>
            ) : (
              <button
                type="button"
                className="btn-primary inline-flex items-center gap-2"
                disabled={busy || blocked}
                onClick={handleEnable}
              >
                {busy ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <MonitorSmartphone className="h-4 w-4" />
                )}
                Enable on this device
              </button>
            )}

            {badge && (
              <span
                className={`text-xs px-2 py-1 rounded-full border inline-flex items-center gap-1 ${
                  badge.tone === 'green'
                    ? 'text-green-700 bg-green-50 border-green-200'
                    : badge.tone === 'amber'
                      ? 'text-amber-800 bg-amber-50 border-amber-200'
                      : badge.tone === 'red'
                        ? 'text-red-700 bg-red-50 border-red-200'
                        : 'text-gray-600 bg-gray-50 border-gray-200'
                }`}
              >
                {badge.tone === 'green' && <CheckCircle2 className="h-3 w-3" />}
                {badge.tone === 'amber' && <AlertTriangle className="h-3 w-3" />}
                {badge.text}
              </span>
            )}
          </div>

          {status === 'on-unregistered' && (
            <p className="mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-3">
              This browser holds a push subscription but the server has no matching
              registration for your account — usually after a server key change or
              signing in with a different account on a shared device. Press
              <strong> Enable on this device</strong> to re-register it.
            </p>
          )}

          {/* Registered devices */}
          <div className="mt-5">
            <h4 className="text-sm font-medium text-gray-700 mb-2">Registered devices</h4>
            {devices.length === 0 ? (
              <p className="text-sm text-gray-500">No devices yet.</p>
            ) : (
              <ul className="divide-y divide-gray-100 border border-gray-100 rounded-md">
                {devices.map((device) => (
                  <li
                    key={device.id}
                    className="flex items-center justify-between px-3 py-2 text-sm"
                  >
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
  );
};

export default PushDevicesCard;
