# Continuous integration

GitHub Actions runs `.github/workflows/ci.yml` on pull requests, pushes to `main`, and manual dispatch. All jobs have read-only repository permissions and time limits; superseded runs are cancelled. The `ci-required` job fails if any dependency fails, is cancelled or is skipped. Configure branch rules to require this check after its first successful run.

Runtime PHP syntax is checked across the declared minimum, PHP 8.3 and PHP 8.5. CI runs `bash build.sh` and `.github/scripts/check-package.py` to check the versioned ZIP for corruption, expected plugin root/bootstrap/uninstall files, unsafe paths and accidentally included development files.

There is no existing automated functional test suite in this repository. These checks verify syntax and packaging; they do not prove live WordPress, upstream plugins, cron or mail behavior. No package is published.
