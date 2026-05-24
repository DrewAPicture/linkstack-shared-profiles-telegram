# Commit Message Guidelines

This document outlines commit message guidelines for maintaining consistent, professional commit messages.

## Core Principles

### 1. **Structure**
- **Concise summary line**: Clear, descriptive first line (50-72 characters recommended)
- **Detailed description**: Bulleted list of specific changes when multiple items are involved
- **HEREDOC format**: Use `cat <<'EOF'` syntax for multi-line commit messages

### 2. **Writing Style**
- **Imperative present tense**: Write as commands ("Add", "Update", "Fix", "Remove")
- **No emoji**: Keep messages clean and professional
- **Avoid flowery language**: Be direct and factual
- **Focus on what, not why**: Describe the changes, not the reasoning (unless critical)

### 3. **Content Guidelines**
- **Accurate reflection**: Ensure the message accurately reflects the changes made
- **Specific details**: Include relevant file names, component names, or feature areas
- **Combine related changes**: Group logically related changes in single commits
- **Avoid mentioning mistakes**: Don't reference previous errors or corrections

## Format Examples

### Simple Changes
```
Add thing to component.
```

### Complex Changes with Multiple Items
```
Add things to component

- Thing here in brief description
- Another thing here
- Perhaps a multi-part thing goes here, here, and here
```

### 1. **Documentation Updates**
```
Add PHPDoc to public API methods in SomeClass

- Add @since tags to all public methods
- Add @throws tags for ExceptionA and ExceptionB
- Add array shape annotations to methods returning structured arrays
```

### 3. **Namespace / Structural Changes**
```
Reorganize auth contracts into dedicated Contracts/ directory

- Move thingA and thingB to Some/Directory
- Update all imports across src/ and tests/
- Align structure with existing service contract layout
```

## Command Format

Always use HEREDOC format for multi-line messages:

```bash
git commit -m "$(cat <<'EOF'
Summary line here

- First change detail
- Second change detail
- Third change detail

EOF
)"
```

## What to Avoid

### Don't Use
- Emoji in commit messages
- Past tense ("Added", "Fixed", "Updated")
- Vague descriptions ("Various changes", "Updates")
- References to mistakes ("Fix previous error", "Correct implementation")
- Overly verbose explanations

### Do Use
- Present imperative tense ("Add", "Fix", "Update")
- Specific component/file names
- Clear, factual descriptions
- Logical grouping of related changes
- Professional, concise language

## Commit Frequency

- **Logical units**: Each commit should represent a complete, logical change
- **Related changes**: Group related changes together rather than making many small commits
- **Functional completeness**: Ensure each commit leaves the codebase in a working state
- **Clear boundaries**: Separate unrelated changes into different commits
