# Continuous integration

GitHub Actions runs `.github/workflows/ci.yml` on pull requests, pushes to `main`, and manual dispatch. All jobs have read-only repository permissions and time limits; superseded runs are cancelled. The `ci-required` job fails if any dependency fails, is cancelled or is skipped. Configure branch rules to require this check after its first successful run.

Runtime PHP syntax is checked across the declared minimum, PHP 8.3 and PHP 8.5. CI runs `bash build.sh` and `.github/scripts/check-package.py` to check the versioned ZIP for corruption, expected plugin root/bootstrap/uninstall files, unsafe paths and accidentally included development files.

The dependency-free scheduling harness (`php tests/scheduler.php`) runs on every PHP matrix entry. It exercises the actual scheduler with in-memory WordPress hook/storage doubles and a mail transport double. It does not prove real WordPress database queries, EventON runtime behavior or SMTP delivery. No package is published.
