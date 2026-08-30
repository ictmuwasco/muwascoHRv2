import { useState, useEffect } from 'react'
import { useAuth } from '../context/AuthContext'
import { useTheme } from '../context/ThemeContext'
import { Menu, Bell, LogOut, Sun, Moon } from 'lucide-react'
import apiClient from '../api/client'

// Base URL for direct file access (auth cookie is sent automatically)
const API_BASE = import.meta.env.VITE_API_URL || '/api'

/**
 * Avatar for the signed-in user.
 * Shows the profile photo uploaded on the Profile page (employees.profile_image_url,
 * streamed through GET /profile/profile-image with the auth cookie). Falls back to
 * an initials circle when the employee has no photo or the image fails to load.
 */
const UserAvatar = ({ user }) => {
  const [imageUrl, setImageUrl] = useState(null)
  const [imageFailed, setImageFailed] = useState(false)

  useEffect(() => {
    let cancelled = false
    setImageUrl(null)
    setImageFailed(false)
    ;(async () => {
      try {
        const res = await apiClient.get('/profile')
        const stored = res.data?.data?.profile_image_url
        if (!cancelled && stored) {
          // Same streaming URL the Profile page uses; cache-busted after re-upload.
          setImageUrl(
            stored.startsWith('http')
              ? stored
              : `${API_BASE}/profile/profile-image?t=${Date.now()}`
          )
        }
      } catch {
        // No employee record / not authorised — initials fallback is used.
      }
    })()
    return () => {
      cancelled = true
    }
  }, [user?.id])

  if (imageUrl && !imageFailed) {
    return (
      <img
        src={imageUrl}
        alt={`${user?.first_name ?? 'User'} ${user?.last_name ?? ''}`.trim()}
        onError={() => setImageFailed(true)}
        className="h-8 w-8 rounded-full object-cover border border-gray-200 dark:border-slate-600"
      />
    )
  }

  return (
    <div className="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center text-white">
      {user?.first_name?.[0] || 'U'}
    </div>
  )
}

const Header = ({ onToggleSidebar = () => {} }) => {
  const { user, logout } = useAuth()
  const { theme, toggleTheme } = useTheme()
  const [showDropdown, setShowDropdown] = useState(false)

  const handleLogout = async () => {
    await logout()
  }

  // Sticky header - pinned to the viewport top while the page scrolls; stays in
  // flow so it honours Layout's lg:pl-64 offset and floats above scrolled content.
  return (
    <header className="sticky top-0 z-40 bg-white dark:bg-slate-800 shadow-sm border-b dark:border-slate-700">
      <div className="flex items-center justify-between px-6 py-4">
        {/* Mobile menu button */}
        <button
          onClick={onToggleSidebar}
          className="lg:hidden text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200"
        >
          <Menu className="h-6 w-6" />
        </button>

        {/* Right side */}
        <div className="flex items-center space-x-4 ml-auto">
          {/* Theme toggle */}
          <button
            onClick={toggleTheme}
            className="p-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700 rounded-lg transition-colors"
            title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
          >
            {theme === 'dark' ? (
              <Sun className="h-5 w-5" />
            ) : (
              <Moon className="h-5 w-5" />
            )}
          </button>

          {/* Notifications */}
          <button className="relative text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
            <Bell className="h-6 w-6" />
            <span className="absolute top-0 right-0 h-2 w-2 bg-red-600 rounded-full"></span>
          </button>

          {/* User dropdown */}
          <div className="relative">
            <button
              onClick={() => setShowDropdown(!showDropdown)}
              className="flex items-center space-x-2 text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-200"
            >
              <UserAvatar user={user} />
              <span className="hidden md:block text-sm font-medium">
                {user?.first_name} {user?.last_name}
              </span>
            </button>

            {showDropdown && (
              <div className="absolute right-0 mt-2 w-48 bg-white dark:bg-slate-800 rounded-md shadow-lg py-1 z-50 border dark:border-slate-700">
                <div className="px-4 py-2 text-sm text-gray-700 dark:text-gray-300 border-b dark:border-slate-700">
                  <p className="font-medium text-gray-900 dark:text-gray-100">{user?.first_name} {user?.last_name}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">{user?.email}</p>
                </div>
                <button
                  onClick={handleLogout}
                  className="flex items-center w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700"
                >
                  <LogOut className="h-4 w-4 mr-2" />
                  Logout
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </header>
  )
}

export default Header
