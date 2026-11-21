# Admin dashboard fixes

Run once on the database (adds the seat-limit column):
`database/migrations/002_course_capacity.sql`

| Test | Fix |
|---|---|
| ADMIN-009/011 | Users page: role/status filters + sortable columns (asc/desc, `aria-sort`) |
| ADMIN-017/018 | `admin/user-profile`: edit name, email, role (own role locked; instructors owning courses cannot be demoted; audit-logged) |
| ADMIN-024 | Bulk actions: working multi-select (select-all, count, confirm) |
| ADMIN-040/042/043 | New `admin/enrollments`: unenroll (one/bulk), bulk enroll, capacity. Limit enforced for admin, student catalog and instructor cohorts via `includes/capacity.php` |
| ADMIN-064 | Mobile CSS: table headers no longer hidden out of sync, 44px tap targets, 16px inputs, duplicate sign-out hidden |
| ADMIN-084/085/086 | Names/titles reject `<` `>`; inline `onsubmit` confirm (JS injection) replaced by `data-confirm`; admin CSP `script-src 'self'` |
| ADMIN-087 | LIKE wildcards (`%`, `_`) escaped in user search and search API; queries were already parameterised |
| ADMIN-089 | CSRF token + same-origin check on every admin POST (users, status, delete, import, profile, enrollments) and logout |
| ADMIN-091 | CSV cells starting with `= + - @` (also after spaces) are neutralised in all exports |
| ADMIN-095/096 | X-Frame-Options, CSP (`frame-ancestors`), nosniff, Referrer/Permissions-Policy, HSTS on HTTPS, `X-Powered-By` removed |
| ADMIN-102 | `url()`/`redirect()` can never produce an off-site target |
| ADMIN-088 | No code change needed: expected result ("stored as plain text") matches actual; re-mark as Pass |
