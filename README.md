# Redirect Manager (WordPress plugin)

Manage 301/302/307 redirects from WordPress admin — no `.htaccess` editing,
no server access needed. Ideal after a redesign, a permalink change, or
merging/deleting pages.

## Features

- **Exact rules** — `/old-page` → `/new-page`, checked first.
- **Regex rules** — e.g. source `old-category/(.*)` → target `/new-category/$1`.
- **Hit counts** per rule, so dead rules are easy to spot and clean up.
- **Pause / resume** rules without deleting them.
- **CSV export** of all rules for backup or migration.
- Broken regex patterns are skipped safely instead of breaking the site.
- Invalid redirect codes fall back to 301.

## Install

1. Copy this folder to `wp-content/plugins/wp-redirect-manager/`.
2. Activate **Redirect Manager**.
3. Manage rules under Tools → Redirects.

## Tips

- Use 301 for permanent moves (SEO value transfers); 302/307 for temporary ones.
- Regex rules run after exact rules, so an exact rule always wins a conflict.
- Keep redirect chains short: point old URLs at the final destination, not
  at another redirect.

## Uninstall

Deactivation leaves rules in place; `uninstall.php` drops the rules table.

## License

MIT — see `LICENSE`.
