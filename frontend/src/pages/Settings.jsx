import Card from '../components/ui/Card'
import { Settings as SettingsIcon, User, Bell, Shield } from 'lucide-react'

const Settings = () => {
  const sections = [
    {
      title: 'Profile Settings',
      description: 'Update your personal information and preferences',
      icon: User,
      color: 'bg-blue-500',
    },
    {
      title: 'Notifications',
      description: 'Configure your notification preferences',
      icon: Bell,
      color: 'bg-green-500',
    },
    {
      title: 'Security',
      description: 'Manage your password and security settings',
      icon: Shield,
      color: 'bg-purple-500',
    },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Settings</h1>
        <p className="text-gray-500">Manage system settings and preferences</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {sections.map((section) => (
          <Card key={section.title} className="cursor-pointer hover:shadow-md transition-shadow">
            <div className="flex flex-col items-center text-center space-y-4">
              <div className={`p-4 rounded-full ${section.color}`}>
                <section.icon className="h-8 w-8 text-white" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">{section.title}</h3>
                <p className="text-sm text-gray-500 mt-1">{section.description}</p>
              </div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}

export default Settings