# Git Safety Protocol

This document provides comprehensive safety protocols for git operations that could affect uncommitted work.

## ⚠️ Core Safety Principle

**NEVER assume the working directory is clean.** Users may have valuable uncommitted changes from previous sessions that are not documented in conversation history. Always check for and preserve work before any potentially destructive git operation.

## Mandatory Check-First Protocol

### Always run `git status` before any git operation that affects the working tree

Before running `git checkout`, `git switch`, `git merge`, `git rebase`, `git reset`, or `git clean`, run:

```bash
git status --porcelain
```

If the output is non-empty, **stop and surface the situation to the user**. Do not proceed with the operation until the working tree is clean or the user has explicitly acknowledged the risk.

### Safe Branch Switch Workflow

```bash
# 1. Check for uncommitted changes
git status --porcelain

# 2a. If clean — safe to switch
git checkout <branch>

# 2b. If dirty — DO NOT switch. Instead, inform the user:
#     "There are uncommitted changes in the working tree. Please commit or
#      stash them before switching branches. To stash: git stash -u"
```

### When the User Asks to Stash and Switch

Only stash on explicit user instruction. When stashing:

```bash
# Stash everything including untracked files
git stash -u -m "Stash before switching to <branch>"

# Switch branch
git checkout <branch>

# Inform user how to restore
# "Your changes are stashed. Run 'git stash pop' to restore them."
```

## Stash Management

### Stash Options

- `git stash` — stashes only tracked files
- `git stash -u` — stashes tracked and untracked files (recommended)
- `git stash -m "message"` — adds a descriptive label

### Recovery Commands

```bash
# List all stashes
git stash list

# Preview what's in the most recent stash
git stash show -p

# Restore most recent stash (removes from stash list)
git stash pop

# Apply without removing from stash list
git stash apply

# Drop a stash without applying
git stash drop stash@{0}
```

### If `git stash pop` Creates Conflicts

```bash
# 1. Resolve conflicts in affected files
# 2. Stage resolved files
git add <resolved-files>
# 3. The stash entry can then be dropped manually
git stash drop
```

## Commits

- **NEVER** create a git commit without explicit user authorization
- If the user says "commit as you go" or "commit after each phase", that authorization applies only to the phases of that specific task — not to follow-up tasks or any subsequent work in the same session
- Always show the user what will be staged (`git diff --staged` or a summary) before committing

## Absolute Prohibitions

The following must never be performed under any circumstances without explicit user permission:

- **NEVER** update git config
- **NEVER** force push (`--force` / `-f`) to any branch
- **NEVER** run `git reset --hard` on committed history
- **NEVER** delete a branch without user confirmation
- **NEVER** skip commit hooks (`--no-verify`) unless explicitly requested
- **NEVER** push to a remote repository without explicit user authorization
- **NEVER** switch branches with a dirty working tree — check first, then stop and inform the user

## Emergency Recovery

If a git operation was run without checking for uncommitted changes first:

```bash
# Check reflog for recent HEAD positions
git reflog

# Recover a previous state if needed
git checkout HEAD@{1}

# Check current status
git status
```

## Summary

1. Always run `git status --porcelain` before any operation that affects the working tree
2. Stop and inform the user if the tree is dirty — never silently proceed
3. Only stash on explicit user instruction, with a descriptive message
4. Commit authorization is task-scoped, not session-scoped
5. When in doubt, do nothing and ask
