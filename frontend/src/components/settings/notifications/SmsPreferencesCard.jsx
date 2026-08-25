import { MessageSquare, Phone, Clock, ShieldCheck } from 'lucide-react'
import Card from '../../ui/Card'

/**
 * @typedef {Object} SmsPreferencesCardProps
 * @property {any} prefs Effective preference view from the backend
 * @property {boolean} smsEnabled
 * @property {(enabled: boolean) => void} onChangeSms
 * @property {boolean} saving
 */

/**
 * SMS section: employee toggle (when not organisation-mandated),
 * masked official phone number and fallback timing info.
 *
 * @param {SmsPreferencesCardProps} props
 */
const SmsPreferencesCard = ({ prefs, smsEnabled, onChangeSms, saving }) => (
  <Card className="p-6">
    <div className="flex items-start gap-4">
      <div className={`p-3 rounded-full ${smsEnabled ? 'bg-blue-600' : 'bg-gray-400'}`}>
        <MessageSquare className="h-6 w-6 text-white" />
      </div>
      <div className="flex-1">
        <h3 className="text-lg font-semibold text-gray-900">SMS Notifications</h3>
        <p className="text-sm text-gray-500 mt-1">
          A short clock-in reminder sent to your phone when a push notification
          cannot reach you.
        </p>

        <label className="mt-4 flex items-center gap-3 cursor-pointer select-none">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            checked={smsEnabled}
            disabled={saving || prefs.reminders_mandatory}
            onChange={(e) => onChangeSms(e.target.checked)}
          />
          <span className="text-sm text-gray-800">
            Send me SMS attendance reminders
          </span>
          {prefs.reminders_mandatory && (
            <span className="inline-flex items-center gap-1 text-xs text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full">
              <ShieldCheck className="h-3 w-3" /> Required by company policy
            </span>
          )}
        </label>

        <dl className="mt-4 space-y-2 text-sm">
          <div className="flex items-center gap-2 text-gray-700">
            <Phone className="h-4 w-4 text-gray-400" />
            <dt className="text-gray-500">Phone:</dt>
            <dd>{prefs.phone_masked ?? 'Not set — contact HR to add one.'}</dd>
          </div>
          <div className="flex items-center gap-2 text-gray-700">
            <Clock className="h-4 w-4 text-gray-400" />
            <dt className="text-gray-500">Reminder time:</dt>
            <dd>
              {prefs.reminder_time} · SMS fallback after {prefs.sms_fallback_delay_minutes} min if still not clocked in
            </dd>
          </div>
        </dl>

        <p className="mt-3 text-xs text-gray-400">
          Reminders are only sent on scheduled working days that are not public
          holidays and while you are not on approved leave. Already clocked in?
          No message is sent.
        </p>
      </div>
    </div>
  </Card>
)

export default SmsPreferencesCard
