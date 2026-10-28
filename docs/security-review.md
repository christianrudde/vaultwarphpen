# Security review, October 2026

A review of Vaultwarphpen's PHP code before people trust it with real passwords. It asked one question
above all: wherever Vaultwarden checks something, does the port check the same thing, the same way?

This was an internal review, carried out with an AI coding assistant (Claude Code) and read against
Vaultwarden's source. It is not an independent audit. It found and fixed real vulnerabilities, which
says as much about what may still be there as about what is gone.

## Scope

- Vaultwarphpen at commit `f8078eb` (main, 28 October 2026), compared with Vaultwarden commit
  [`0f44744`](https://github.com/dani-garcia/vaultwarden/commit/0f44744ef86a2a7bea615af1c024044ce5736187)
  (release 1.37.4), the pin of `parity/reference.json`.
- All of `app/`: the request guards and tokens, every route, two-step login, organizations, collections
  and groups, ciphers, attachments, Sends, emergency access, SSO, outgoing requests and icons, the admin
  panel, the notifications daemon, the web vault, configuration, error output, logging and response
  headers. Also `public/`, `.env.example` and the installation documentation, as far as they decide what
  is reachable.

## Method

- **Reading side by side.** Each area was read in PHP next to the Rust handlers, guards and models it
  ports, checking for the slips a port typically makes: a missing guard, a check in the wrong order, a
  comparison that is not constant-time, PHP type juggling, a token not bound to its purpose, an id that is
  not checked against its owner.
- **Mechanical checks.**
  - A script compared the request guards of all 263 routes (`Headers`, `OrgHeaders`, `AdminHeaders`,
    `ManagerHeaders`, `OwnerHeaders`, `AdminToken`, `PublicToken`, `SendHeaders`, ...) with the guards of
    the Rust handler each route is named after. Every route has the guard of its handler; six resolve it
    in the method body on purpose (five uploads after the multipart check, the breach report after its
    query parameter), in Rocket's order.
  - Searches for loose comparisons, `in_array` and `array_search` without strict mode, weak randomness
    (`rand`, `mt_rand`, `uniqid`, `str_shuffle`), `unserialize`, `extract`, `eval` and shell calls.
  - `composer audit` (no advisories) and PHPStan at its maximum level.
- **Exploit attempts.** Every fixed finding below was reproduced with a test; each test fails without its
  fix. Outgoing requests were tried against local servers, including one that redirects and
  one that sends its answer a byte at a time.

## Findings that were fixed

| # | Severity | Area | Problem | Vaultwarden |
| --- | --- | --- | --- | --- |
| 1 | High | Organizations | Manager guard checks another collection than the route changes | not affected |
| 2 | High | Outgoing requests | Redirects, IPv6 addresses and rebinding get past `HTTP_REQUEST_BLOCK_*` | not affected |
| 3 | Medium | SSO | `"email_verified": "false"` read as unknown, which may link an existing account | not affected |
| 4 | Medium | Logging | Failed queries log their bound values: tokens, two-factor secrets, hashes | not affected |
| 5 | Medium (Windows) | Web vault | `\` in a path leads out of the web vault folder | not affected |
| 6 | Low | Notifications daemon | `X_Real_IP` counted as `X-Real-IP` | not affected |
| 7 | Low | Web vault | A NUL byte in a path gives a 500 and an error in the log | not affected |

### 1. Manager guard checks another collection than the route changes

`ManagerHeaders` takes the collection id from the fourth path segment, or from the `collectionId` query
field when that segment is no UUID. It split the raw path, but Laravel's router matches and binds the
decoded one, where an encoded slash separates segments. For
`PUT /api/organizations/<org>/collections%2F<other>?collectionId=<own>` the route bound `<other>`, the
guard found no fourth segment and checked `<own>`. A confirmed manager who manages any one collection
could rename any other collection of the organization, replace its access list (granting themselves
manage rights to it), read its member list or delete it. Rocket never matches such a path.

Fixed: the guard splits the decoded path, as the router does. Test:
`OrganizationTest::test_a_manager_only_reaches_collections_they_manage`. Whether it was reachable depended
on the web server: nginx and PHP's built-in server pass `%2F` on; Apache refuses it by default.

### 2. Outgoing requests got past `HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS` and `HTTP_REQUEST_BLOCK_REGEX`

Vaultwarden's HTTP client checks the host of every redirect, resolves each name once, checks every
address (IPv4 and IPv6) and connects only to those addresses, all within one timeout. The port used PHP's
`http` stream wrapper and differed in several ways:

- `HttpClient::send` checked the first URL only and let the stream wrapper follow up to six redirects
  unchecked. Any user can set the host of their own Duo keys, so any user could make the server request
  internal addresses (`169.254.169.254`, `localhost`, the local network) through a redirect, and tell
  from the answer whether they responded.
- Names were checked with `gethostbynamel()`, which returns IPv4 addresses only, and then resolved again
  by the stream wrapper, which also uses IPv6. A name with an AAAA record for `::1`, `fd00::/8` or
  `::ffff:169.254.169.254` passed unchecked. Through the icon service this needed no login. The second
  lookup also allowed DNS rebinding.
- `send()` matched the block regex against the host as written, so `LOCALHOST` passed `^localhost$`.
  A regex containing `\/` failed to compile at match time, and a failed match counted as no match.
- The stream wrapper's timeout applies to each read. A server sending a byte now and then held a PHP
  worker for as long as it liked; through the icon service anyone could tie up all workers.

Fixed: `HttpClient` now speaks HTTP/1.1 over its own connections. It parses URLs as the url crate does,
resolves names once through `App\Support\DnsResolver` (IPv4 and IPv6), checks every address, connects to
those addresses only (the certificate is still checked for the name), follows redirects itself with the
checks and request changes of reqwest's redirect policy, and keeps one deadline for the whole request. The
block regex is compiled with the delimiters `ConfigValidator` checks it with, and a match that fails
blocks. Tests: `HttpClientRequestTest`.

### 3. SSO: a verification claim that is not a boolean

Vaultwarden is built without openidconnect's `accept-string-booleans` feature, so an ID token or userinfo
answer with `"email_verified": "false"` fails to parse and the login fails. The port read any
non-boolean as unknown. With `SSO_ALLOW_UNKNOWN_EMAIL_VERIFICATION=true` and `SSO_SIGNUPS_MATCH_EMAIL`
(the default), an identity that the provider reports as unverified was linked to the existing account with
that email: a login as that user.

Fixed: `email_verified` and `phone_number_verified` must be booleans or null; otherwise the token answer
(or the userinfo answer) fails to parse, as in Vaultwarden. Test: `SsoProviderTest`. Providers that send
these claims as strings now fail to log in, as they do with Vaultwarden.

### 4. Bound values in logged query errors

Laravel writes the bindings into a `QueryException`'s message, and failed queries are logged. A failed
write (a locked SQLite database, a deadlock, a unique violation) put device refresh tokens, two-factor
secrets, API keys, security stamps or password hashes into the log. Diesel's errors carry no bind
parameters. Fixed: `mask_bindings_in_exception_messages` on the Vaultwarden connection. Test:
`DatabaseUrlTest::test_query_errors_do_not_show_the_bound_values`.

### 5. Web vault paths on Windows

Rocket's `PathBuf` guard refuses backslashes and colons on Windows. The port only had the Unix checks, so
on a Windows host `GET /x%5C..%5C..%5C.env` read `.env`, and the same way `data/rsa_key.pem` (which signs
every token), `data/config.json` or the SQLite database, without a login. Fixed in
`WebVault::relativePath`. Test: `WebVaultTest::test_paths_out_of_the_web_vault`.

### 6. Underscores in header names in the notifications daemon

The daemon turned `X_Real_IP` into the same server variable as `X-Real-IP`. Behind a trusted proxy that
passes such headers on, a client could name its own address and so get past the limit of anonymous
connections per address. Web servers drop these headers before PHP; the daemon now does too. Test:
`DaemonTest::test_headers_with_underscores_do_not_count`.

### 7. A NUL byte in a web vault path

`is_file()` throws on a NUL byte; the request ended as a 500 with an error in the log instead of
Rocket's 404. Fixed in `WebController::webVaultFile`.

## Open findings

Findings that are not fixed are not described here while they are open; the maintainers have the
details. Please report anything new privately, as [SECURITY.md](../SECURITY.md) describes.

- **In Vaultwarphpen only:** two, both of the notifications daemon: a denial of service (out of scope of
  the security policy, but worth smaller limits) and hardening of the local channel between PHP and the
  daemon. Until they are fixed, run the daemon as a service that restarts it (see the
  [installation documentation](installation.md#live-notifications)) and keep a TCP
  `NOTIFICATIONS_SOCKET` on the loopback interface.
- **Shared with Vaultwarden:** eleven weaknesses of Vaultwarden's own behaviour, most of them of low
  severity. The port keeps Vaultwarden's behaviour until the maintainers decide otherwise; they belong
  reported to Vaultwarden first.

Notes without a security impact:

- `.env.example` is set up for development (`APP_ENV=local`, `APP_DEBUG=true`). No response showed more
  with debug on, because every error goes through `ErrorRenderer`, but the installation documentation's
  advice to switch both off on a server stands.
- `DnsResolver` takes IPv4 addresses from the system's resolver (so the hosts file counts) and IPv6
  addresses from DNS; an AAAA entry in the hosts file is not seen. Connections only go to addresses that
  were checked, so this cannot be used to get past the checks.
- `KdfData::equals()` compares loosely (`null == 0`), and an `attachments2` key made of digits is bound as
  an integer. Neither reaches anything but the user's own data.

## Checked and found equivalent

- **Tokens:** RS256 only, signature before claims, 30 seconds of leeway, the issuer of each purpose,
  `aud` refused, typed claims; access, refresh, invitation, emergency access, Send, file download,
  admin, organization API, verification, deletion and two-factor remember tokens, each bound to what it
  is for. Security stamps and their exceptions. API key, access code, recovery code, Send password and
  admin token compared in constant time. Rate limits at the same places, shared between PHP workers.
- **Two-step login:** TOTP windows and replay, email codes, WebAuthn (origin, rpId, challenge, counters,
  user presence and verification), Duo (state, nonce, ID token), YubiKey (key ownership, signature,
  nonce), recovery codes, remembered devices, provider selection and the organization policy.
- **Organizations:** every member, collection, group, policy, account recovery and event route, the public
  API and the cipher access rules, scoped to the organization and checked for status and role like
  Vaultwarden.
- **Personal data:** ciphers, folders, attachments, Sends, emergency access and account management; ids
  checked against their owner; file paths only from validated or server-made ids.
- **SSO:** state and its binding cookie, nonce, PKCE, redirect URIs, ID token signature, algorithm and
  key selection, issuer and audience, account linking rules.
- **Admin panel:** guard on every route, cookie attributes, the JSON content type that keeps cross-site
  requests out, the editable settings, backups, masked diagnostics.
- **Responses:** only `public/` is served; errors never show traces; `AppHeaders` and CORS as in
  Vaultwarden; client IPs only from trusted proxies; HTML in templates escaped like handlebars-rust.

## Not reviewed

- The Bitwarden clients, the web vault and the cryptography they do; the server only stores what they
  encrypt.
- PHP, Laravel and the other packages beyond `composer audit`, and the web server configurations.
- Behaviour that only shows on MySQL or PostgreSQL (collations, locking), on Windows, or under real
  concurrency; DNS rebinding was not tried against a rebinding DNS server.
- Denial of service in general, the release pipeline and the GitHub workflows.
