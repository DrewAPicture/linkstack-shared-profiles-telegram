# GitHub Actions Best Practices

## Pin actions to full commit SHAs

Never reference an action by a mutable version tag (`@v4`, `@main`, `@latest`). Tags can be silently moved to a different commit; a SHA cannot. This is a supply-chain security requirement for any public repo.

**Pattern:**
```yaml
uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683  # v4.2.2
```

The inline comment preserves human-readable intent without sacrificing immutability.

**How to find the SHA:**  
Go to the action's GitHub page → Releases → click the tag you want → copy the full commit SHA from the URL or the commit list.

Always verify SHAs against the action's releases page before committing.

---

## Keep all sensitive values in GitHub Secrets

No identifying or infrastructure detail may appear in workflow files in plain text.

**Never hardcode:**
- SSH hostnames or IP addresses
- SSH ports
- SSH usernames
- Server-side directory paths
- Private keys or tokens (beyond `GITHUB_TOKEN`, which is auto-provided)

**Always use `${{ secrets.NAME }}`:**

```yaml
# Bad — reveals infrastructure
- run: rsync -avz -e "ssh -p 2200" _site/ deploy@192.0.2.10:/home/deploy/public_html/

# Good — nothing identifying is in the workflow file
- run: rsync -avz --delete -e "ssh -p ${{ secrets.SSH_PORT }}" _site/ ${{ secrets.SSH_USER }}@${{ secrets.SSH_HOST }}:${{ secrets.DEPLOY_PATH }}
```

`GITHUB_TOKEN` is injected automatically — do not create a secret for it.

---

## Do not expose secret values in logs

GitHub automatically masks `${{ secrets.* }}` in step output. Do not work around this by echoing secrets into intermediate variables or files that are then logged. If a value must be masked that isn't a native secret, prefix its echo with `::add-mask::`:

```yaml
- run: |
    VALUE=$(some-command)
    echo "::add-mask::$VALUE"
```

---

## Pre-commit check for workflow files

Before committing any file under `.github/workflows/`, verify:

1. Every `uses:` line references a full 40-character SHA, not a tag or branch name
2. No literal hostnames, IP addresses, ports, usernames, paths, or credentials appear in plain text
3. All sensitive values are accessed via `${{ secrets.NAME }}`
