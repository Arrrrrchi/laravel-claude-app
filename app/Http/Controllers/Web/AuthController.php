<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\Auth\PasswordResetNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Show the login form
     */
    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    /**
     * Show the registration form
     */
    public function showRegistrationForm(): View
    {
        return view('auth.register');
    }

    /**
     * Handle user login
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $key = Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            throw ValidationException::withMessages([
                'email' => "ログイン試行回数が多すぎます。{$seconds}秒後に再度お試しください。",
            ]);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (!Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($key, 300); // 5 minutes lockout

            // デバッグ用ログ
            \Log::info('Login attempt failed', [
                'email' => $request->input('email'),
                'ip' => $request->ip(),
                'user_exists' => \App\Models\User::where('email', $request->input('email'))->exists()
            ]);

            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ]);
        }

        RateLimiter::clear($key);

        // Update last active timestamp
        /** @var User $user */
        $user = Auth::user();
        $user->update(['last_active_at' => now()]);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->with('success', 'おかえりなさい、' . $user->name . 'さん！');
    }

    /**
     * Handle user registration
     */
    public function register(RegisterRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('success', 'アカウントが正常に作成されました！ブログへようこそ、' . $user->name . 'さん！');
    }

    /**
     * Handle user logout
     */
    public function logout(Request $request): RedirectResponse
    {
        $userName = Auth::user()->name ?? 'User';

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('success', $userName . 'さん、お疲れさまでした！正常にログアウトしました。');
    }

    /**
     * Show password reset request form
     */
    public function showLinkRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle password reset request
     */
    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ], [
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => '有効なメールアドレスを入力してください。',
            'email.exists' => '指定されたメールアドレスは登録されていません。',
        ]);

        // Generate password reset token
        $token = Password::createToken(
            User::where('email', $request->email)->first()
        );

        // Send password reset notification
        $user = User::where('email', $request->email)->first();
        $user->notify(new PasswordResetNotification($token, $request->email));

        return back()
            ->with('status', 'パスワードリセットリンクをメールで送信しました！メールをご確認ください。');
    }

    /**
     * Show password reset form
     */
    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->email,
        ]);
    }

    /**
     * Handle password reset
     */
    public function resetPassword(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('success', 'パスワードが正常にリセットされました。新しいパスワードでログインしてください。');
        }

        throw ValidationException::withMessages([
            'email' => __($status),
        ]);
    }
}