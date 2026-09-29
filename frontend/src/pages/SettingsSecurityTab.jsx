import Card from '../components/ui/Card'
import { Lock } from 'lucide-react'

const SecurityTab = () => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
      <Card className="cursor-pointer hover:shadow-md transition-shadow">
        <div className="flex flex-col items-center text-center space-y-4">
          <div className="p-4 rounded-full bg-purple-500">
            <Lock className="h-8 w-8 text-white" />
          </div>
          <div>
            <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Security</h3>
            <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
              Manage your password and security settings
            </p>
          </div>
        </div>
      </Card>
      <Card>
        <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Active Sessions</h3>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Active sessions and device management will be available here.
        </p>
      </Card>
      <Card>
        <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Two-Factor Auth</h3>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Two-factor authentication setup will be available here.
        </p>
      </Card>
    </div>
  )
}

export default SecurityTab
