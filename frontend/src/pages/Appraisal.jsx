import { useState, useEffect } from 'react'
import api from '../utils/api'
import Card from '../components/ui/Card'
import Badge from '../components/ui/Badge'
import Button from '../components/ui/Button'
import { FileText, Star } from 'lucide-react'

const Appraisal = () => {
  const [appraisals, setAppraisals] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchAppraisals()
  }, [])

  const fetchAppraisals = async () => {
    try {
      const response = await api.get('/appraisals/employee/me')
      setAppraisals(response.data.data || [])
    } catch (error) {
      console.error('Failed to fetch appraisals:', error)
    } finally {
      setLoading(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">My Appraisals</h1>
        <p className="text-gray-500 dark:text-gray-400">View and manage your performance reviews</p>
      </div>

      {appraisals.length > 0 ? (
        <div className="space-y-4">
          {appraisals.map((appraisal) => (
            <Card key={appraisal.id}>
              <div className="flex items-start justify-between">
                <div className="flex items-start space-x-4">
                  <div className="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                    <FileText className="h-6 w-6 text-purple-600 dark:text-purple-400" />
                  </div>
                  <div>
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                      {appraisal.cycle_name || 'Performance Review'}
                    </h3>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                      Period: {appraisal.start_date} - {appraisal.end_date}
                    </p>
                    <div className="mt-2">
                      <Badge variant={appraisal.status === 'completed' ? 'success' : appraisal.status === 'pending' ? 'warning' : 'info'}>
                        {appraisal.status}
                      </Badge>
                    </div>
                  </div>
                </div>
                {appraisal.rating && (
                  <div className="flex items-center space-x-1">
                    <Star className="h-5 w-5 text-yellow-500 fill-current" />
                    <span className="text-lg font-semibold">{appraisal.rating}/5</span>
                  </div>
                )}
              </div>
            </Card>
          ))}
        </div>
      ) : (
        <Card>
          <div className="text-center py-8">
            <FileText className="h-12 w-12 text-gray-400 dark:text-gray-500 mx-auto mb-4" />
            <p className="text-gray-500 dark:text-gray-400">No appraisals assigned yet</p>
          </div>
        </Card>
      )}
    </div>
  )
}

export default Appraisal