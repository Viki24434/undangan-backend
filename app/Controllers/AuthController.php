<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\AdminModel;
use App\Services\Identifiers;
use App\Services\Passwords;
use App\Validation\Input;

final class AuthController
{
    public function __construct(
        private readonly AdminModel $model,
        private readonly array $config,
    ) {}

    private function token(): string
    {
        return (string) ($_COOKIE['inviteku_session'] ?? '');
    }

    private function session(): ?array
    {
        $token = $this->token();
        return preg_match('/^[a-f0-9]{64}$/D', $token)
            ? $this->model->session(hash('sha256', $token))
            : null;
    }

    public function authorize(Request $request): void
    {
        if ($request->header('Authorization') !== '') {
            $configured = $this->config['admin_token'];
            if (
                strlen($configured) < 32 ||
                str_starts_with($configured, 'replace-with-') ||
                !hash_equals(
                    hash('sha256', $configured),
                    hash('sha256', $request->bearer()),
                )
            ) {
                throw new HttpException(401, 'Token admin tidak valid.');
            }
            return;
        }
        $session = $this->session();
        if (!$session) {
            throw new HttpException(401, 'Silakan masuk ke dashboard admin.');
        }
        if (
            !in_array($request->method, ['GET', 'HEAD'], true) &&
            !hash_equals($session['csrf_token'], $request->header('X-CSRF-Token'))
        ) {
            throw new HttpException(
                403,
                'Session keamanan tidak valid. Muat ulang halaman.',
            );
        }
    }

    public function info(): array
    {
        $session = $this->session();
        return Response::data([
            'user' => $session
                ? ['name' => $session['name'], 'email' => $session['email']]
                : null,
            'csrfToken' => $session['csrf_token'] ?? null,
            'setupRequired' => !$this->model->find(),
        ]);
    }

    private function cookie(string $token, int $maxAge = 28800): void
    {
        if ($this->config['cookie_path'] !== '/api') {
            setcookie('inviteku_session', '', [
                'expires' => time() - 3600,
                'path' => '/api',
                'secure' => $this->config['secure_cookie'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        setcookie('inviteku_session', $token, [
            'expires' => time() + $maxAge,
            'path' => $this->config['cookie_path'],
            'secure' => $this->config['secure_cookie'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function establish(array $admin): array
    {
        $this->model->cleanup();
        if ($this->token() !== '') {
            $this->model->removeSession(hash('sha256', $this->token()));
        }
        $token = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        $this->model->createSession(
            hash('sha256', $token),
            $csrfToken,
            Identifiers::milliseconds() + 28800000,
        );
        $this->cookie($token);
        return Response::data([
            'user' => ['name' => $admin['name'], 'email' => $admin['email']],
            'csrfToken' => $csrfToken,
        ]);
    }

    private function credentials(object $body): array
    {
        $email = strtolower(Input::text($body->email ?? null, 'Email', 160));
        $password = $body->password ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Alamat email tidak valid.');
        }
        if (
            !is_string($password) ||
            mb_strlen($password) < 12 ||
            mb_strlen($password) > 128 ||
            str_contains($password, "\0")
        ) {
            throw new HttpException(422, 'Password harus 12–128 karakter.');
        }
        return [$email, $password];
    }

    public function setup(object $body): array
    {
        if ($this->model->find()) {
            throw new HttpException(409, 'Admin sudah tersedia. Silakan login.');
        }
        $name = Input::text($body->name ?? null, 'Nama admin', 80);
        [$email, $password] = $this->credentials($body);
        $this->model->create($name, $email, Passwords::hash($password));
        return $this->establish(compact('name', 'email'));
    }

    public function login(object $body): array
    {
        [$email, $password] = $this->credentials($body);
        $admin = $this->model->find();
        $valid =
            $admin &&
            hash_equals($admin['email'], $email) &&
            Passwords::verify($password, $admin['password_hash']);
        if (!$valid) {
            if (!$admin || $email !== $admin['email']) {
                password_verify(
                    hash('sha256', $password),
                    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.',
                );
            }
            throw new HttpException(401, 'Email atau password tidak sesuai.');
        }
        if (Passwords::needsUpgrade($admin['password_hash'])) {
            $this->model->updatePassword(Passwords::hash($password));
        }
        return $this->establish($admin);
    }

    public function logout(Request $request): array
    {
        $this->authorize($request);
        $this->model->removeSession(hash('sha256', $this->token()));
        $this->cookie('', -3600);
        return Response::data(['message' => 'Berhasil keluar.']);
    }
}
