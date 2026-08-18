import Card from '../ui/Card'
import { Bell } from 'lucide-react'

const NotificationsTab = () => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
      <Card className="cursor-pointer hover:shadow-md transition-shadow">
        <div className="flex flex-col items-center text-center space-y-4">
          <div className="p-4 rounded-full bg-green-500">
            <Bell className="h-8 w-8 text-white" />
          </div>
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Notification Preferences</h3>
            <p className="text-sm text-gray-500 mt-1">
              Configure email, SMS and in-app alerts
            </p>
          </div>
        </div>
      </Card>
      <Card>
        <h3 className="text-lg font-semibold text-gray-900 mb-2">Quiet Hours</h3>
        <p className="text-sm text-gray-500">
          Quiet hours scheduling will be available here.
        </p>
      </Card>
    </div>
  )
}

export default NotificationsTab

