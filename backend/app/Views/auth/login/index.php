<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Login · MUWASCO HR System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-dark-bg flex items-center justify-center min-h-screen relative overflow-hidden">

    <!-- Animated Background Orbs -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute w-[600px] h-[600px] -top-[15%] -left-[10%] rounded-full opacity-30 animate-float"
             style="background: radial-gradient(circle, rgba(0,212,255,0.25), transparent); filter: blur(80px);"></div>
        <div class="absolute w-[500px] h-[500px] -bottom-[20%] -right-[10%] rounded-full opacity-30"
             style="background: radial-gradient(circle, rgba(108,92,231,0.2), transparent); filter: blur(80px); animation: float 20s ease-in-out infinite; animation-delay: -7s;"></div>
        <div class="absolute w-[400px] h-[400px] top-2/5 left-1/2 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-20"
             style="background: radial-gradient(circle, rgba(255,51,102,0.15), transparent); filter: blur(80px); animation: float 20s ease-in-out infinite; animation-delay: -14s;"></div>
    </div>

    <!-- Grid Overlay -->
    <div class="fixed inset-0 z-[1] pointer-events-none"
         style="background-image:
            linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 60px 60px;"></div>

    <!-- Floating Particles -->
    <div class="fixed inset-0 z-[1] pointer-events-none overflow-hidden">
        <?php
        $particles = [
            ['left' => '10%', 'dur' => '18s', 'delay' => '0s', 'bg' => '#00d4ff', 'w' => '3px', 'h' => '3px'],
            ['left' => '25%', 'dur' => '22s', 'delay' => '-3s', 'bg' => '#00d4ff', 'w' => '2px', 'h' => '2px'],
            ['left' => '45%', 'dur' => '20s', 'delay' => '-7s', 'bg' => '#6c5ce7', 'w' => '3px', 'h' => '3px'],
            ['left' => '65%', 'dur' => '24s', 'delay' => '-11s', 'bg' => '#00d4ff', 'w' => '4px', 'h' => '4px'],
            ['left' => '80%', 'dur' => '19s', 'delay' => '-5s', 'bg' => '#ff3366', 'w' => '3px', 'h' => '3px'],
            ['left' => '35%', 'dur' => '21s', 'delay' => '-9s', 'bg' => '#00d4ff', 'w' => '2px', 'h' => '2px'],
            ['left' => '55%', 'dur' => '17s', 'delay' => '-13s', 'bg' => '#00d4ff', 'w' => '3px', 'h' => '3px'],
            ['left' => '90%', 'dur' => '23s', 'delay' => '-2s', 'bg' => '#6c5ce7', 'w' => '3px', 'h' => '3px'],
        ];
        foreach ($particles as $p): ?>
            <div class="absolute rounded-full"
                 style="left: <?= $p['left'] ?>; width: <?= $p['w'] ?>; height: <?= $p['h'] ?>; background: <?= $p['bg'] ?>; opacity: 0.4; animation: drift <?= $p['dur'] ?> linear infinite; animation-delay: <?= $p['delay'] ?>;"></div>
        <?php endforeach; ?>
    </div>

    <!-- Login Card Wrapper -->
    <div class="relative z-10 w-full max-w-[440px] p-5 animate-fadeIn">
        <div class="relative bg-dark-card backdrop-blur-2xl border border-white/10 rounded-[24px] px-9 pt-10 pb-8 overflow-hidden"
             style="box-shadow: 0 24px 80px rgba(0,0,0,0.6), inset 0 1px 0 rgba(255,255,255,0.05);">

            <!-- Shimmer Border Top -->
            <div class="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-primary-400 via-secondary-400 via-accent to-primary-400 shimmer-border"></div>

            <!-- Logo & Brand -->
            <div class="text-center mb-8">
                <div class="w-[88px] h-[88px] mx-auto mb-4 rounded-[20px] flex items-center justify-center p-3 transition-all duration-300 hover:scale-105 hover:-rotate-2 hover:shadow-[0_0_40px_rgba(0,212,255,0.2)]"
                     style="background: linear-gradient(135deg, rgba(0,212,255,0.15), rgba(108,92,231,0.15)); border: 1px solid rgba(255,255,255,0.08);">
                    <img src="<?= BASE_URL ?>/frontend/assets/images/muwascologo.png" alt="MUWASCO Logo" class="w-full h-full object-contain rounded-xl" loading="lazy">
                </div>
                <h1 class="text-[22px] font-bold text-white -tracking-[0.3px] mb-1">HR Management System</h1>
                <p class="text-[13px] text-white/45 font-normal">Muranga Water & Sanitation Co. Ltd</p>
                <div class="inline-flex items-center gap-1.5 mt-3 px-3.5 py-1 rounded-full text-[11px] font-semibold tracking-[0.5px] bg-primary-400/10 text-primary-400 border border-primary-400/20">
                    <i class="fas fa-lock text-[10px]"></i> Secure Employee Portal
                </div>
            </div>

            <!-- Error Alert -->
            <?php if (!empty($error)): ?>
                <div class="flex items-start gap-3 p-3.5 rounded-xl bg-error/15 border border-error/30 text-[#f5a690] text-sm leading-relaxed mb-6 animate-slideDown"
                     id="errorAlert">
                    <i class="fas fa-exclamation-triangle text-error shrink-0 mt-0.5"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="<?= BASE_URL ?>/login/authenticate" id="loginForm" autocomplete="on" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <!-- Email -->
                <div class="mb-5" id="emailGroup">
                    <label for="email" class="block text-xs font-semibold text-white/60 tracking-[0.8px] uppercase mb-2">Email Address</label>
                    <div class="relative">
                        <i class="fas fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-white/25 text-[15px] pointer-events-none transition-colors duration-300 peer-focus:text-primary-400"></i>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               autocomplete="email"
                               placeholder="you@muwasco.co.ke"
                               class="peer w-full pl-11 pr-4 py-3.5 bg-white/5 border-[1.5px] border-white/10 rounded-xl text-white text-sm font-sans outline-none transition-all duration-300 placeholder:text-white/20 focus:border-primary-400/50 focus:bg-white/6 focus:shadow-[0_0_0_4px_rgba(0,212,255,0.08)] focus:-translate-y-px">
                    </div>
                    <p class="text-xs text-error mt-1.5 hidden" id="emailError">Email is required</p>
                </div>

                <!-- Password -->
                <div class="mb-5" id="passwordGroup">
                    <label for="password" class="block text-xs font-semibold text-white/60 tracking-[0.8px] uppercase mb-2">Password</label>
                    <div class="relative">
                        <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-white/25 text-[15px] pointer-events-none transition-colors duration-300 peer-focus:text-primary-400"></i>
                        <input type="password" id="password" name="password"
                               autocomplete="current-password"
                               placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                               class="peer w-full pl-11 pr-11 py-3.5 bg-white/5 border-[1.5px] border-white/10 rounded-xl text-white text-sm font-sans outline-none transition-all duration-300 placeholder:text-white/20 focus:border-primary-400/50 focus:bg-white/6 focus:shadow-[0_0_0_4px_rgba(0,212,255,0.08)] focus:-translate-y-px">
                        <button type="button" id="togglePassword" aria-label="Toggle password visibility"
                                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-white/25 hover:text-white/50 bg-transparent border-none cursor-pointer p-1 text-[15px] transition-colors duration-300">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <p class="text-xs text-error mt-1.5 hidden" id="passwordError">Password is required</p>
                </div>

                <!-- Validation Summary -->
                <div class="hidden mb-4 p-3 rounded-xl bg-error/15 border border-error/30 text-[#f5a690] text-sm animate-slideDown" id="validationSummary"></div>

                <!-- Submit -->
                <button type="submit" id="submitBtn"
                        class="w-full inline-flex items-center justify-center gap-2.5 px-6 py-[15px] border-none rounded-xl font-sans text-sm font-semibold tracking-[0.5px] text-white cursor-pointer relative overflow-hidden mt-2 transition-all duration-300 active:translate-y-0"
                        style="background: linear-gradient(135deg, #00d4ff, #0099cc);">
                    <span class="relative z-10 flex items-center gap-2.5">
                        <i class="fas fa-arrow-right-to-bracket btn-icon"></i>
                        <span class="btn-text">Sign In</span>
                        <div class="hidden w-[18px] h-[18px] border-[2.5px] border-white/30 border-t-white rounded-full animate-spin spinner"></div>
                    </span>
                </button>
            </form>

            <!-- Footer -->
            <div class="text-center mt-6">
                <p class="text-xs text-white/30">
                    <i class="fas fa-life-ring mr-1.5"></i>
                    Need help? Contact
                    <a href="mailto:support@muwasco.co.ke" class="text-primary-400/60 hover:text-primary-400 no-underline transition-colors duration-300">HR Support</a>
                </p>
            </div>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('loginForm');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const emailGroup = document.getElementById('emailGroup');
            const passwordGroup = document.getElementById('passwordGroup');
            const emailError = document.getElementById('emailError');
            const passwordError = document.getElementById('passwordError');
            const summary = document.getElementById('validationSummary');
            const submitBtn = document.getElementById('submitBtn');

            // ─── Validation helpers ────────────────────────────
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            function setFieldError(group, input, errorEl, message) {
                errorEl.textContent = message;
                errorEl.classList.remove('hidden');
                input.classList.remove('border-white/10', 'focus:border-primary-400/50');
                input.classList.add('border-error/60', 'focus:border-error');
                group.querySelector('.input-icon')?.classList.add('text-error');
            }

            function clearFieldError(group, input, errorEl) {
                errorEl.classList.add('hidden');
                input.classList.remove('border-error/60', 'focus:border-error');
                input.classList.add('border-white/10', 'focus:border-primary-400/50');
                group.querySelector('.input-icon')?.classList.remove('text-error');
            }

            function validateEmail() {
                const val = emailInput.value.trim();
                if (val === '') {
                    setFieldError(emailGroup, emailInput, emailError, 'Email is required');
                    return false;
                }
                if (!emailRegex.test(val)) {
                    setFieldError(emailGroup, emailInput, emailError, 'Please enter a valid email address');
                    return false;
                }
                clearFieldError(emailGroup, emailInput, emailError);
                return true;
            }

            function validatePassword() {
                const val = passwordInput.value.trim();
                if (val === '') {
                    setFieldError(passwordGroup, passwordInput, passwordError, 'Password is required');
                    return false;
                }
                if (val.length < 6) {
                    setFieldError(passwordGroup, passwordInput, passwordError, 'Password must be at least 6 characters');
                    return false;
                }
                clearFieldError(passwordGroup, passwordInput, passwordError);
                return true;
            }

            // ─── Real-time validation on blur ─────────────────
            emailInput.addEventListener('blur', validateEmail);
            passwordInput.addEventListener('blur', validatePassword);

            // Clear errors on input
            emailInput.addEventListener('input', function () {
                if (this.value.trim() !== '') {
                    clearFieldError(emailGroup, emailInput, emailError);
                    summary.classList.add('hidden');
                }
            });
            passwordInput.addEventListener('input', function () {
                if (this.value.trim() !== '') {
                    clearFieldError(passwordGroup, passwordInput, passwordError);
                    summary.classList.add('hidden');
                }
            });

            // ─── Form submission validation ────────────────────
            form.addEventListener('submit', function (e) {
                const isEmailValid = validateEmail();
                const isPasswordValid = validatePassword();

                if (!isEmailValid || !isPasswordValid) {
                    e.preventDefault();

                    // Show summary
                    const errors = [];
                    if (!isEmailValid) errors.push('- Email is required');
                    if (!isPasswordValid) errors.push('- Password is required');
                    summary.innerHTML = '<i class="fas fa-exclamation-triangle mr-1.5"></i>Please fix the following:<br>' + errors.join('<br>');
                    summary.classList.remove('hidden');

                    // Scroll to first error
                    if (!isEmailValid) {
                        emailInput.focus();
                    } else {
                        passwordInput.focus();
                    }
                    return;
                }

                // ── All valid — show loading state ─────────────
                submitBtn.querySelector('.btn-icon').style.display = 'none';
                submitBtn.querySelector('.btn-text').style.display = 'none';
                submitBtn.querySelector('.spinner').style.display = 'inline-block';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.8';
                submitBtn.style.pointerEvents = 'none';
            });

            // ─── Auto-focus ────────────────────────────────────
            if (emailInput && !emailInput.value) {
                emailInput.focus();
            } else if (passwordInput) {
                passwordInput.focus();
            }

            // ─── Toggle password visibility ────────────────────
            document.getElementById('togglePassword').addEventListener('click', function () {
                const icon = this.querySelector('i');
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.className = 'fas fa-eye-slash';
                } else {
                    passwordInput.type = 'password';
                    icon.className = 'fas fa-eye';
                }
            });

            // ─── Auto-dismiss server error alert ───────────────
            const alertEl = document.getElementById('errorAlert');
            if (alertEl) {
                setTimeout(function () {
                    alertEl.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                    alertEl.style.opacity = '0';
                    alertEl.style.transform = 'translateY(-12px)';
                    setTimeout(function () { alertEl.remove(); }, 700);
                }, 6000);
            }

            // ─── Prevent form resubmission on back/forward ─────
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            // ─── Ctrl+Enter shortcut ───────────────────────────
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    form.requestSubmit();
                }
            });
        });
    </script>

</body>
</html>