# Security policy

## Reporting a vulnerability

Please report security issues privately through a
[GitHub security advisory](https://github.com/ibuilder/AutodeskFormaWP/security/advisories/new)
rather than a public issue.

Include the affected component (WordPress plugin, backend service or Forma
extension), the version, and enough detail to reproduce. Please allow time for a
fix before public disclosure.

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |

## Scope

The security model, trust boundaries and the controls protecting each of them
are documented in [docs/security.md](docs/security.md).

Findings that are especially in scope:

- Any path that lets an unsigned or replayed request reach a write operation.
- Any way to make the WordPress plugin issue an outbound request to a host that
  is not on the configured media allow list.
- Any way to read a connection secret or an Autodesk token from a response, a
  log line or a rendered page.
- Any stored or reflected cross-site scripting reachable through a publish
  payload.
- Privilege escalation through the plugin's custom capabilities.

## Out of scope

- Findings that require an administrator account to already be compromised.
- Denial of service through sheer request volume against your own deployment.
- Missing hardening headers on the example backend, which is intended to run
  behind a reverse proxy that supplies them.
