# Release procedure

1. Review the complete release tree, dependency changes and process/permission trust boundaries. Run the security
   review before committing and pushing. Record the release scope and breaking status in `CHANGELOG.md` and keep
   `Prism\Acp\Version::VERSION` aligned with the proposed tag.
2. Pass the org third-party allowlist before dependency installation. Run the Windows/Ubuntu test matrix,
   PHPStan max, Pint, Rector, Composer require checker, audit and fixture regeneration against the pinned published
   TypeScript reference. Include a hostile negative control for a changed safety property.
3. Push the reviewed commit. Read Actions runs filtered to its exact SHA and require every job to pass. Configure
   required checks on the default branch. Do not treat a run for another commit as release evidence.
4. Only then create the version tag and GitHub release. A preview uses a pre-release tag and is marked as such on
   GitHub. Tags are immutable consumer artifacts.
5. First publication: submit the public repository URL to Packagist. The documented API is
   `POST https://packagist.org/api/create-package` with JSON `{"repository":"https://github.com/Particle-Academy/prism-acp"}`
   and main-token bearer authentication. Never put credentials in URLs, command arguments, logs or this repository.
   See [Packagist API](https://packagist.org/apidoc#create-a-package).
6. Verify the uncached registry response and install the exact published version into an empty directory using
   `--prefer-dist --no-dev --no-scripts --no-plugins`. Verify the source reference and run hostile framing,
   credential-withholding and permission tests using the installed autoloader; on Windows exercise containment.
7. Store the SHA, CI identity, registry version, downloaded artifact hash and consumer results with release evidence.
   Update dependent applications only after the artifact is installable. Never infer publication from a green job.

CI uses no provider credentials and performs no model turns. Future Linux support needs full descendant tracking
and conservative stop verdicts; a process group alone is not sufficient containment.
