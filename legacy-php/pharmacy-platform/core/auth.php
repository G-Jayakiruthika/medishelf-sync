<?php
/* Staff sign-in. Owners and managers can turn on two-factor sign-in codes (any authenticator app).
   Each person has a role; riders see only their own deliveries, cashiers can't change stock or prices. */
declare(strict_types=1);

function me(): ?array
{
    static $cache = null;
    if ($cache !== null) return $cache ?: null;
    if (empty($_SESSION['staff_id'])) { $cache = false; return null; }
    $s = q("SELECT s.*, o.status org_status, o.name org_name FROM staff s JOIN orgs o ON o.id = s.org_id WHERE s.id = ? AND s.status = 'active'", [$_SESSION['staff_id']])->fetch();
    $cache = $s ?: false;
    return $s ?: null;
}
function need_me(): array { $s = me(); if (!$s) fail('not_signed_in', 'Please sign in.', 401); return $s; }
function can(array $s, string $what): bool { $c = CAN[$s['role']] ?? []; return in_array('*', $c, true) || in_array($what, $c, true); }
function need(string $what): array
{
    $s = need_me();
    if (!can($s, $what)) fail('forbidden', 'Your role (' . $s['role'] . ') cannot do this. Ask the owner.', 403);
    if ($s['org_status'] !== 'approved' && !in_array($what, ['settings', 'staff'], true)) fail('not_approved', $s['org_status'] === 'suspended' ? 'This pharmacy is suspended. Contact AushadhBox support.' : 'Your pharmacy is waiting for approval. We check the drug licence, usually within one working day.', 403);
    return $s;
}
/* The branch this person is working at now. Staff tied to one branch always get that branch. */
function my_branch(array $s): array
{
    $id = $s['branch_id'] ? (int) $s['branch_id'] : (int) ($_SESSION['branch_id'] ?? 0);
    $b = $id ? q('SELECT b.*, o.status org_status, o.name org_name FROM branches b JOIN orgs o ON o.id = b.org_id WHERE b.id = ? AND b.org_id = ?', [$id, $s['org_id']])->fetch() : null;
    if (!$b) $b = q('SELECT b.*, o.status org_status, o.name org_name FROM branches b JOIN orgs o ON o.id = b.org_id WHERE b.org_id = ? ORDER BY b.id LIMIT 1', [$s['org_id']])->fetch();
    if (!$b) fail('no_branch', 'Add your first shop in Settings.', 409);
    $_SESSION['branch_id'] = (int) $b['id'];
    return $b;
}

function staff_public(array $s): array
{
    return ['id' => (int) $s['id'], 'name' => $s['name'], 'role' => $s['role'], 'email' => $s['email'], 'phone' => $s['phone'], 'branchId' => $s['branch_id'] ? (int) $s['branch_id'] : null,
            'twoFactor' => (bool) $s['totp_on'], 'status' => $s['status'], 'lastLogin' => $s['last_login_at']];
}

function login_start(string $id, string $password): array
{
    $id = trim($id);
    $phone = phone_norm($id);
    limit('login:' . strtolower($id), 8, 15); limit('loginip:' . client_ip(), 30, 15);
    $s = q("SELECT * FROM staff WHERE (email = ? OR (? <> '' AND phone = ?)) AND status = 'active'", [strtolower($id), $phone, $phone])->fetch();
    $ok = password_verify($password, $s['password_hash'] ?? PW_DUMMY);   // same work whether or not the account exists
    if (!$s || !$ok) { audit('staff', $s ? (int) $s['id'] : null, $s ? (int) $s['org_id'] : null, null, 'login_fail', mb_substr($id, 0, 80)); fail('bad_login', 'E-mail / phone or password is not correct.', 401); }
    if (pw_needs_rehash($s['password_hash'])) q('UPDATE staff SET password_hash = ? WHERE id = ?', [pw_hash($password), $s['id']]);
    if ($s['totp_on']) { $_SESSION['pending_2fa'] = (int) $s['id']; $_SESSION['pending_at'] = time(); return ['twoFactor' => true]; }
    return login_finish($s);
}
function login_2fa(string $code): array
{
    $id = (int) ($_SESSION['pending_2fa'] ?? 0);
    if (!$id || time() - (int) ($_SESSION['pending_at'] ?? 0) > 300) fail('expired', 'Sign in again.', 401);
    limit('2fa:' . $id, 6, 15);
    $s = q('SELECT * FROM staff WHERE id = ?', [$id])->fetch();
    if (!$s || !totp_ok((string) dec($s['totp_secret']), $code)) fail('bad_code', 'That code is not correct. Use the 6-digit code from your authenticator app.', 401);
    unset($_SESSION['pending_2fa'], $_SESSION['pending_at']);
    return login_finish($s);
}
function login_finish(array $s): array
{
    session_regenerate_id(true);
    $_SESSION['staff_id'] = (int) $s['id']; $_SESSION['csrf'] = bin2hex(random_bytes(32)); unset($_SESSION['branch_id']);
    q('UPDATE staff SET last_login_at = NOW() WHERE id = ?', [$s['id']]);
    audit('staff', (int) $s['id'], (int) $s['org_id'], $s['branch_id'] ? (int) $s['branch_id'] : null, 'login', $s['role']);
    return ['ok' => true, 'csrf' => $_SESSION['csrf']];
}

function password_check(string $p): void
{
    if (mb_strlen($p) < 8) fail('weak_password', 'Use at least 8 characters for the password.');
    if (preg_match('/^(12345678|password|qwerty|11111111|00000000|abcd1234)/i', $p)) fail('weak_password', 'That password is too easy to guess.');
}
