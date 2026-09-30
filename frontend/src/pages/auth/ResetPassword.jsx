import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle2,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  Lock,
  ShieldCheck,
} from 'lucide-react';
import Logo from '../../components/Logo';
import { authService } from '../../api/services/authService';

const OTP_LENGTH = 6;

/**
 * Mirrors PasswordResetService::sanitisePassword() exactly.
 *
 * Duplicated deliberately rather than fetched: the client copy is a fast
 * convenience for the user, and the SERVER re-runs every one of these rules
 * before writing anything, so the two can never disagree about what is accepted.
 */
const checkPassword = (value) => {
  if (value.length === 0) return 'Enter a new password.';
  if (value.length < 8) return 'Password must be at least 8 characters.';
  if (value.length > 72) return 'Password must be 72 characters or fewer.';
  if (!/[A-Za-z]/.test(value)) return 'Password must contain at least one letter.';
  if (!/\d/.test(value)) return 'Password must contain at least one number.';
  if (['password', '12345678', 'qwerty123', 'password1', 'letmein1', 'welcome1', 'admin123'].includes(value.toLowerCase())) {
    return 'That password is too common. Please choose another.';
  }
  return null;
};

/** The backend strips NUL / CR / LF / TAB, so the preview must do the same. */
const sanitise = (value) => value.replace(/[\0\r\n\t]/g, '');

