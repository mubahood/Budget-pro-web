<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>{{ config('admin.title') }} | Login</title>
    
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

        .login-container {
            width: 100%;
            max-width: 400px;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .login-header {
            background: #dc2626;
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .login-logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 16px;
            background: rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-logo svg {
            width: 32px;
            height: 32px;
            fill: white;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .login-header p {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 400;
        }

        .login-body {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            opacity: 0.4;
        }

        .form-control {
            width: 100%;
            padding: 11px 12px 11px 40px;
            font-size: 14px;
            border: 1px solid #d1d5db;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
            background: #ffffff;
        }

        .form-control:focus {
            border-color: #dc2626;
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        .error-message {
            color: #dc2626;
            font-size: 13px;
            margin-top: 6px;
        }

        .has-error .form-control {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 18px 0 24px;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #dc2626;
        }

        .checkbox-wrapper label {
            font-size: 14px;
            color: #666;
            cursor: pointer;
            margin: 0;
            font-weight: 400;
        }

        .btn-login {
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
        }

        .btn-login:hover:not(:disabled) {
            background: #b91c1c;
        }

        .btn-login:active:not(:disabled) {
            background: #991b1b;
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-login.loading {
            position: relative;
            color: transparent;
        }

        .btn-login.loading::after {
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

        .login-footer {
            padding: 20px 30px;
            background: #fafafa;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }

        .login-footer p {
            font-size: 13px;
            color: #666;
        }

        /* Responsive */
        @media (max-width: 480px) {
            body {
                padding: 0;
                background: #ffffff;
            }

            .login-container {
                box-shadow: none;
                border: none;
                min-height: 100vh;
                max-width: 100%;
                display: flex;
                flex-direction: column;
            }

            .login-header {
                padding: 30px 24px;
            }

            .login-body {
                padding: 30px 24px;
                flex: 1;
            }

            .login-footer {
                padding: 16px 24px;
            }
        }
    </style>
</head>

<body>
    <div class="login-container" id="loginContainer">
        <!-- Header -->
        <div class="login-header">
            <div class="login-logo">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/>
                </svg>
            </div>
            <h1>{{ config('admin.name') }}</h1>
            <p>Sign in to continue</p>
        </div>

        <!-- Body -->
        <div class="login-body">
            <form action="{{ admin_url('auth/login') }}" method="post" id="loginForm">
                @csrf
                
                <!-- Username -->
                <div class="form-group @if($errors->has('username')) has-error @endif">
                    <label for="username">Email or Username</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                        <input 
                            type="text" 
                            class="form-control" 
                            id="username" 
                            name="username" 
                            value="{{ old('username') }}" 
                            placeholder="Enter your email or username"
                            required
                            autofocus>
                    </div>
                    @if($errors->has('username'))
                        <div class="error-message">{{ $errors->first('username') }}</div>
                    @endif
                </div>

                <!-- Password -->
                <div class="form-group @if($errors->has('password')) has-error @endif">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                        </svg>
                        <input 
                            type="password" 
                            class="form-control" 
                            id="password" 
                            name="password" 
                            placeholder="Enter your password"
                            required>
                    </div>
                    @if($errors->has('password'))
                        <div class="error-message">{{ $errors->first('password') }}</div>
                    @endif
                </div>

                <!-- Remember Me -->
                @if(config('admin.auth.remember'))
                <div class="checkbox-wrapper">
                    <input type="checkbox" id="remember" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                    <label for="remember">Remember me for 30 days</label>
                </div>
                @endif

                <!-- Submit Button -->
                <button type="submit" class="btn-login" id="loginBtn">
                    <span>Login to Dashboard</span>
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="login-footer">
            <p>Don't have an account? <a href="{{ url('auth/register') }}" style="color: #dc2626; text-decoration: none; font-weight: 500;">Create one here</a></p>
            <p>&copy; {{ date('Y') }} {{ config('admin.name') }}. All rights reserved.</p>
        </div>
    </div>

    <script>
        (function() {
            const form = document.getElementById('loginForm');
            const btn = document.getElementById('loginBtn');

            // Form submission
            form.addEventListener('submit', function() {
                btn.classList.add('loading');
                btn.disabled = true;
            });

            // Auto-focus on error field
            @if($errors->has('username'))
                document.getElementById('username').focus();
            @elseif($errors->has('password'))
                document.getElementById('password').focus();
            @endif
        })();
    </script>
</body>
</html>
