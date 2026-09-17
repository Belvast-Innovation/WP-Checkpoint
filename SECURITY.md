# Security Policy

WP Checkpoint handles complete copies of WordPress sites, including databases with personal data. We take security reports seriously and appreciate responsible disclosure.

## Reporting a vulnerability

**Please do not report security issues in public GitHub issues, pull requests, discussions, or the WordPress.org support forum.**

Use one of these private channels:

1. **GitHub private vulnerability reporting** (preferred): open the repository's **Security** tab and click **Report a vulnerability**.
2. **Email**: security@wpcheckpoint.com

Please include:

- Affected version(s) and configuration (WordPress, PHP, web server)
- A description of the issue and its impact
- Steps to reproduce or a proof of concept
- Whether the issue is already known to anyone else

## What to expect

| Step | Target time |
| --- | --- |
| Acknowledgement of your report | within 3 business days |
| Initial assessment and severity rating | within 10 business days |
| Fix released for critical / high severity issues | as fast as possible, usually within 30 days |
| Public disclosure | coordinated with you, at the latest 90 days after the report |

We will keep you informed about progress and credit you in the release notes and advisory unless you prefer to remain anonymous. We currently do not run a paid bug bounty program.

## Supported versions

Only the latest released version on WordPress.org receives security fixes. Please update before reporting.

## Scope

In scope:

- The WP Checkpoint plugin code in this repository
- The standalone restore script generated from this repository
- The backup archive format as implemented by this plugin

Out of scope:

- Vulnerabilities in WordPress core, other plugins or themes (report them to their maintainers)
- Issues requiring an already compromised administrator account, unless they allow privilege escalation beyond it
- Missing hardening on servers you control (e.g. directory listing enabled by the host)

## Safe harbor

We will not pursue legal action against researchers who act in good faith, avoid privacy violations and data destruction, only test against sites they own or have permission to test, and give us reasonable time to fix the issue before disclosure.
