# Code Review: codraw/log

## Fixes applied (2026-07-20)

- **composer.json:** PHP version constraint changed from unbounded `>=8.5` to `^8.5` (version-compatibility debt: prevents a future PHP 9 from installing against this package; no effect on any currently existing PHP version).
- **H1** — `DependencyInjection/Compiler/LoggerDecoratorPass.php`: fixed the invalid named-argument keys on the inline `DecoratedLogger` definitions — `'defaultContext'` → `'$defaultContext'`, `'decorateMessage'` → `'$decorateMessage'` (lines 99-100) and `'logger'` → `'$logger'` on the bindings path (line 66). Container compilation no longer fails when the `logger.decorate` tag is used.
- **M2** — `composer.json`: moved `monolog/monolog ^3.5.0` from `require-dev` to `require` (core dependency: `Monolog/` classes, `LogRecord` in every processor); added `symfony/service-contracts ^2.5 || ^3.0` to `require` (`ResetInterface` in `DecoratedLogger` and now `DelayProcessor`); added `symfony/monolog-bridge ^6.4.0` to `require-dev` (`LogIntegrationTest` imports `ConsoleCommandProcessor`); added a `suggest` section covering the optional Symfony/codraw integrations (`codraw/dependency-injection`, `codraw/user-bundle`, `symfony/dependency-injection`, `symfony/event-dispatcher`, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/monolog-bridge`, `symfony/security-core`). Open items: `"php": ">=8.5"` kept as-is (repo convention across all codraw packages) and no `conflict` section was added.
- **M3** — `Monolog/Processor/DelayProcessor.php`: now implements `Symfony\Contracts\Service\ResetInterface`, so autoconfiguration adds the `kernel.reset` tag and `reset()` is actually invoked in long-running processes.
- **M4** — `DependencyInjection/LogIntegration.php:123`: removed the `?: 10000` fallback (`$requestMatcher['duration'] ?? $defaultDuration`); `default_duration` always has an integer value from config, and a configured `0` is no longer silently replaced by `10000`.
- **L2** — `DependencyInjection/Compiler/LoggerDecoratorPass.php:58-64`: the existing `Psr\Log\LoggerInterface` binding value is now passed through unchanged when it is already a `Reference` or `Definition`, instead of being fed to `new Reference()` (which fatals on a `Definition`).

### Validation pass (2026-07-20)

- `composer install` succeeds with the constraints above (no adjustment needed). Locally, `codraw/user-bundle` had to be installed with `--prefer-source` because the GitHub dist download could not authenticate in this environment; this is an environment limitation, not a composer.json issue.
- `vendor/bin/phpunit` (PHPUnit 12.5.31, PHP 8.5.8): **25 tests, 101 assertions, 0 failures** — identical result with and without the fixes, so no test fallout; no test expectations needed updating. The 7 "PHPUnit Notices" (mock objects without expectations in `DecoratedLoggerTest`, `SlowRequestLoggerTest`, `TokenProcessorTest`) are pre-existing test-style notices unaffected by the fixes (exit code 0).
- PHPStan level 5: 7 errors reported, all pre-existing (verified via `git stash`: same 7 errors on the unmodified tree) and none introduced or removable by the fixes — `LoggerDecoratorPass.php:55` (`function.alreadyNarrowedType`, the L4 dead guard), `LogIntegration.php:175/180/234/260` (`method.notFound`/`return.type` on config-builder fluent chains), `RequestHeadersProcessor.php:13/15` (`property.unusedType`). The baseline is empty; these likely stem from a newer PHPStan than CI pins. Left untouched per L4/config findings still being open.
- markdownlint-cli2: clean after `--fix` added the missing trailing newline to this file; no other file changed.

## Overall Assessment

`codraw/log` is a small, focused package providing a decorating PSR-3 logger, a set of Monolog processors (delay, request headers, security token), a slow-request logger listener, and a DI integration for the codraw framework. Most of the runtime code is clean, idiomatic Monolog 3 / Symfony 6.4 code, and the processors and listener are well tested. However, the package has one serious defect: the `LoggerDecoratorPass` compiler pass — the feature behind the `logger.decorate` tag — builds inline service definitions with invalid named-argument keys (missing the `$` prefix), which makes Symfony container compilation fail as soon as the tag is used. Tellingly, this is the only class in the package with no test coverage at all. Beyond that, there are a few medium concerns: sensitive request headers (Authorization, Cookie) are logged by default, `composer.json` massively under-declares the dependencies most of the classes need, `DelayProcessor::reset()` is never actually invoked by the framework, and a configured slow-request threshold of `0` is silently coerced to `10000`.

## Findings

### High

#### **[FIXED]** H1. `LoggerDecoratorPass` produces definitions with invalid argument names — container compilation fails when the `logger.decorate` tag is used

`DependencyInjection/Compiler/LoggerDecoratorPass.php:99-100` and `:66`

```php
return (new Definition(DecoratedLogger::class))
    ->setArgument('defaultContext', $tag)      // line 99 — missing '$'
    ->setArgument('decorateMessage', $message) // line 100 — missing '$'
...
    ->setArgument('logger', $argument)         // line 66 — missing '$'
```

Symfony's named constructor arguments must be keyed `'$paramName'` (or a FQCN type). The pass itself proves the author knows this — line 37 correctly uses `->setArgument('$logger', $argument)` — but the three keys above lack the prefix. Consequences:

- For decorators injected as constructor arguments/method-call arguments (lines 34-38, 48-50): the inline `DecoratedLogger` definition ends up with argument keys `defaultContext` (array value) and `decorateMessage` (string value). `ResolveNamedArgumentsPass` recurses into inline definitions and, for a non-`$` string key whose value is not `null`/`Reference`/`Definition`, throws `InvalidArgumentException` ("the value of argument "defaultContext" ... must be null, an instance of Reference or Definition, array given"). Container compilation aborts.
- For the bindings path (line 66): the key `logger` is interpreted as a type name; since `DecoratedLogger::__construct()` has no parameter type-hinted `logger`, this also fails (either in `ResolveNamedArgumentsPass` if reached, or in `CheckArgumentsValidityPass` — "integer expected but found string" — after `ResolveBindingsPass` injects the definition).

Net effect: tagging any service with `logger.decorate` while `monolog.logger` is registered breaks the container build. This is the package's headline feature and it cannot work as written. The convention used elsewhere in the framework confirms the expectation: `IntegrationTrait::arrayToArgumentsArray()` (codraw-dependency-injection, line 157-165) explicitly prefixes every key with `'$'`. There is no test for this compiler pass, which is why the defect is undetected.

Fix: use `'$defaultContext'`, `'$decorateMessage'`, and `'$logger'` respectively.

### Medium

#### M1. `RequestHeadersProcessor` logs all request headers by default, including credentials

`Symfony/Processor/RequestHeadersProcessor.php:37-47`, defaults in `DependencyInjection/LogIntegration.php:202-213`

When enabled (including via `enable_all_processors: true`), the processor copies `$request->headers->all()` into every log record's `extra` unless `onlyHeaders`/`ignoreHeaders` are configured. `ignoreHeaders` defaults to `[]`, so `Authorization`, `Cookie`, `X-Api-Key`, CSRF tokens, etc. are written verbatim to the log pipeline. This is a credential/PII-leak-by-default. Recommend a safe default ignore list (at minimum `authorization`, `cookie`, `proxy-authorization`, `php-auth-*`) or a prominent documentation warning.

#### **[FIXED]** M2. `composer.json` under-declares dependencies — most classes are unusable with the declared requirements

`composer.json:17-20`

`require` lists only `php >=8.5` and `psr/log ^3`, yet:

- `Monolog/ErrorToArray.php`, `Monolog/Processor/DelayProcessor.php`, both Symfony processors, and `DecoratedLogger::reset()` reference `monolog/monolog` classes (`JsonFormatter`, `LogRecord`, `ResettableInterface`).
- `DecoratedLogger.php:8` references `Symfony\Contracts\Service\ResetInterface` (`symfony/service-contracts` — not even in `require-dev` directly).
- `Symfony/EventListener/SlowRequestLoggerListener.php` needs `symfony/http-kernel`/`event-dispatcher`; `TokenProcessor` needs `symfony/security-core`; `LogIntegration` needs `symfony/config`, `symfony/dependency-injection`, `symfony/monolog-bridge` (`ConsoleCommandProcessor`), and `codraw/dependency-injection`.
- `TokenProcessor.php:5` references `Draw\Bundle\UserBundle\Entity\SecurityUserInterface` (`codraw/user-bundle`).

The "optional dependency" component pattern is legitimate, but there is no `suggest` section and no `conflict` constraints (e.g. against `monolog < 3`), so a standalone `composer require codraw/log` yields a package where almost everything fatals with "class not found" at runtime with no guidance. Also, `"php": ">=8.5"` is unbounded (allows PHP 9) — `^8.5` would be safer.

#### **[FIXED]** M3. `DelayProcessor::reset()` is never invoked — stale baseline in long-running processes

`Monolog/Processor/DelayProcessor.php:26-29`

The class has a `reset()` method, but implements neither `Symfony\Contracts\Service\ResetInterface` nor Monolog's `ResettableInterface`. Autoconfiguration only adds the `kernel.reset` tag for `ResetInterface` implementors, and Monolog only resets processors implementing `ResettableInterface`, so nothing ever calls `reset()`. In messenger workers or other long-running processes, `$this->start` is initialized once from `REQUEST_TIME_FLOAT` (process start) and the reported "delay" grows monotonically forever instead of being per-message/per-request. Implement `ResetInterface` (and/or `ResettableInterface`) so the existing reset logic actually runs.

#### **[FIXED]** M4. A configured slow-request duration of `0` is silently replaced by `10000`

`DependencyInjection/LogIntegration.php:123`

```php
$duration = $requestMatcher['duration'] ?? $defaultDuration ?: 10000;
```

Operator precedence makes this `($requestMatcher['duration'] ?? $defaultDuration) ?: 10000`. The config explicitly allows `0` (`integerNode('default_duration')->min(0)`, line 174), which is the natural way to say "log every request", but `0 ?: 10000` evaluates to `10000`, so the intent is silently discarded. Related dead code: `null !== $defaultDuration` (line 106) can never be false because the node has a default and cannot be null.

### Low

#### L1. `DelayProcessor` emits a locale-formatted string instead of a numeric value

`Monolog/Processor/DelayProcessor.php:21` — `number_format(microtime(true) - $this->start, 2)` returns a string, and for delays ≥ 1000 seconds includes a thousands separator (`"1,234.57"`), which breaks numeric parsing/aggregation in log backends. `round(..., 2)` (float) would be more robust.

#### **[FIXED]** L2. `LoggerDecoratorPass` binding rewrap assumes the bound value is a string/Reference

`DependencyInjection/Compiler/LoggerDecoratorPass.php:59` — `new Reference($bindings['Psr\Log\LoggerInterface']->getValues()[0])` works for a string id and (via non-strict `__toString` coercion) for a `Reference`, but fatals with an `Error` if the existing binding is an inline `Definition`. Edge case, but worth hardening.

#### L3. `kernel.reset` tag on inline decorator definitions is ineffective

`DependencyInjection/Compiler/LoggerDecoratorPass.php:101` — the `DecoratedLogger` definitions are anonymous inline definitions used as arguments; they are never registered under a service id, so `findTaggedServiceIds()` never sees the `kernel.reset` tag and `ServicesResetter` will never call `reset()` on them. Harmless in practice (the inner logger service is reset directly), but the tag is dead code and gives a false sense of behavior.

#### L4. Dead/misleading guard code

- `LoggerDecoratorPass.php:21` — `class_exists(DecoratedLogger::class)` is always true: the pass lives in the same package as the class, and the error message references installing `draw/log` (the package this code *is*).
- `LoggerDecoratorPass.php:55` — `method_exists($definition, 'getBindings')` is always true on all supported Symfony versions.
- `Symfony/EventListener/SlowRequestLoggerListener.php:32` — duplicated `$request = $event->getRequest();` (already assigned at line 26).

#### L5. Component depends on a bundle's entity interface

`Symfony/Processor/TokenProcessor.php:5,33` — a `Draw\Component\*` class referencing `Draw\Bundle\UserBundle\Entity\SecurityUserInterface` inverts the component→bundle dependency direction. It only degrades gracefully because `instanceof` against an undefined interface returns false. A local interface (or duck-typed `method_exists($user, 'getId')`) would decouple the component.

#### L6. Vacuous test

`Tests/Symfony/Processor/RequestHeadersProcessorTest.php:131` — `testInvokeNoRequestStack()` constructs the processor but never calls `__invoke()`; it asserts that a freshly created `LogRecord` has empty `extra`, which is true regardless of the processor. The null-`RequestStack` branch (`RequestHeadersProcessor.php:29-31`) is therefore untested.

## Strengths

- Correct, idiomatic Monolog 3 usage: `$record['extra'][$key] = ...` works precisely because `LogRecord::offsetGet()` returns `extra` by reference — the processors use the supported mutation pattern.
- `ErrorToArray` cleverly reuses Monolog's battle-tested `JsonFormatter::normalizeException()` (exposed via a small anonymous subclass) instead of reimplementing exception normalization, including nested previous exceptions and stack-trace toggling.
- `LogIntegration` uses the modern, non-deprecated `RequestMatcher\*` classes and `ChainRequestMatcher` (the deprecated monolithic `RequestMatcher` is never instantiated), with a well-structured, `canBeEnabled()`-based configuration tree including XML normalization (`fixXmlConfig`) and string-to-array normalizers.
- `DecoratedLogger` is a clean PSR-3 decorator: gracefully appends `{message}` when the token is missing from the template, and merges context so call-site context wins over defaults.
- `SlowRequestLoggerListener` runs on `kernel.terminate` (after the response is sent) at high priority, matches per-threshold matcher groups, and uses the lowest matched threshold — sensible design with negligible request overhead.
- PHPStan level 5 with an *empty* baseline; consistent structure with the rest of the codraw framework (integration + compiler pass + tests mirroring source layout).

## Test Coverage

Coverage is good for the runtime classes and thin exactly where the bug is:

- **Well covered**: `DecoratedLogger` (message decoration, context merge, missing-token fallback — though `reset()` is untested), `DelayProcessor` (invoke, reset, default key), `RequestHeadersProcessor` (only/ignore/combination matrix via data provider), `TokenProcessor` (no token, anonymous token, identified user with `SecurityUserInterface`), `SlowRequestLoggerListener` (match and no-match paths, subscribed events), and `LogIntegration` (default configuration snapshot plus detailed assertions on compiled service definitions, including per-duration `ChainRequestMatcher` wiring).
- **Not covered at all**: `DependencyInjection/Compiler/LoggerDecoratorPass.php` — the class containing the high-severity defect (H1) — and `Monolog/ErrorToArray.php`.
- **Weak spots**: `RequestHeadersProcessorTest::testInvokeNoRequestStack()` never invokes the processor (L6); `DelayProcessorTest::testInvoke()` recomputes `number_format(microtime(...) ...)` at assertion time, which can flake when the two calls straddle a 10 ms rounding boundary; the `duration = 0` edge case of the slow-request config (M4) is untested.
