# Repository Guidelines

## Project Structure & Module Organization
This is a Moodle local plugin named `local_referral`, targeting Moodle 4.x/5.x. Use the standard Moodle plugin layout:
- `classes/` for namespaced PHP classes (e.g., event observers, services)
- `db/` for install and event definitions (`install.xml`, `events.php`)
- `lang/en/` for language strings
- `templates/` for Mustache templates (if needed)
- Root files: `version.php`, `settings.php`, `lib.php`, `manage.php`, `report.php`, `dashboard.php`, `index.php` (optional redirect to `manage.php`)

Keep admin-only code in the root pages and move reusable logic into `classes/`.

## Build, Test, and Development Commands
No frontend build is used. Primary workflows are standard Moodle commands:
- `php admin/cli/install.php` to install a local Moodle instance (Moodle core)
- `php admin/cli/upgrade.php` to apply plugin upgrades
- `vendor/bin/phpunit --testsuite local_referral_testsuite` if PHPUnit tests are added

Run commands from the Moodle root, not from the plugin directory.

## Architecture Overview
Data flow and responsibilities are intentionally simple:
- Visitor opens a site URL with `?ref=CODE`
- Plugin captures `CODE` and stores it in a cookie named `referral_code`
- On `\core\event\user_created`:
  - Read the cookie
  - Look up the marketer by code
  - Save the relationship in `local_ref_users`
- On `\core\event\user_enrolment_created`:
  - Resolve referred user and marketer
  - Read enrolment amount
  - Save commission in `local_ref_commissions` (unique per user+course)
- Admin pages:
  - CRUD for marketers
  - Report page that counts users per marketer
  - Commission approval and payout updates
- Marketer page:
  - Dashboard page for own commissions (`dashboard.php`)

## Implementation Notes
- Event observer class: `classes/observer.php`
- Database tables in `db/install.xml`: `local_ref_marketers`, `local_ref_users`, `local_ref_commissions`
- Admin pages: `manage.php` (marketer CRUD + commission workflow), `report.php` (reports)
- Marketer page: `dashboard.php` (own referral and commission metrics)
- `manage.php` and `report.php` must require login and check admin capabilities (`moodle/site:config`)

## Coding Style & Naming Conventions
Follow Moodle coding style: `https://moodledev.io/docs/standards/coding`.
- PHP: 4-space indent, braces on next line, lowercase `true/false/null`
- Classes: namespaced under `local_referral` and placed in `classes/`
- Functions: `snake_case` for global functions in `lib.php`
- Files: match Moodle conventions (e.g., `db/events.php`, `lang/en/local_referral.php`)

Prefer Moodle APIs (events, config, DB) over direct access.

## Testing Guidelines
Testing is optional but should use Moodle PHPUnit if added.
- Location: `tests/` with classes under `local_referral` namespace
- Naming: `*_test.php` (Moodle standard)
- Focus on referral capture, event observer logic, and admin CRUD behavior

Keep tests isolated and use Moodle data generators where possible.

## Commit & Pull Request Guidelines
Use clear, imperative commit messages (e.g., `Add referral observer`, `Fix report query`). Pull requests should include:
- A concise summary of changes
- Linked issues (if any)
- Notes on upgrade impact (e.g., schema changes in `db/install.xml`)

## Security & Configuration Tips
This plugin is admin-only and should remain simple and upgrade-safe:
- Use capability checks for admin pages
- Store referral codes securely via Moodle config or custom tables
- Avoid custom session handling; use Moodle cookies and APIs

## Agent-Specific Instructions
Keep the plugin minimal and upgrade-safe. If you add new pages, DB tables, or tooling, update this file with the new paths and commands.
