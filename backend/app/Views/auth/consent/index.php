<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta nameviewport" content="width=device-width, initial-scale=1.0">
    <title>Data Protection Consent - MUWASCO HR System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6"
      style="background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);">

    <div class="w-full max-w-2xl mx-auto">
        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[calc(100vh-2rem)] sm:max-h-[calc(100vh-3rem)]">
            <!-- Header -->
            <div class="bg-gradient-to-r from-primary-400 to-primary-600 px-6 sm:px-8 py-6 sm:py-8 flex-shrink-0">
                <div class="flex items-center justify-center gap-3 mb-3">
                    <i class="fas fa-shield-alt text-white text-3xl sm:text-4xl"></i>
                    <h1 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-white">Data Protection Consent</h1>
                </div>
                <p class="text-center text-white/90 text-sm sm:text-base lg:text-lg">
                    Welcome, <span class="font-semibold"><?= htmlspecialchars($pending_name) ?></span>. Please review and accept our data protection terms to continue.
                </p>
            </div>

            <!-- Error Alert -->
            <?php if (!empty($error)): ?>
                <div class="mx-6 sm:mx-8 mt-6 px-4 sm:px-6 py-4 rounded-xl border-2 backdrop-blur-sm
                            bg-gradient-to-r from-error to-red-600 text-white border-error
                            animate-[slideDown_0.4s_ease-out] flex-shrink-0">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-exclamation-circle text-xl mt-0.5"></i>
                        <div>
                            <p class="font-semibold mb-1">Error</p>
                            <p class="text-sm"><?= htmlspecialchars($error) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Scrollable Content -->
            <div class="flex-1 overflow-y-auto px-6 sm:px-8 py-6 sm:py-8">
                <!-- Identity Verification Notice -->
                <div class="bg-blue-500/10 border border-blue-400/30 rounded-xl p-4 sm:p-6 mb-6 sm:mb-8">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-id-card text-blue-400 text-xl sm:text-2xl mt-1"></i>
                        <div>
                            <h3 class="text-base sm:text-lg font-semibold text-blue-300 mb-2">Identity Verification Required</h3>
                            <p class="text-sm text-gray-300 leading-relaxed">
                                For security and compliance purposes, please enter your National ID number exactly as it appears in your employee records. This information will be securely stored and used only for identity verification.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Consent Document -->
                <div class="bg-white/5 border border-white/10 rounded-xl p-6 sm:p-8 mb-6 sm:mb-8">
                    <h2 class="text-xl sm:text-2xl font-bold text-white mb-4 sm:mb-6">Employee Data Protection and Consent Policy</h2>

                    <div class="text-gray-300 space-y-6 text-sm sm:text-base leading-relaxed">
                        <p class="text-gray-400 italic">
                            This policy complies with the Kenya Data Protection Act, 2019 and ensures your personal information is collected, processed, and protected in accordance with applicable laws and regulations.
                        </p>

                        <div>
                            <h3 class="text-lg sm:text-xl font-semibold text-white mt-6 mb-3">1. Purpose of Data Collection</h3>
                            <p class="mb-3">We collect and process your personal data for the following legitimate purposes:</p>
                            <ul class="list-disc list-inside space-y-2 ml-4">
                                <li><strong>Payroll Processing:</strong> Salary calculations, tax deductions, and benefits administration</li>
                                <li><strong>Attendance Management:</strong> Work hours tracking, leave management, and overtime calculations</li>
                                <li><strong>Performance Management:</strong> Appraisals, goal tracking, and professional development</li>
                                <li><strong>HR Administration:</strong> Employee records, compliance reporting, and organizational planning</li>
                                <li><strong>Legal Compliance:</strong> Statutory deductions, tax reporting, and regulatory requirements</li>
                                <li><strong>System Security:</strong> Access control, authentication, and audit logging</li>
                            </ul>
                        </div>

                        <div>
                            <h3 class="text-lg sm:text-xl font-semibold text-white mt-6 mb-3">2. Types of Data Collected</h3>
                            <p class="mb-3">We collect and process the following categories of personal data:</p>
                            <ul class="list-disc list-inside space-y-2 ml-4">
                                <li><strong>Personal Identification:</strong> Full name, national ID number, email address, phone number</li>
                                <li><strong>Employment Information:</strong> Job title, department, employment dates, designation</li>
                                <li><strong>Financial Data:</strong> Salary information, bank details, tax identification numbers</li>
                                <li><strong>Attendance Records:</strong> Clock-in/out times, leave balances, overtime hours</li>
                                <li><strong>Performance Data:</strong> Appraisal scores, goals, training records</li>
                                <li><strong>System Access Logs:</strong> Login timestamps, IP addresses, device fingerprints for security</li>
                            </ul>
                        </div>

                        <div>
                            <h3 class="text-lg sm:text-xl font-semibold text-white mt-6 mb-3">3. Your Rights Under Kenyan Law</h3>
                            <p class="mb-3">In accordance with the Data Protection Act, 2019, you have the right to:</p>
                            <ul class="list-disc list-inside space-y-2 ml-4">
                                <li><strong>Right to Access:</strong> Request copies of your personal data held by the organization</li>
                                <li><strong>Right to Correction:</strong> Request correction of inaccurate or incomplete data</li>
                                <li><strong>Right to Object:</strong> Object to processing of your data for direct marketing or legitimate interests</li>
                                <li><strong>Right to Erasure:</strong> Request deletion of your data (subject to legal retention requirements)</li>
                                <li><strong>Right to Data Portability:</strong> Request transfer of your data to another service provider</li>
                                <li><strong>Right to Withdraw Consent:</strong> Withdraw consent at any time (may affect system access)</li>
                            </ul>
                        </div>

                        <div>
                            <h3 class="text-lg sm:text-xl font-semibold text-white mt-6 mb-3">4. Data Security Measures</h3>
                            <p class="mb-3">We implement comprehensive security measures to protect your personal data:</p>
                            <ul class="list-disc list-inside space-y-2 ml-4">
                                <li><strong>Encryption:</strong> Data is encrypted in transit (TLS/SSL) and at rest</li>
                                <li><strong>Access Control:</strong> Role-based access with multi-factor authentication</li>
                                <li><strong>Audit Logging:</strong> All data access and modifications are logged and monitored</li>
                                <li><strong>Secure Infrastructure:</strong> Regular security updates, firewalls, and intrusion detection</li>
                                <li><strong>Employee Training:</strong> Regular data protection and privacy training for staff</li>
                                <li><strong>Incident Response:</strong> Established procedures for data breach notification and response</li>
                            </ul>
                        </div>

                        <div>
                            <h3 class="text-lg sm:text-xl font-semibold text-white mt-6 mb-3">5. Data Retention and Disposal</h3>
                            <p class="leading-relaxed">
                                Your personal data is retained for the duration of your employment and for a period thereafter as required by Kenyan law (maximum 7 years for employment records). After the retention period, data is securely destroyed using industry-standard methods.
                            </p>
                        </div>

                        <div>
                            <h3 class="text-lg sm:text-xl font-semibold text-white mt-6 mb-3">6. Contact Information</h3>
                            <p class="mb-3">For questions, concerns, or to exercise your data protection rights, please contact:</p>
                            <div class="bg-white/5 border border-white/10 rounded-lg p-4">
                                <p class="font-semibold text-white">Data Protection Officer</p>
                                <p class="text-sm text-gray-300">MUWASCO HR Department</p>
                                <p class="text-sm text-gray-300">Email: dpo@muwasco.co.ke</p>
                                <p class="text-sm text-gray-300">Phone: +254 (0) 20 123 4567</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Consent Form (Fixed at bottom) -->
            <form method="POST" action="<?= BASE_URL ?>/consent/submit" id="consentForm" class="border-t border-white/10 p-6 sm:p-8 flex-shrink-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <!-- Personal Information -->
                <div class="bg-white/5 border border-white/10 rounded-xl p-6 sm:p-8 mb-6">
                    <h3 class="text-lg sm:text-xl font-semibold text-white mb-4 sm:mb-6">Identity Verification</h3>

                    <div class="space-y-4">
                        <div>
                            <label for="full_name" class="block mb-2 text-white font-semibold text-sm uppercase tracking-wider">
                                Full Name <span class="text-red-400">*</span>
                            </label>
                            <input type="text" id="full_name" name="full_name"
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? $pending_name) ?>"
                                   required placeholder="Enter your full legal name as per ID"
                                   class="w-full px-4 py-3 bg-white/5 border-2 border-white/10 rounded-xl
                                          text-white text-sm sm:text-base transition-all duration-300 backdrop-blur-sm
                                          focus:outline-none focus:border-primary-400 focus:bg-white/10
                                          placeholder:text-gray-500">
                        </div>

                        <div>
                            <label for="national_id" class="block mb-2 text-white font-semibold text-sm uppercase tracking-wider">
                                National ID Number <span class="text-red-400">*</span>
                            </label>
                            <input type="text" id="national_id" name="national_id"
                                   value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>"
                                   required placeholder="Enter your national ID number (numbers only)"
                                   pattern="[0-9\s\-]+"
                                   class="w-full px-4 py-3 bg-white/5 border-2 border-white/10 rounded-xl
                                          text-white text-sm sm:text-base transition-all duration-300 backdrop-blur-sm
                                          focus:outline-none focus:border-primary-400 focus:bg-white/10
                                          placeholder:text-gray-500">
                            <p class="text-xs text-gray-400 mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Enter your ID as it appears on your national ID card
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Consent Agreement -->
                <div class="bg-gradient-to-r from-primary-400/10 to-blue-500/10 border-2 border-primary-400/30 rounded-xl p-6 sm:p-8 mb-6">
                    <div class="flex items-start gap-3 sm:gap-4">
                        <input type="checkbox" name="consent_accepted" value="1" required
                               id="consent_checkbox"
                               class="mt-1 w-5 h-5 sm:w-6 sm:h-6 rounded border-white/30 bg-white/5
                                      text-primary-400 focus:ring-primary-400 focus:ring-offset-0 cursor-pointer flex-shrink-0">
                        <label for="consent_checkbox" class="cursor-pointer">
                            <span class="text-white font-semibold text-sm sm:text-base lg:text-lg leading-relaxed">
                                I have read, understood, and agree to the Data Protection and Consent Policy
                            </span>
                            <p class="text-gray-400 text-xs sm:text-sm mt-2 leading-relaxed">
                                By checking this box, I consent to the collection, processing, and storage of my personal data as described above. I understand my rights under the Kenya Data Protection Act, 2019 and how to exercise them.
                            </p>
                        </label>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-3 sm:gap-4">
                    <button type="submit" id="submitBtn"
                            class="flex-1 inline-flex items-center justify-center gap-2 px-6 sm:px-8 py-4 sm:py-5
                                   border-2 border-transparent rounded-xl text-base sm:text-lg font-semibold
                                   uppercase tracking-wider transition-all duration-300 bg-gradient-to-r
                                   from-primary-400 to-primary-600 text-white border-primary-400
                                   shadow-[0_6px_20px_rgba(0,212,255,0.4)]
                                   hover:-translate-y-1 hover:shadow-[0_10px_30px_rgba(0,212,255,0.5)]
                                   active:translate-y-0">
                        <i class="fas fa-check-circle text-lg sm:text-xl"></i>
                        <span>Accept and Continue to Dashboard</span>
                    </button>

                    <a href="<?= BASE_URL ?>/auth/logout"
                       class="inline-flex items-center justify-center gap-2 px-6 sm:px-8 py-4 sm:py-5
                              border-2 border-red-400/50 rounded-xl text-base sm:text-lg font-semibold
                              text-red-400 hover:bg-red-400/10 transition-all duration-300">
                        <i class="fas fa-times-circle text-lg sm:text-xl"></i>
                        <span>Cancel</span>
                    </a>
                </div>

                <!-- Footer Note -->
                <div class="mt-6 text-center">
                    <p class="text-gray-500 text-xs sm:text-sm">
                        <i class="fas fa-lock mr-1"></i>
                        Your information is protected under the Kenya Data Protection Act, 2019
                    </p>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const nameField = document.getElementById('full_name');
            const nationalIdField = document.getElementById('national_id');
            const consentForm = document.getElementById('consentForm');
            const submitBtn = document.getElementById('submitBtn');
            const consentCheckbox = document.getElementById('consent_checkbox');

            // Auto-focus
            if (nameField && !nameField.value) {
                nameField.focus();
            }

            // National ID input validation - numbers only
            nationalIdField.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9\s\-]/g, '');
            });

            // Form submission validation
            consentForm.addEventListener('submit', function (e) {
                const nid = nationalIdField.value.replace(/[^0-9]/g, '');
                
                if (nid.length < 5) {
                    e.preventDefault();
                    nationalIdField.focus();
                    alert('Please enter a valid National ID with at least 5 digits.');
                    return;
                }

                if (nameField.value.trim().length < 3) {
                    e.preventDefault();
                    nameField.focus();
                    alert('Please enter your full name.');
                    return;
                }

                if (!consentCheckbox.checked) {
                    e.preventDefault();
                    alert('Please accept the data protection terms to continue.');
                    return;
                }

                // Show loading state
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                submitBtn.style.opacity = '0.7';
                submitBtn.style.pointerEvents = 'none';
            });

            // Prevent form resubmission on back/forward
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>