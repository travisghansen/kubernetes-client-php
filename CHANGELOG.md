# v0.4.6

Released 2026-11-19

- periodically re-read token files (in-cluster service account token, kubeconfig `tokenFile`) to support token rotation (matches `client-go` behavior)
- support `tokenFile` in kubeconfig users
- refresh exec provider credentials on expiry even when only a client cert/key is returned
- remove stray `var_dump` from watch connection errors

# v0.4.5

Released 2025-03-22

- force proper `json_encode` flags (see #12)
- fix php 8.4 warnings (see #17)

# v0.4.2

Released 2023-10-10

- support for non-associative array responses

# v0.4.1

Released 2023-10-10

- add `unset` method to `Dotty`

# v0.4.0

Released 2023-10-09

- better support for `pcntl` signal handling
- more control over how requests / responses are handled (allow control of encode/decoding options)
- support for `ReactPHP` loops
- small internal library (`Dotty`) useful for interacting with structured data (arrays, stdobject)
- update composer deps
