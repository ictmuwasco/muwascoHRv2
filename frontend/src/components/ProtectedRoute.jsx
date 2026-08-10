import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { getConsentStatus } from '../api/services/consentService'
import { useState, useEffect } from 'react'

const ProtectedRoute = ({ children }) => {
  const { isAuthenticated, loading: authLoading } = useAuth()
  const [consented, setConsented] = useState(false)
  const [checkingConsent, setCheckingConsent] = useState(true)
  const [consentError, setConsentError] = useState(false)

  useEffect(() => {
    if (!isAuthenticated || authLoading) return

    let cancelled = false

    const checkConsent = async () => {
      try {
        const response = await getConsentStatus()
        if (!cancelled) {
          setConsented(response.consented || false)
          setConsentError(false)
        }
      } catch (err) {
        if (!cancelled) {
          // On API failure, allow access rather than blocking the user
          setConsented(true)
          setConsentError(true)
        }
      } finally {
        if (!cancelled) {
          setCheckingConsent(false)
        }
      }
    }

    checkConsent()

    return () => {
      cancelled = true
    }
  }, [isAuthenticated, authLoading])

  if (authLoading || checkingConsent) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  // Not consented - redirect to consent page
  if (!consented) {
    return <Navigate to="/data-protection-consent" replace />
  }

  // Authenticated and consented - render protected content
  return children
}

export default ProtectedRoute