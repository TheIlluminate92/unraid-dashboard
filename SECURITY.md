# Security policy

## Supported version

Unraid Dashboard is a rolling test project. Security fixes apply to the current version on `main`; historical artifacts under `original/` and `releases/history/` are retained for reference and are not supported.

## Reporting a vulnerability

Do not include credentials, server configuration, private addresses, tokens, or other sensitive data in a public issue.

Use GitHub's private vulnerability reporting for this repository when it is available. Otherwise, contact the repository owner privately through GitHub and share only the minimum detail needed to arrange a secure report.

## Sensitive data

Never commit:

- Unraid credentials, API keys, tokens, or cookies
- SSH keys or private certificates
- Real `waz.dashboard.cfg` files from a server
- Network inventories or other private infrastructure details
- Diagnostic output that exposes secrets or personally identifying data

If sensitive data is committed, treat it as exposed even after deletion because it remains in Git history. Rotate the affected secret and then remove it from the repository history.
