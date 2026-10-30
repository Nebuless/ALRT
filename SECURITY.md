# Security

Do not post credentials, machine inventories, tailnet addresses, session files, process payloads or private diagnostics in public issues.

Tailscale is the intended access boundary. Backends must remain private and validate the configured identity; a loopback address alone is not authentication. No production deployments or credentials are configured by this repository.

To report a vulnerability, use GitHub private vulnerability reporting for this repository when available. If unavailable, avoid posting exploitation details or sensitive evidence publicly until a private reporting channel is established.

Scope limits: cross-UID Citizens controls are intentionally disabled pending independently verified command authorization; provider outages cannot revoke already copied upstream credentials; local redaction cannot erase data previously copied by an authorized user. This source release does not claim absence of all vulnerabilities or physical-device/assistive-technology certification.
