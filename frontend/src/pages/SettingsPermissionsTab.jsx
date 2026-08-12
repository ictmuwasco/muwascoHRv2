import Card from '../components/ui/Card'
import { Shield } from 'lucide-react'

const PermissionsTab = () => {
  return (
    <Card>
      <div className="text-center py-8 text-gray-500">
        <Shield className="h-10 w-10 mx-auto mb-3 text-gray-400" />
        <p className="text-gray-700 font-medium">Role Permissions</p>
        <p className="text-sm mt-1">
          Role-based access management will be available here.
        </p>
      </div>
    </Card>
  )
}

export default PermissionsTab
