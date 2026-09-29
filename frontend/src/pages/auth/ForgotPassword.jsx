import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AlertCircle, ArrowLeft, CheckCircle2, Mail, Send } from 'lucide-react';
import Logo from '../../components/Logo';
import { authService } from '../../api/services/authService';

/**
 * Step 1 of the reset flow: collect the email and ask for a link + code.
 *
 * The backend deliberately returns the SAME message whether or not the address
 * is registered, so this page must never imply success or failure beyond that
 * neutral wording - doing so would recreate the account-enumeration oracle the
 * backend works to avoid.
 */
const ForgotPassword = () => {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [touched, setTouched] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);

  const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
  const emailError = touched && !emailValid ? 'Please enter a valid email address' : '';

  const send = async () => {
    setError('');
    setMessage('');
    setLoading(true);
    try {
      const res = await authService.forgotPassword(email.trim());
      setMessage(
        res?.message ||
          'If that email address belongs to an active staff account, a reset link and verification code are on their way.',
      );
    } catch (err) {
      setError(
        err?.response?.data?.message ||
          'We could not process that request. Please try again in a few minutes.',
      );
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    setTouched(true);
    if (!emailValid) return;
    send();
  };

  return (
    <div className="min-h-screen w-full flex bg-slate-50 dark:bg-slate-900">
      <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-gradient-to-br from-primary-700 via-primary-600 to-primary-800 text-white">
        <div className="absolute -top-32 -left-32 w-96 h-96 rounded-full bg-white/10 blur-3xl" />
        <div className="absolute -bottom-40 -right-20 w-[28rem] h-[28rem] rounded-full bg-primary-400/30 blur-3xl" />

        <div className="relative z-10 flex flex-col justify-between p-12 w-full">
          <div className="flex items-center gap-3">
            <Logo className="h-14 w-14" />
            <div>
              <p className="text-sm text-white/70">Welcome to</p>
              <h1 className="text-lg font-semibold tracking-wide">MUWASCO HR</h1>
            </div>
          </div>

          <div className="space-y-6 max-w-md">
            <h2 className="text-4xl font-bold leading-tight">Locked out?</h2>
            <p className="text-white/80 leading-relaxed">
              Tell us the email on your staff account. We will send a reset link and a 6-digit
              verification code to prove you control that mailbox.
            </p>
            <ol className="space-y-3 text-sm text-white/75">
              {[
                'Open the link we email you.',
                'Enter the 6-digit code from the same email.',
                'Choose a new password and sign in.',
              ].map((step, i) => (
                <li key={i} className="flex items-start gap-3">
                  <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/15 text-xs font-semibold">
                    {i + 1}
                  </span>
                  {step}
                </li>
              ))}
            </ol>
          </div>

          <p className="text-xs text-white/60">
            &copy; {new Date().getFullYear()} MUWASCO. All rights reserved.
          </p>
        </div>
      </div>

      <div className="flex-1 flex items-center justify-center px-4 sm:px-6 lg:px-12 py-10">
        <div className="w-full max-w-md">
          <div className="lg:hidden mb-8 flex items-center gap-3">
            <Logo className="h-14 w-14" />
            <div>
              <p className="text-xs text-slate-500 dark:text-slate-400">Welcome to</p>
              <h1 className="text-base font-semibold text-slate-900 dark:text-slate-100">
                MUWASCO HR
              </h1>
            </div>
          </div>

          <div className="rounded-2xl border border-primary-600 bg-white dark:bg-slate-800 shadow-md shadow-primary-600/80 p-6 sm:p-8">
            {message ? (
              <>
                <div className="flex items-center gap-3">
                  <CheckCircle2 className="h-9 w-9 text-green-600 dark:text-green-400" />
                  <h2 className="text-2xl font-bold text-slate-900 dark:text-slate-100">
                    Check your email
                  </h2>
                </div>
                <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">{message}</p>
                <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">
                  The code expires in 30 minutes and can be used once. Open the link from that email
                  on this device.
                </p>

                {error && (
                  <div
                    role="alert"
                    className="mt-5 flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-700 dark:text-red-300"
                  >
                    <AlertCircle className="w-5 h-5 mt-0.5 flex-shrink-0" />
                    <span>{error}</span>
                  </div>
                )}

                <div className="mt-6 flex flex-col gap-2 sm:flex-row">
                  <button
                    type="button"
                    onClick={send}
                    disabled={loading}
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg border border-slate-300 dark:border-slate-600 px-4 py-2.5 text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 disabled:opacity-60 transition"
                  >
                    {loading ? 'Sending...' : 'Send again'}
                  </button>
                  <button
                    type="button"
                    onClick={() => navigate('/login')}
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 transition"
                  >
                    Back to sign in
                  </button>
                </div>
              </>
            ) : (
              <>
                <h2 className="text-3xl font-bold text-slate-900 dark:text-slate-100">
                  Reset your password
                </h2>
                <p className="mt-2 text-sm text-slate-500">
                  Enter your work email and we will send a reset link with a 6-digit code.
                </p>

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
                      htmlFor="reset-email"
                      className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                    >
                      Work email address
                    </label>
                    <div className="relative">
                      <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                      <input
                        id="reset-email"
                        type="email"
                        autoComplete="email"
                        autoFocus
                        required
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        onBlur={() => setTouched(true)}
                        aria-invalid={!!emailError}
                        aria-describedby={emailError ? 'reset-email-error' : undefined}
                        className={`w-full pl-10 pr-3 py-2.5 bg-white dark:bg-slate-900 text-sm dark:text-slate-100 rounded-lg border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 ${
                          emailError ? 'border-red-300' : 'border-slate-200 dark:border-slate-600'
                        }`}
                        placeholder="you@muwasco.co.ke"
                      />
                    </div>
                    {emailError && (
                      <p id="reset-email-error" className="mt-1 text-xs text-red-600 dark:text-red-400">
                        {emailError}
                      </p>
                    )}
                  </div>

                  <button
                    type="submit"
                    disabled={loading}
                    className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg shadow-sm text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 active:bg-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed transition"
                  >
                    {loading ? (
                      <>
                        <span className="h-4 w-4 rounded-full border-2 border-white/40 border-t-white animate-spin" />
                        Sending...
                      </>
                    ) : (
                      <>
                        <Send className="h-4 w-4" />
                        Send reset link
                      </>
                    )}
                  </button>
                </form>
              </>
            )}

            <div className="mt-6 border-t border-slate-200 dark:border-slate-700 pt-4">
              <Link
                to="/login"
                className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-primary-600 dark:text-slate-300 dark:hover:text-primary-400"
              >
                <ArrowLeft className="h-4 w-4" />
                Back to sign in
              </Link>
            </div>
          </div>

          <p className="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
            Need help? Contact your HR administrator.
          </p>
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;