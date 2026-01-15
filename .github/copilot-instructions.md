# Agents Instructions

> **Note**: This file contains project-wide Copilot instructions that apply to all developers.
> You can create a personal `.github/copilot-instructions.local.md` file for your own preferences
> (this file is gitignored and won't be committed). Personal instructions complement these project instructions.

## Project Context

This is the **NCW Tools** app - a Nextcloud external app that provides utility functionality for the Nextcloud Webmail stack.

### Stack & Architecture

- **Backend**: PHP 8.1-8.4 with Nextcloud app framework
  - Service-oriented architecture: Controllers → Services → DB
  - Dependency injection via Nextcloud's DI container
- **Testing**: PHPUnit (unit/integration)
- **Build**: Composer, Make workflows

### Repository Structure

**Important**: This repository is checked out as a **git submodule** of the parent `ncw-server` repository located at `../../` (two directories up).

- **Parent Repository**: `../../ncw-server/`
- **This Submodule Location**: `apps-external/ncw_tools/`
- **Git Context**: This is a submodule, so git operations are scoped to this directory
- **Container Execution**: Tests and commands run inside containers via parent repo's `.dev/container/dev` script

### Key Directory Structure

```
lib/                      # PHP backend
├── Controller/          # HTTP/API endpoints (extend Controller/OCSController)
├── Service/            # Business logic
└── AppInfo/            # Bootstrap & DI registration (Application.php)

tests/
├── unit/              # PHPUnit unit tests
└── phpunit.xml        # Test configuration
```

## Architecture Patterns

### Backend: Service Layer Pattern

Controllers delegate to services for business logic:

```php
// Controller receives request
class ToolsController extends Controller {
    public function doSomething() {
        // Validate, then delegate to service
        return $this->toolsService->process(...);
    }
}

// Service contains logic
class ToolsService {
    // Uses mappers for DB access
}
```

## Developer Workflows

### Running Tests (Container-Based)

**All tests must run inside the container** via the parent repo's `.dev/container/dev` script:

```bash
# PHPUnit unit tests
cd ../../ && .dev/container/dev bash -c "cd /var/www/html/apps-external/ncw_tools && composer run test:unit"

# PHPUnit unit tests (specific file)
cd ../../ && .dev/container/dev bash -c "cd /var/www/html/apps-external/ncw_tools && vendor/bin/phpunit -c tests/phpunit.xml tests/unit/AppInfo/ApplicationTest.php"
```

### Build & Development

```bash
# Initial setup (install dependencies)
composer install

# Linting
composer run cs:fix     # PHP (php-cs-fixer)
```

## Code Quality Standards

### License Headers

All code files **must** include a SPDX license header with the current year (2026).

#### Shell Scripts (*.sh, run scripts)

```bash
#!/usr/bin/env bash

#
# SPDX-FileCopyrightText: 2026 STRATO GmbH
# SPDX-License-Identifier: AGPL-3.0-or-later
#
```

#### Python Scripts (*.py)

```python
#!/usr/bin/env python3

#
# SPDX-FileCopyrightText: 2026 STRATO GmbH
# SPDX-License-Identifier: AGPL-3.0-or-later
#
```

#### PHP files

```php
/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
```

#### Makefiles and Configuration Files

```makefile
# SPDX-FileCopyrightText: 2026 STRATO GmbH
# SPDX-License-Identifier: AGPL-3.0-or-later
```

### Shell Scripts

Shell scripts must meet the same quality standards as other code:

- **Always use `#!/usr/bin/env bash`** as the shebang (not `/bin/bash` or `/bin/sh`)
  > This improves portability across different systems, as `bash` may not always be located in `/bin/`.
- **Run `shellcheck`** on all shell scripts before committing to catch issues and ensure POSIX compliance
- Use **double quotes** for variables to prevent word splitting: `"${variable}"`
- Use **long-form options** when available for better readability: `--verbose` instead of `-v`
- Add **descriptive comments** for complex logic
- Use **functions** for repeated code blocks
- Handle **errors appropriately** with proper exit codes
- Include **usage/help** messages for user-facing scripts

### Python Scripts

- Use **Python 3** (specifically `#!/usr/bin/env python3`)
- Follow **PEP 8** style guidelines
- Include **type hints** where appropriate
- Add **docstrings** for functions and classes
- Handle **exceptions** properly
- Use **virtual environments** for dependencies (see `venv/` pattern)

### Documentation

- Keep documentation **up-to-date** with code changes
- Use **clear, concise language**
- Provide **examples** for complex workflows
- Document **dependencies and prerequisites**
- Include **troubleshooting** sections where relevant
- Follow **Markdown** best practices

### Makefiles

- Use **`.PHONY`** for non-file targets
- Include **help targets** with `##` comments for self-documentation
- Use **meaningful target names**
- Add **comments** for complex recipes
- Keep targets **focused and atomic**

### Documentation

- Keep documentation **up-to-date** with code changes
- Use **clear, concise language**
- Provide **examples** for complex workflows
- Document **dependencies and prerequisites**
- Include **troubleshooting** sections where relevant
- Follow **Markdown** best practices

## Git Workflow

### Commit Messages

- **MUST** use [Conventional Commits](https://www.conventionalcommits.org/) format
- no backticks in commit messages
- Structure: `<type>(<scope>): <description>`
- Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `build`, `ci`
- Create atomic commits (one logical change per commit)
- Explain *why*, not just *what*
- put new line after commit subject
- sign off commit messages using the git user.name and user.email configured in git config

### Commit Guidelines

- **Create atomic commits**: One logical change per commit
	- Fixing different shellcheck issues = separate commits per issue
	- Changing quotes from double to single = one commit
	- Adding a feature = one commit (but separate from unrelated fixes)
- **Only commit relevant changes**: No accidental debugging code, temp files, or unrelated modifications
- **Verify commit success**: Always check that `git commit` completed successfully
- **Write descriptive messages**: Explain *why*, not just *what*
- **Group related changes**: If modifying multiple files for one feature, that's one commit

### Code Review

- Reference any related issues or PRs in commit messages
- Keep commits reviewable (not too large)
- Ensure all tests pass before committing
- Run relevant quality checks (`shellcheck`, linters, etc.) before committing

### Pull Requests

When creating Pull Requests to merge into the **master** branch:

- **Use descriptive PR titles** following the Conventional Commits format: `<type>(<scope>): <description>` (scope is optional, e.g., `feat(auth): add OAuth2 support` or `fix: correct typo in README`)
- **Provide a clear description** that includes:
	- What changes are being made and why
	- Any breaking changes or important considerations
	- Related issue numbers (e.g., "Fixes #123" or "Relates to #456")
	- Testing steps or verification instructions
- **Ensure all checks pass**:
	- All commits follow Conventional Commits format
	- Code quality checks pass (shellcheck, linters, etc.)
	- No failing tests
	- License headers are present in all new files
- **Keep PRs focused**: One feature or fix per PR when possible
- **Request reviews** from appropriate team members
- **Address review feedback** promptly and professionally
- **Squash commits** if the PR history contains many small fixup commits (discuss with team)
- **Update documentation** if the PR changes user-facing features or APIs
- **Test in development environment** before requesting final review

### Common Task Prompts

These task prompts have proven effective for common workflows:

#### Interactive Rebase with Commit Message Rewording

```
Let's do interactive rebasing from <sha> to HEAD and reword the commit messages to reflect what has actually been done in commits.
Let's avoid user input and usage of vi, vim, nano.
```

This automatically:
- Uses `GIT_SEQUENCE_EDITOR` and `GIT_EDITOR` environment variables to avoid interactive editors
- Rewrites commit messages programmatically
- Applies changes without requiring manual text editor interaction

#### Evaluate and Reword Commit Message

```
Please evaluate change in HEAD and reword the commit message
```

This automatically:
- Reviews the actual code changes in the HEAD commit
- Compares the changes against the commit message
- Identifies any inaccuracies or missing details
- Rewrites the commit message to accurately reflect what was actually changed
- Ensures commit message matches the actual implementation

## Environment and Paths

- **Never hardcode absolute paths** in scripts (except for well-known system paths)
- Use **relative paths** from script location when possible
- Reference the **docs-and-tools path** via Makefile variables or script detection
- Support both **local development** and **container environments**
- Use **environment variables** for configuration (see `.env` pattern)
- Keep **secrets in `.env.secret`**, never commit them

## Container Best Practices

- Prefer **Podman** over Docker in examples and scripts
- Use **descriptive container names**: `nc-dev-container`, `minio`, `nextcloud-dev`
- **Clean up containers** properly (see `clean.sh` pattern)
- Make scripts **idempotent** where possible
- Support **both interactive and non-interactive** execution modes

# Additional
* add and commit only files that have changes you created
* check if `git commit` actually happened
* project runs in container
  * You can run tests for external apps locally with composer via: cd ../../ && .dev/container/dev bash -c "cd /var/www/html/apps-external/ncw_tools && composer run test:unit"
  * You can run tests for external apps locally with phpunit via: $ cd ../../ && ./.dev/container/dev bash -c "cd /var/www/html/apps-external/ncw_tools && vendor/bin/phpunit -c tests/phpunit.xml tests/unit/AppInfo/ApplicationTest.php"

# PHP
* do not modify vendor files
* Use the DRY (Don't Repeat Yourself) principle
* update relevant tests
* **Before committing PHP files, always run `composer run cs:fix`** to ensure code style compliance

## PHP Unittests
  * Don't use deprecated functions like 'withConsecutive'
  * Test files now extend Test\TestCase instead of PHPUnit\Framework\TestCase
  * Mock property declarations use intersection type syntax instead of PHPDoc annotations
  * always add `declare(strict_types=1);`
  * use single quotes for strings in PHP
  * Check if file you about to modify has unitests. If it has no unitests lets add one before adding our changes to the original file.
  * Use direct type definition instead of PHPDoc annotations:
```php
	/** @var SomeService&MockObject */
	private SomeService $someService;
```
use
```php
	private SomeService&MockObject $someService;
```
