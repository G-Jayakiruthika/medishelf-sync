# Security policy

## Reporting a vulnerability

Please report security problems privately, not in a public issue: open the repository's **Security** tab and choose
**Report a vulnerability** (GitHub private vulnerability reporting). Include the steps to reproduce, the address or file
affected, and what an attacker could do with it. You will get a reply within 7 days, and a fix or a plan within 30 days.

Please do not test against a live site you do not own, and do not access or keep other people's data.

## Supported versions

Only the latest code on the `main` branch gets security fixes.

## How the project is protected

The full review is in [docs/SECURITY-REPORT.md](docs/SECURITY-REPORT.md): every protection, where it lives, and the test that proves
it. In short: Argon2id passwords, two-factor sign-in for staff and admins, encryption of health details and phone numbers,
CSRF tokens plus Origin checks, strict security headers and CSP, no cross-origin API access, parameterised queries,
per-family and per-shop isolation, rate limits, bot traps, security event logging, encrypted backups with tested restore,
and a privacy centre built to the ISO/IEC 29100 / 27701 structure for India's DPDP Act. `tests/security_test.sh` (167 checks)
runs on every push.
