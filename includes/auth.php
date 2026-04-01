<?php
/**
 * Admin authentication
 *
 * Usage: require_once __DIR__ . '/auth.php'; require_admin_auth();
 *
 * Password lives in ~/local/.env as ADMIN_PASSWORD=yourpassword
 * Cookie is HttpOnly, SameSite=Strict, 10-year expiry — never logs out.
 */

function is_admin(): bool {
	$password = $_ENV['ADMIN_PASS'] ?? getenv('ADMIN_PASS') ?? null;
	if (!$password) return false;
	$expected = hash_hmac('sha256', 'admin', $password);
	return isset($_COOKIE['bcc_admin']) && hash_equals($expected, $_COOKIE['bcc_admin']);
}

function require_admin_auth(): void {
	$password = $_ENV['ADMIN_PASS'] ?? getenv('ADMIN_PASS') ?? null;
	if (!$password) {
		http_response_code(500);
		exit('ADMIN_PASS not set in environment.');
	}

	$cookie_name   = 'bcc_admin';
	$expected_token = hash_hmac('sha256', 'admin', $password);

	// Already authenticated
	if (isset($_COOKIE[$cookie_name]) && hash_equals($expected_token, $_COOKIE[$cookie_name])) {
		return;
	}

	// Handle login form submission
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
		if (hash_equals($password, $_POST['password'])) {
			setcookie(
				$cookie_name,
				$expected_token,
				[
					'expires'  => time() + (10 * 365 * 24 * 60 * 60), // 10 years
					'path'     => '/',
					'httponly' => true,
					'samesite' => 'Strict',
					'secure'   => isset($_SERVER['HTTPS']),
				]
			);
			$next = $_GET['next'] ?? '/admin';
			header('Location: ' . $next);
			exit;
		}
		$login_error = 'Incorrect password.';
	}

	// Show login form
	$next        = $_GET['next'] ?? ($_SERVER['REQUEST_URI'] ?? '/admin');
	$login_error = $login_error ?? null;

	http_response_code(isset($login_error) ? 401 : 200);
	?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Backcountry Club</title>
<style>
:root {
	--bg:      #f5f3ee;
	--border:  #ddd9cf;
	--text:    #1a1916;
	--muted:   #6b6860;
	--red:     #BF2C34;
	--sys:     -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
	min-height: 100%; background: var(--bg);
	font-family: var(--sys); font-size: 15px;
	display: flex; align-items: center; justify-content: center;
	-webkit-font-smoothing: antialiased;
}
.login-box {
	width: 100%; max-width: 360px;
	padding: 48px 40px;
}
.login-mark {
	margin-bottom: 32px;
}
.login-mark img { height: 36px; width: auto; display: block; }
h1 {
	font-size: 1.3rem; font-weight: 600;
	letter-spacing: -0.02em; margin-bottom: 24px;
	color: var(--text);
}
label {
	display: block; font-size: 12px; font-weight: 600;
	letter-spacing: 0.04em; text-transform: uppercase;
	color: var(--muted); margin-bottom: 6px;
}
input[type="password"] {
	display: block; width: 100%;
	padding: 10px 12px; font-size: 15px;
	font-family: var(--sys);
	background: #fff; color: var(--text);
	border: 1px solid var(--border); border-radius: 3px;
	outline: none; margin-bottom: 16px;
}
input[type="password"]:focus { border-color: var(--muted); }
button {
	display: block; width: 100%;
	padding: 10px 12px; font-size: 14px; font-weight: 600;
	font-family: var(--sys);
	background: var(--text); color: #fff;
	border: none; border-radius: 3px;
	cursor: pointer; transition: opacity 0.15s;
}
button:hover { opacity: 0.85; }
.error {
	font-size: 13px; color: var(--red);
	margin-bottom: 16px;
}
</style>
</head>
<body>
<div class="login-box">
	<div class="login-mark">
		<img src="/images/bcc-red.svg" alt="Backcountry Club">
	</div>
	<h1>Sign in</h1>
	<?php if ($login_error): ?>
	<p class="error"><?= htmlspecialchars($login_error) ?></p>
	<?php endif; ?>
	<form method="POST" action="/login?next=<?= htmlspecialchars(urlencode($next)) ?>">
		<label for="password">Password</label>
		<input type="password" id="password" name="password" autofocus autocomplete="current-password">
		<button type="submit">Sign in →</button>
	</form>
</div>
</body>
</html>
	<?php
	exit;
}