import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext'
import {
  Eye,
  EyeOff,
  LogIn,
  Mail,
  Lock,
  ShieldCheck,
  Users,
  BarChart3,
  Sparkles,
  AlertCircle,
} from 'lucide-react'
import Logo from '../../components/Logo'

// Note: Consent check is handled by ProtectedRoute component

const Login = () => {
  const navigate = useNavigate()
  const { login, isAuthenticated } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [rememberMe, setRememberMe] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [touched, setTouched] = useState({ email: false, password: false })

  // Redirect if already authenticated
  useEffect(() => {
    if (isAuthenticated) {
      navigate('/dashboard', { replace: true })
    }
  }, [isAuthenticated, navigate])

  const emailError =
    touched.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
      ? 'Please enter a valid email address'
      : ''
  const passwordError =
    touched.password && password.length < 8
      ? 'Password must be at least 8 characters'
      : ''

  const handleSubmit = async (e) => {
    e.preventDefault()
    setTouched({ email: true, password: true })
    setError('')

    if (emailError || passwordError || !email || !password) {
      return
    }

    setLoading(true)

    try {
      const result = await login(email, password)

      if (result.success) {
        // Navigate to dashboard; ProtectedRoute will handle consent check
        navigate('/dashboard', { replace: true })
      } else {
        setError(result.message)
      }
    } catch (err) {
      setError('An unexpected error occurred. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen w-full flex bg-slate-50 dark:bg-slate-900">
      {/* Left brand panel */}
      <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-gradient-to-br from-primary-700 via-primary-600 to-primary-800 text-white">
        {/* Decorative shapes */}
        <div className="absolute -top-32 -left-32 w-96 h-96 rounded-full bg-white/10 blur-3xl" />
        <div className="absolute -bottom-40 -right-20 w-[28rem] h-[28rem] rounded-full bg-primary-400/30 blur-3xl" />
        <div className="absolute inset-0 opacity-[0.07] bg-[radial-gradient(circle_at_1px_1px,white_1px,transparent_0)] [background-size:24px_24px]" />

        <div className="relative z-10 flex flex-col justify-between p-12 w-full">
          <div className="flex items-center gap-3">
            <Logo className="h-14 w-14" />
            <div>
              <p className="text-sm text-white/70">Welcome to</p>
              <h1 className="text-lg font-semibold tracking-wide">MUWASCO HR</h1>
            </div>
          </div>

          <div className="space-y-8 max-w-md">
            <div>
              <span className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 ring-1 ring-white/20 text-xs font-medium">
                <Sparkles className="w-3.5 h-3.5" /> Murang'a Water & Sanitation Co.
              </span>
              <h2 className="mt-4 text-4xl font-bold leading-tight">
                Manage your team with confidence.
              </h2>
              <p className="mt-3 text-white/80 leading-relaxed">
                A modern HR platform for employees, attendance, leave,
                appraisals and reports — all in one secure place.
              </p>
            </div>

            <div className="grid grid-cols-1 gap-3">
              <Feature
                icon={<Users className="w-5 h-5" />}
                title="Employee records"
                desc="Centralised profiles, contracts and documents."
              />
              <Feature
                icon={<ShieldCheck className="w-5 h-5" />}
                title="Secure & role-based"
                desc="Granular permissions for every team member."
              />
              <Feature
                icon={<BarChart3 className="w-5 h-5" />}
                title="Insightful reports"
                desc="Attendance, leave and performance at a glance."
              />
            </div>
          </div>

          <p className="text-xs text-white/60">
            © {new Date().getFullYear()} MUWASCO. All rights reserved.
          </p>
        </div>
      </div>

      {/* Right form panel */}
      <div className="flex-1 flex items-center justify-center px-4 sm:px-6 lg:px-12 py-10">
        <div className="w-full max-w-md">
          {/* Mobile brand */}
          <div className="lg:hidden mb-8 flex items-center gap-3">
            <Logo className="h-14 w-14" />
            <div>
              <p className="text-xs text-slate-500 dark:text-slate-400">Welcome to</p>
              <h1 className="text-base font-semibold text-slate-900 dark:text-slate-100">MUWASCO HR</h1>
            </div>
          </div>

          {/* Card wrapper */}
          <div className="rounded-2xl border border-primary-600 bg-white dark:bg-slate-800 shadow-md shadow-primary-600/80 p-6 sm:p-8">
            <div>
              <h2 className="text-3xl font-bold text-slate-900 dark:text-slate-100">Sign in</h2>
              <p className="mt-2 text-sm text-slate-500">
                Enter your credentials to access the HR portal.
              </p>
            </div>

            {error && (
              <div
                role="alert"
                className="mt-6 flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-700 dark:text-red-300"
              >
                <AlertCircle className="w-5 h-5 mt-0.5 flex-shrink-0" />
                <span>{error}</span>
              </div>
            )}

            <form className="mt-8 space-y-5" onSubmit={handleSubmit} noValidate>
              <div>
                <label
                  htmlFor="email"
                  className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                >
                  Email address
                </label>
                <div className="relative">
                  <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                  <input
                    id="email"
                    type="email"
                    autoComplete="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    onBlur={() => setTouched((t) => ({ ...t, email: true }))}
                    aria-invalid={!!emailError}
                    aria-describedby={emailError ? 'email-error' : undefined}
                    className={`w-full pl-10 pr-3 py-2.5 bg-white dark:bg-slate-900 text-sm dark:text-slate-100 rounded-lg border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 ${emailError ? 'border-red-300' : 'border-slate-200 dark:border-slate-600'
                      }`}
                    placeholder="you@muwasco.co.ke"
                  />
                </div>
                {emailError && (
                  <p id="email-error" className="mt-1 text-xs text-red-600 dark:text-red-400">
                    {emailError}
                  </p>
                )}
              </div>

              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label
                    htmlFor="password"
                    className="block text-sm font-medium text-slate-700 dark:text-slate-300"
                  >
                    Password
                  </label>
                  <button
                    type="button"
                    onClick={() => {
                      /* hook up forgot-password flow here */
                    }}
                    className="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
                  >
                    Forgot password?
                  </button>
                </div>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                  <input
                    id="password"
                    type={showPassword ? 'text' : 'password'}
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    onBlur={() => setTouched((t) => ({ ...t, password: true }))}
                    aria-invalid={!!passwordError}
                    aria-describedby={passwordError ? 'password-error' : undefined}
                    className={`w-full pl-10 pr-10 py-2.5 bg-white dark:bg-slate-900 text-sm dark:text-slate-100 rounded-lg border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 ${passwordError ? 'border-red-300' : 'border-slate-200 dark:border-slate-600'
                      }`}
                    placeholder="Enter your password"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((v) => !v)}
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                    className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300"
                  >
                    {showPassword ? (
                      <EyeOff className="h-5 w-5" />
                    ) : (
                      <Eye className="h-5 w-5" />
                    )}
                  </button>
                </div>
                {passwordError && (
                  <p id="password-error" className="mt-1 text-xs text-red-600 dark:text-red-400">
                    {passwordError}
                  </p>
                )}
              </div>

              <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 select-none cursor-pointer">
                <input
                  type="checkbox"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                  className="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                />
                Keep me signed in on this device
              </label>

              <button
                type="submit"
                disabled={loading}
                className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg shadow-sm text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 active:bg-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed transition"
              >
                {loading ? (
                  <>
                    <span className="h-4 w-4 rounded-full border-2 border-white/40 border-t-white animate-spin" />
                    Signing in…
                  </>
                ) : (
                  <>
                    <LogIn className="h-4 w-4" />
                    Sign in
                  </>
                )}
              </button>
            </form>
          </div>

          <p className="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
            Need help signing in? Contact your HR administrator.
          </p>
        </div>
      </div>
    </div>
  )
}

const Feature = ({ icon, title, desc }) => (
  <div className="flex items-start gap-3 rounded-lg bg-white/10 ring-1 ring-white/15 p-3 backdrop-blur-sm">
    <div className="w-9 h-9 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
      {icon}
    </div>
    <div>
      <p className="text-sm font-semibold" dangerouslySetInnerHTML={{ __html: title }} />
      <p className="text-xs text-white/75">{desc}</p>
    </div>
  </div>
)

export default Login
