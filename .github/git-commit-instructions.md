# Git Commit Instructions

See the **Git Workflow** section in [copilot-instructions.md](./copilot-instructions.md) for comprehensive commit message guidelines.

## Quick Reference

- **MUST** use [Conventional Commits](https://www.conventionalcommits.org/) format
- no backticks in commit messages
- Structure: `<type>(<scope>): <description>`
- Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `build`, `ci`
- Use empty line between commit subject and body
- Use empty line between commit body and sign-off
- Create atomic commits (one logical change per commit)
- Explain *why*, not just *what*
- put new line after commit subject
- commits should be signed off by: Misha M.-Kupriyanov <kupriyanov@strato.de>

## Fixup Commits

When addressing code review feedback or fixing issues in previous commits:

### Using git commit --fixup

For small fixes to previous commits that haven't been pushed yet or are in a PR:

```bash
# Create a fixup commit targeting a specific commit
git commit --fixup=<commit-hash>

# Example: Fix a typo in the previous commit
git commit --fixup=HEAD

# Example: Fix an issue in a specific earlier commit
git commit --fixup=abc1234
```

**When to use fixup commits:**
- Addressing code review feedback on a PR
- Fixing small issues (typos, formatting, minor bugs) in recent commits
- Making changes that logically belong to an earlier commit in your branch

**Important notes:**
- Fixup commits automatically prepend `fixup!` to the original commit message
- Before merging to master, use `git rebase -i --autosquash` to squash fixup commits into their targets
- Never push fixup commits to master - always squash them first
- Sign off fixup commits just like regular commits

### Autosquashing Fixup Commits

Before merging your PR or pushing to master:

```bash
# Interactive rebase with automatic squashing of fixup commits
git rebase -i --autosquash <base-branch>

# Example: Squash fixups before merging to master
git rebase -i --autosquash origin/master
```

This will automatically:
1. Reorder fixup commits to be after their target commits
2. Mark them for squashing during the interactive rebase
3. Combine the fixup changes into the original commits

### Alternative: Amending the Last Commit

For fixing the most recent commit (not yet pushed):

```bash
# Stage your changes
git add <files>

# Amend the last commit (keeps the same message)
git commit --amend --no-edit

# Amend and update the commit message
git commit --amend
```

**When to amend:**
- Fixing issues in the very last commit
- Adding forgotten files to the last commit
- The commit hasn't been pushed or shared yet

### Fixup vs Regular Commits

| Scenario | Use Fixup | Use Regular Commit |
|----------|-----------|-------------------|
| Fix typo in previous commit | ✓ | |
| Address PR review feedback | ✓ | |
| Add new feature | | ✓ |
| Fix unrelated bug | | ✓ |
| Improve previous commit's logic | ✓ | |
| Add completely new functionality | | ✓ |

### Best Practices

- **Keep fixup commits focused**: One fixup per issue/review comment
- **Reference review comments**: Include PR comment links in fixup commit bodies
- **Squash before merging**: Never merge fixup commits to master
- **Communicate with team**: Let reviewers know when you've pushed fixup commits
- **Test after squashing**: Run tests again after `git rebase -i --autosquash`
