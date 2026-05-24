# PHP Import Style

Always import classes with a `use` statement at the top of the file. Never reference a class by its fully qualified name (FQN) inline in code — not even with a leading backslash for global-namespace classes.

```php
// correct
use Generator;
use SensitiveParameter;

public function sendMessage(#[SensitiveParameter] string $botToken): bool { ... }
public static function provideData(): Generator { ... }

// wrong — FQN in code; leading backslash is a sign the import is missing
public function sendMessage(#[\SensitiveParameter] string $botToken): bool { ... }
public static function provideData(): \Generator { ... }
```

This applies to:

- PHP built-in classes (`stdClass`, `Throwable`, `Generator`, …)
- PHP 8.x attributes (`SensitiveParameter`, `Override`, …)
- Global-namespace classes from any dependency
- Namespaced classes from any dependency — use the short name after importing

**Why:** FQNs and inline backslashes are easy to miss in review, inconsistent with how the rest of the codebase references classes, and rejected by Pint's `fully_qualified_strict_types` fixer if it is ever enabled. A `use` statement at the top of the file is always unambiguous.
