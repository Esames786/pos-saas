# Edge package: the PHP runtime must be portable (Khatri 0.8.4 → 0.8.5, 21 Nov 2026)

## What broke

`EdgePackageBuilder` copies the PHP runtime directory **verbatim** into the package (`php\`). Until 0.8.4 that source was the
build laptop's Laragon PHP (`D:\laragon2\bin\php\php-8.3.16-Win32-vs16-x64`), whose `php.ini` carries absolute laptop paths:

| `php.ini` key | Laragon value (laptop-absolute) |
|---|---|
| `extension_dir` | `D:\laragon2\bin\php\php-8.3.16-…\ext` |
| `include_path` | a laptop folder |
| `error_log` | a laptop log folder |
| `sendmail_path` | laptop `sendmail` |
| `session.save_path` | laptop `tmp` |
| `curl.cainfo` | laptop CA bundle |

On the Khatri PC (no `D:\laragon2`) the shipped `php.exe` pointed at folders that do not exist, so the signed 0.8.4-edge
package could not be installed. The package itself was intact (signature verified) — the runtime it shipped was only valid on
the laptop that built it.

## What 0.8.5-edge is

Same app code as 0.8.4 (commit a23d1f26), rebuilt from a **portable PHP copy** at
`C:\Users\Dell\BingooEdgeLab\php-runtime-8.3.16-portable` (the same PHP 8.3.16 x64 build) whose `php.ini` has:

- `extension_dir = "ext"` (relative to `php.exe`)
- `include_path = "."`
- `error_log`, `sendmail_path`, `session.save_path` commented out (PHP defaults: stderr / none / system temp)
- `curl.cainfo = "C:\Program Files\Bingoo Edge\php\cacert.txt"` and `openssl.cafile = "C:\Program Files\Bingoo Edge\php\cacert.txt"`
  — the CA bundle is shipped as **`cacert.txt`** because the package boundary (`EdgePackageBuilder::PACKAGE_FORBIDDEN`,
  `#\.(pem|key|pfx|p12)$#i`, plus the artifact's own list) forbids `*.pem` / `*.crt` inside the package; the file content is the
  ordinary Mozilla bundle. This pins the install root to the default
  `-InstallRoot 'C:\Program Files\Bingoo Edge'`; a different install root needs a rebuilt runtime (or a later builder that rewrites it).

Installed at Khatri on 21 Nov 2026 (pairing against the central domain `https://bingoopos.com`; see
`khatri-onsite-install-steps-084-2026-11-21.md`).

## TODO (not implemented now — owner decision on the builder)

`EdgePackageBuilder` must **refuse** a PHP runtime whose `php.ini`:

1. has an absolute `extension_dir` (anything other than `ext` / `"ext"` / a path relative to `php.exe`), or
2. contains a Laragon / build-machine path (`laragon`, the builder's home directory, any drive-letter path that is not under
   the install root), in any of `extension_dir`, `include_path`, `error_log`, `sendmail_path`, `session.save_path`,
   `curl.cainfo`, `openssl.cafile`, `upload_tmp_dir`, `sys_temp_dir`, `opcache.file_cache`.

Fail closed with the offending key and value (a `php.ini` without an `extension_dir` is also a refusal: PHP then looks in its
compiled-in Laragon default). Add the check to `EdgePackageBuilderTest` with a fixture `php.ini` carrying a laptop path, and make
the release checklist in `edge-p5b-release-operations.md` reference it. Until then every build must start from the portable copy
above, and `Get-Content <package>\php\php.ini | Select-String 'laragon|D:\\'` must print nothing before signing.
