<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>{{ config('admin.title') }} | Register</title>
    
    @if(!is_null($favicon = Admin::favicon()))
    <link rel="shortcut icon" href="{{ $favicon }}">
    @endif

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .register-container {
            width: 100%;
            max-width: 600px;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin: 20px auto;
        }

        .register-header {
            background: #dc2626;
            padding: 30px;
            text-align: center;
            color: white;
        }

        .register-logo {
            width: 50px;
            height: 50px;
            margin: 0 auto 12px;
            background: rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .register-logo svg {
            width: 28px;
            height: 28px;
            fill: white;
        }

        .register-header h1 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .register-header p {
            font-size: 13px;
            opacity: 0.9;
            font-weight: 400;
        }

        .register-body {
            padding: 30px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #dc2626;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 6px;
        }

        .form-group label .required {
            color: #dc2626;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            opacity: 0.4;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            border: 1px solid #d1d5db;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
            background: #ffffff;
        }

        .form-control.has-icon {
            padding-left: 38px;
        }

        .form-control:focus {
            border-color: #dc2626;
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        select.form-control {
            cursor: pointer;
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .error-message {
            color: #dc2626;
            font-size: 12px;
            margin-top: 4px;
        }

        .has-error .form-control {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-left: 3px solid #dc2626;
            background: #fef2f2;
            color: #991b1b;
            font-size: 13px;
        }

        .btn-register {
            width: 100%;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: 600;
            color: white;
            background: #dc2626;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
            font-family: inherit;
            margin-top: 8px;
        }

        .btn-register:hover:not(:disabled) {
            background: #b91c1c;
        }

        .btn-register:active:not(:disabled) {
            background: #991b1b;
        }

        .btn-register:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-register.loading {
            position: relative;
            color: transparent;
        }

        .btn-register.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .register-footer {
            padding: 20px 30px;
            background: #fafafa;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }

        .register-footer p {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }

        .register-footer a {
            color: #dc2626;
            text-decoration: none;
            font-weight: 500;
        }

        .register-footer a:hover {
            text-decoration: underline;
        }

        .divider {
            height: 1px;
            background: #e5e7eb;
            margin: 24px 0;
        }

        /* Responsive */
        @media (max-width: 640px) {
            body {
                padding: 0;
                background: #ffffff;
            }

            .register-container {
                box-shadow: none;
                border: none;
                min-height: 100vh;
                max-width: 100%;
                display: flex;
                flex-direction: column;
            }

            .register-header {
                padding: 24px 20px;
            }

            .register-body {
                padding: 24px 20px;
                flex: 1;
            }

            .register-footer {
                padding: 16px 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }
        }
    </style>
</head>

<body>
    <div class="register-container" id="registerContainer">
        <!-- Header -->
        <div class="register-header">
            <div class="register-logo">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/>
                </svg>
            </div>
            <h1>Create Your Account</h1>
            <p>Start managing your business today</p>
        </div>

        <!-- Body -->
        <div class="register-body">
            <!-- Global Errors -->
            @if($errors->has('error'))
                <div class="alert">
                    <strong>Error:</strong> {{ $errors->first('error') }}
                </div>
            @endif

            <form action="{{ url('auth/register') }}" method="post" id="registerForm">
                @csrf
                
                <!-- Personal Information Section -->
                <div class="section-title">Personal Information</div>
                
                <div class="form-row">
                    <div class="form-group @if($errors->has('first_name')) has-error @endif">
                        <label for="first_name">First Name <span class="required">*</span></label>
                        <input 
                            type="text" 
                            class="form-control" 
                            id="first_name" 
                            name="first_name" 
                            value="{{ old('first_name') }}" 
                            placeholder="Enter first name"
                            required
                            autofocus>
                        @if($errors->has('first_name'))
                            <div class="error-message">{{ $errors->first('first_name') }}</div>
                        @endif
                    </div>

                    <div class="form-group @if($errors->has('last_name')) has-error @endif">
                        <label for="last_name">Last Name <span class="required">*</span></label>
                        <input 
                            type="text" 
                            class="form-control" 
                            id="last_name" 
                            name="last_name" 
                            value="{{ old('last_name') }}" 
                            placeholder="Enter last name"
                            required>
                        @if($errors->has('last_name'))
                            <div class="error-message">{{ $errors->first('last_name') }}</div>
                        @endif
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group @if($errors->has('email')) has-error @endif">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                            </svg>
                            <input 
                                type="email" 
                                class="form-control has-icon" 
                                id="email" 
                                name="email" 
                                value="{{ old('email') }}" 
                                placeholder="your@email.com"
                                required>
                        </div>
                        @if($errors->has('email'))
                            <div class="error-message">{{ $errors->first('email') }}</div>
                        @endif
                    </div>

                    <div class="form-group @if($errors->has('phone_number')) has-error @endif">
                        <label for="phone_number">Phone Number <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                            </svg>
                            <input 
                                type="tel" 
                                class="form-control has-icon" 
                                id="phone_number" 
                                name="phone_number" 
                                value="{{ old('phone_number') }}" 
                                placeholder="+256 700 000000"
                                required>
                        </div>
                        @if($errors->has('phone_number'))
                            <div class="error-message">{{ $errors->first('phone_number') }}</div>
                        @endif
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group @if($errors->has('password')) has-error @endif">
                        <label for="password">Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                            </svg>
                            <input 
                                type="password" 
                                class="form-control has-icon" 
                                id="password" 
                                name="password" 
                                placeholder="Minimum 6 characters"
                                required
                                minlength="6">
                        </div>
                        @if($errors->has('password'))
                            <div class="error-message">{{ $errors->first('password') }}</div>
                        @endif
                    </div>

                    <div class="form-group @if($errors->has('password_confirmation')) has-error @endif">
                        <label for="password_confirmation">Confirm Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                            </svg>
                            <input 
                                type="password" 
                                class="form-control has-icon" 
                                id="password_confirmation" 
                                name="password_confirmation" 
                                placeholder="Re-enter password"
                                required
                                minlength="6">
                        </div>
                        @if($errors->has('password_confirmation'))
                            <div class="error-message">{{ $errors->first('password_confirmation') }}</div>
                        @endif
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Company Information Section -->
                <div class="section-title">Company Information</div>

                <div class="form-group @if($errors->has('company_name')) has-error @endif">
                    <label for="company_name">Company Name <span class="required">*</span></label>
                    <input 
                        type="text" 
                        class="form-control" 
                        id="company_name" 
                        name="company_name" 
                        value="{{ old('company_name') }}" 
                        placeholder="Enter your company name"
                        required>
                    @if($errors->has('company_name'))
                        <div class="error-message">{{ $errors->first('company_name') }}</div>
                    @endif
                </div>

                <div class="form-row">
                    <div class="form-group @if($errors->has('company_phone')) has-error @endif">
                        <label for="company_phone">Company Phone</label>
                        <input 
                            type="tel" 
                            class="form-control" 
                            id="company_phone" 
                            name="company_phone" 
                            value="{{ old('company_phone') }}" 
                            placeholder="+256 700 000000">
                        @if($errors->has('company_phone'))
                            <div class="error-message">{{ $errors->first('company_phone') }}</div>
                        @endif
                    </div>

                    <div class="form-group @if($errors->has('currency')) has-error @endif">
                        <label for="currency">Currency <span class="required">*</span></label>
                        <select 
                            class="form-control" 
                            id="currency" 
                            name="currency" 
                            required>
                            <option value="">Select Currency</option>
                            <option value="UGX" {{ old('currency') == 'UGX' ? 'selected' : '' }}>UGX - Ugandan Shilling</option>
                            <option value="USD" {{ old('currency') == 'USD' ? 'selected' : 'selected' }}>USD - US Dollar</option>
                            <option value="KES" {{ old('currency') == 'KES' ? 'selected' : '' }}>KES - Kenyan Shilling</option>
                            <option value="TZS" {{ old('currency') == 'TZS' ? 'selected' : '' }}>TZS - Tanzanian Shilling</option>
                            <option value="RWF" {{ old('currency') == 'RWF' ? 'selected' : '' }}>RWF - Rwandan Franc</option>
                            <option value="EUR" {{ old('currency') == 'EUR' ? 'selected' : '' }}>EUR - Euro</option>
                            <option value="GBP" {{ old('currency') == 'GBP' ? 'selected' : '' }}>GBP - British Pound</option>
                        </select>
                        @if($errors->has('currency'))
                            <div class="error-message">{{ $errors->first('currency') }}</div>
                        @endif
                    </div>
                </div>

                <div class="form-group @if($errors->has('company_address')) has-error @endif">
                    <label for="company_address">Company Address</label>
                    <textarea 
                        class="form-control" 
                        id="company_address" 
                        name="company_address" 
                        placeholder="Enter company address (optional)"
                        rows="3">{{ old('company_address') }}</textarea>
                    @if($errors->has('company_address'))
                        <div class="error-message">{{ $errors->first('company_address') }}</div>
                    @endif
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-register" id="registerBtn">
                    <span>Create Account</span>
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="register-footer">
            <p>Already have an account? <a href="{{ admin_url('auth/login') }}">Sign in here</a></p>
            <p>&copy; {{ date('Y') }} {{ config('admin.name') }}. All rights reserved.</p>
        </div>
    </div>

    <script>
        (function() {
            const form = document.getElementById('registerForm');
            const btn = document.getElementById('registerBtn');
            const password = document.getElementById('password');
            const passwordConfirmation = document.getElementById('password_confirmation');

            // Form submission
            form.addEventListener('submit', function(e) {
                // Check password match
                if (password.value !== passwordConfirmation.value) {
                    e.preventDefault();
                    alert('Passwords do not match. Please check and try again.');
                    passwordConfirmation.focus();
                    return false;
                }

                btn.classList.add('loading');
                btn.disabled = true;
            });

            // Auto-focus on first error field
            @if($errors->any())
                const firstError = document.querySelector('.has-error');
                if (firstError) {
                    const input = firstError.querySelector('input, select, textarea');
                    if (input) {
                        input.focus();
                    }
                }
            @endif

            // Real-time password match validation
            passwordConfirmation.addEventListener('input', function() {
                if (password.value && passwordConfirmation.value) {
                    if (password.value !== passwordConfirmation.value) {
                        passwordConfirmation.setCustomValidity('Passwords do not match');
                    } else {
                        passwordConfirmation.setCustomValidity('');
                    }
                }
            });
        })();
    </script>
</body>
</html>