const ResetPassword = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') || '';

  const [status, setStatus] = useState('checking'); // checking | invalid | ready
  const [masked, setMasked] = useState(null);
  const [stage, setStage] = useState('otp'); // otp | password | done

  const [otp, setOtp] = useState('');
  const [otpError, setOtpError] = useState('');
  const [verifying, setVerifying] = useState(false);

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [confirmError, setConfirmError] = useState('');
  const [formError, setFormError] = useState('');
  const [saving, setSaving] = useState(false);

  // Used to focus the box the user most likely wants next.
  const otpRef = useRef(null);

  useEffect(() => {
    if (!token) {
      setStatus('invalid');
      return;
    }
    let cancelled = false;
    authService
      .validateResetToken(token)
      .then((res) => {
        if (cancelled) return;
        const valid = res?.data?.valid;
        setStatus(valid ? 'ready' : 'invalid');
        setMasked(res?.data?.masked || null);
      })
      .catch(() => {
        if (!cancelled) setStatus('invalid');
      });
    return () => {
      cancelled = true;
    };
  }, [token]);

  useEffect(() => {
    if (stage === 'otp' && status === 'ready') otpRef.current?.focus();
  }, [stage, status]);

  const handleOtpChange = (e) => {
    // Digits only, capped at 6. Pasting "482 913" yields "482913".
    const digits = e.target.value.replace(/\D/g, '').slice(0, OTP_LENGTH);
    setOtp(digits);
    setOtpError('');
  };

  const submitOtp = async (e) => {
    e.preventDefault();
    setOtpError('');
    if (otp.length !== OTP_LENGTH) {
      setOtpError(`Enter all ${OTP_LENGTH} digits from your email.`);
      return;
    }
    setVerifying(true);
    try {
      await authService.verifyResetOtp(token, otp);
      setStage('password');
    } catch (err) {
      setOtpError(err?.response?.data?.message || 'That code is not correct. Please try again.');
      setOtp('');
    } finally {
      setVerifying(false);
    }
  };

  const submitPassword = async (e) => {
    e.preventDefault();
    const clean = sanitise(password);
    const pwError = checkPassword(clean);
    // Compare the RAW fields, not the sanitised value: trimming must not
    // silently turn two different passwords into a match.
    const mismatch = confirm !== password ? 'The two passwords do not match.' : '';

    setPasswordError(pwError || '');
    setConfirmError(mismatch);
    setFormError('');

    if (pwError || mismatch) return;

    setSaving(true);
    try {
      await authService.resetPassword(token, clean, confirm);
      setStage('done');
    } catch (err) {
      setFormError(err?.response?.data?.message || 'We could not update your password.');
    } finally {
      setSaving(false);
    }
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
            <h2 className="text-4xl font-bold leading-tight">Almost there.</h2>
            <p className="text-white/80 leading-relaxed">
              Confirm the code from your email, then choose a new password. Signing in everywhere
              else will require the new one.
            </p>
            <div className="flex items-start gap-3 rounded-lg bg-white/10 ring-1 ring-white/15 p-3 backdrop-blur-sm">
              <ShieldCheck className="h-5 w-5 shrink-0" />
              <p className="text-sm text-white/80">
                The link alone cannot change your password - the 6-digit code proves you read the
                email yourself.
              </p>
            </div>
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
            <h1 className="text-base font-semibold text-slate-900 dark:text-slate-100">
              MUWASCO HR
            </h1>
          </div>

          <div className="rounded-2xl border border-primary-600 bg-white dark:bg-slate-800 shadow-md shadow-primary-600/80 p-6 sm:p-8">
            {status === 'checking' && (
              <div className="flex flex-col items-center py-10 text-center">
                <Loader2 className="h-8 w-8 animate-spin text-slate-400" />
                <p className="mt-4 text-sm text-slate-500">Checking your reset link...</p>
              </div>
            )}

            {status === 'invalid' && (
              <>
                <div className="flex items-center gap-3">
                  <AlertCircle className="h-9 w-9 text-amber-500" />
                  <h2 className="text-2xl font-bold text-slate-900 dark:text-slate-100">
                    Link expired
                  </h2>
                </div>
                <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">
                  This reset link is no longer valid. Links expire after 30 minutes and can only be
                  used once.
                </p>
                <Link
                  to="/forgot-password"
                  className="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 transition"
                >
                  Request a new link
                </Link>
              </>
            )}

            {status === 'ready' && stage === 'otp' && (
              <>
                <h2 className="text-3xl font-bold text-slate-900 dark:text-slate-100">
                  Enter your code
                </h2>
                <p className="mt-2 text-sm text-slate-500">
                  {masked ? (
                    <>
                      We sent a 6-digit code to <span className="font-medium">{masked}</span>.
                    </>
                  ) : (
                    'We sent a 6-digit code to your email.'
                  )}
                </p>

                {otpError && (
                  <div
                    role="alert"
                    className="mt-6 flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-700 dark:text-red-300"
                  >
                    <AlertCircle className="w-5 h-5 mt-0.5 flex-shrink-0" />
                    <span>{otpError}</span>
                  </div>
                )}

                <form className="mt-8 space-y-5" onSubmit={submitOtp} noValidate>
                  <div>
                    <label
                      htmlFor="otp"
                      className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                    >
                      6-digit verification code
                    </label>
                    <div className="relative">
                      <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                      <input
                        id="otp"
                        ref={otpRef}
                        type="text"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={OTP_LENGTH}
                        value={otp}
                        onChange={handleOtpChange}
                        aria-invalid={!!otpError}
                        aria-describedby={otpError ? 'otp-error' : undefined}
                        className={`w-full pl-10 pr-3 py-3 bg-white dark:bg-slate-900 text-lg tracking-[0.4em] text-center dark:text-slate-100 rounded-lg border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 ${
                          otpError ? 'border-red-300' : 'border-slate-200 dark:border-slate-600'
                        }`}
                        placeholder="000000"
                      />
                    </div>
                    <p className="mt-1.5 text-xs text-slate-400">
                      {otp.length} of {OTP_LENGTH} digits entered
                    </p>
                  </div>

                  <button
                    type="submit"
                    disabled={verifying || otp.length !== OTP_LENGTH}
                    className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg shadow-sm text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 active:bg-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed transition"
                  >
                    {verifying ? (
                      <>
                        <span className="h-4 w-4 rounded-full border-2 border-white/40 border-t-white animate-spin" />
                        Checking...
                      </>
                    ) : (
                      'Verify code'
                    )}
                  </button>
                </form>
              </>
            )}
            {status === 'ready' && stage === 'password' && (
              <>
                <div className="flex items-center gap-2 text-green-600 dark:text-green-400">
                  <CheckCircle2 className="h-5 w-5" />
                  <span className="text-sm font-medium">Code accepted</span>
                </div>
                <h2 className="mt-2 text-3xl font-bold text-slate-900 dark:text-slate-100">
                  Choose a new password
                </h2>
                {masked && (
                  <p className="mt-2 text-sm text-slate-500">
                    For <span className="font-medium">{masked}</span>
                  </p>
                )}

                {formError && (
                  <div
                    role="alert"
                    className="mt-6 flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-700 dark:text-red-300"
                  >
                    <AlertCircle className="w-5 h-5 mt-0.5 flex-shrink-0" />
                    <span>{formError}</span>
                  </div>
                )}

                <form className="mt-6 space-y-5" onSubmit={submitPassword} noValidate>
                  <div>
                    <label
                      htmlFor="new-password"
                      className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                    >
                      New password
                    </label>
                    <div className="relative">
                      <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                      <input
                        id="new-password"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        value={password}
                        onChange={(e) => {
                          setPassword(e.target.value);
                          setPasswordError('');
                        }}
                        aria-invalid={!!passwordError}
                        aria-describedby={passwordError ? 'new-password-error' : undefined}
                        className={`w-full pl-10 pr-10 py-2.5 bg-white dark:bg-slate-900 text-sm dark:text-slate-100 rounded-lg border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 ${
                          passwordError ? 'border-red-300' : 'border-slate-200 dark:border-slate-600'
                        }`}
                        placeholder="At least 8 characters"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword((v) => !v)}
                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                        className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300"
                      >
                        {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                      </button>
                    </div>
                    {passwordError && (
                      <p id="new-password-error" className="mt-1 text-xs text-red-600 dark:text-red-400">
                        {passwordError}
                      </p>
                    )}
                  </div>

                  <div>
                    <label
                      htmlFor="confirm-password"
                      className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5"
                    >
                      Confirm new password
                    </label>
                    <div className="relative">
                      <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                      <input
                        id="confirm-password"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="new-password"
                        value={confirm}
                        onChange={(e) => {
                          setConfirm(e.target.value);
                          setConfirmError('');
                        }}
                        aria-invalid={!!confirmError}
                        aria-describedby={confirmError ? 'confirm-password-error' : undefined}
                        className={`w-full pl-10 pr-3 py-2.5 bg-white dark:bg-slate-900 text-sm dark:text-slate-100 rounded-lg border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 ${
                          confirmError ? 'border-red-300' : 'border-slate-200 dark:border-slate-600'
                        }`}
                        placeholder="Re-enter your new password"
                      />
                    </div>
                    {confirmError && (
                      <p
                        id="confirm-password-error"
                        className="mt-1 text-xs text-red-600 dark:text-red-400"
                      >
                        {confirmError}
                      </p>
                    )}
                  </div>

                  <ul className="rounded-lg bg-slate-50 dark:bg-slate-900/50 p-3 text-xs text-slate-600 dark:text-slate-400 space-y-1">
                    <li>At least 8 characters (72 maximum)</li>
                    <li>At least one letter and one number</li>
                    <li>Avoid common passwords</li>
                  </ul>

                  <button
                    type="submit"
                    disabled={saving}
                    className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg shadow-sm text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 active:bg-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed transition"
                  >
                    {saving ? (
                      <>
                        <span className="h-4 w-4 rounded-full border-2 border-white/40 border-t-white animate-spin" />
                        Saving...
                      </>
                    ) : (
                      'Change password'
                    )}
                  </button>
                </form>
              </>
            )}

            {stage === 'done' && (
              <div className="text-center py-4">
                <CheckCircle2 className="mx-auto h-14 w-14 text-green-600 dark:text-green-400" />
                <h2 className="mt-4 text-2xl font-bold text-slate-900 dark:text-slate-100">
                  Password changed
                </h2>
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
                  Sign in with your new password. Any other signed-in devices have been signed out.
                </p>
                <button
                  type="button"
                  onClick={() => navigate('/login', { replace: true })}
                  className="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 transition"
                >
                  Go to sign in
                </button>
              </div>
            )}

            {status !== 'done' && stage !== 'done' && (
              <div className="mt-6 border-t border-slate-200 dark:border-slate-700 pt-4">
                <Link
                  to="/login"
                  className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-primary-600 dark:text-slate-300 dark:hover:text-primary-400"
                >
                  <ArrowLeft className="h-4 w-4" />
                  Back to sign in
                </Link>
              </div>
            )}
          </div>

          <p className="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
            Need help? Contact your HR administrator.
          </p>
        </div>
      </div>
    </div>
  );
};

export default ResetPassword;