import Card from '../ui/Card'
import { Settings as SettingsIcon } from 'lucide-react'

const ProfileSettings = () => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
      <Card className="cursor-pointer hover:shadow-md transition-shadow">
        <div className="flex flex-col items-center text-center space-y-4">
          <div className="p-4 rounded-full bg-blue-500">
            <SettingsIcon className="h-8 w-8 text-white" />
          </div>
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Profile Settings</h3>
            <p className="text-sm text-gray-500 mt-1">
              Update your personal information and preferences
            </p>
          </div>
        </div>
      </Card>
      <Card className="cursor-pointer hover:shadow-md transition-shadow">
        <div className="flex flex-col items-center text-center space-y-4">
          <div className="p-4 rounded-full bg-green-500">
            <SettingsIcon className="h-8 w-8 text-white" />
          </div>
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Email & Phone</h3>
            <p className="text-sm text-gray-500 mt-1">
              Manage how MUWASCO contacts you
            </p>
          </div>
        </div>
      </Card>
      <Card className="cursor-pointer hover:shadow-md transition-shadow">
        <div className="flex flex-col items-center text-center space-y-4">
          <div className="p-4 rounded-full bg-yellow-500">
            <SettingsIcon className="h-8 w-8 text-white" />
          </div>
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Appearance</h3>
            <p className="text-sm text-gray-500 mt-1">
              Theme, language and date format
            </p>
          </div>
        </div>
      </Card>
    </div>
  )
}

export default ProfileSettings

