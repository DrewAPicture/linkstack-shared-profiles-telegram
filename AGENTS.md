# Telegram Provider for Shared Profiles for LinkStack — Agent Guidelines

## Stop Conditions

**Stop and ask the user before proceeding if any of the following apply:**

- You are about to commit, push, or modify git history in any way
- The working tree is dirty before a branch switch, reset, or merge
- You are considering a force push — this is never permitted
- You are unsure whether a destructive action is reversible
- A file you are about to commit contains sensitive or identifying information (SSH ports, server paths, credentials, local filesystem paths) — strip it first

Commit authorization is **task-scoped**: explicit approval to commit during one task does not carry over to follow-up tasks or future sessions. Always assume you do not have commit permission unless the user has said so in the current task.

See full protocol: [`.agent/context/best-practices/git-safety-protocol.md`](.agent/context/best-practices/git-safety-protocol.md)

---

## Project


---

## Context


---

## Best Practices

- [Commit Messages](.agent/context/best-practices/commit-messages.md) — imperative tense, no emoji, HEREDOC format
- [Git Safety Protocol](.agent/context/best-practices/git-safety-protocol.md) — check working tree, no force push, commit scope
- [Accessibility](.agent/context/best-practices/accessibility.md) — WCAG 2.2 Level AA for all rendered views
- [GitHub Actions](.agent/context/best-practices/github-actions.md) — pin actions to SHAs, keep secrets out of workflow files
- [PHP Imports](.agent/context/best-practices/php-imports.md) — always `use`-import classes; no leading backslashes inline
