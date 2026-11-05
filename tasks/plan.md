# Implementation Plan: Language access control policy (#46030)

Spec: [`SPEC.md`](../SPEC.md). Task list: [`tasks/todo.md`](todo.md).

## Overview

Add an "Allow specific languages" form access policy to the advancedforms plugin through
GLPI core's `FormAccessControlManager::registerPluginAccessControlPolicy()`. It votes
`Grant` when the authenticated user's session language is in the configured list, and
`Deny` otherwise, so the language always restricts the other policies. No GLPI core
change and no DB schema change.

## Architecture Decisions

- **Mirror `AllowList` / `DirectAccess`**: two classes, the strategy
  (`ControlTypeInterface`) and its config (`JsonFieldInterface`), plus one Twig template.
  New namespace `GlpiPlugin\Advancedforms\Model\AccessControl`.
- **Language source**: `$_SESSION['glpilanguage']`. `SessionInfo` doesn't expose the
  language, and the session language is what "GLPI is set in French" means.
- **Vote**: unlisted language → `Deny` (restricts every other policy); listed →
  `Grant` if authenticated, `Abstain` if anonymous, so the policy can't be used to get
  around Direct access's token.
- **Validation**: `createConfigFromUserInput()` keeps only codes that are keys of
  `Glpi\Locale\LanguageRegistry` and removes duplicates.
- **Export**: `new DynamicExportDataField($config->jsonSerialize(), [])`, the same as
  DirectAccess. There are no DB ids, so import passes the data through unchanged.
- **Registration**: unconditional in `InitManager`, guarded against double
  registration if `init()` runs again, the same way `registerDestinationFields()` is.
  Core already creates the policy inactive on each form.
- **Uninstall**: delete the `FormAccessControl` rows whose `strategy` is this class.

## Dependency Graph

```
LanguageAccessControlConfig
        │
LanguageAccessControl (vote, validation, export, warnings)
        │
 ├── template + InitManager registration ──→ visible in UI, enforced by canAnswerForm()
 │          │
 │          └── Playwright spec
 └── InstallManager uninstall cleanup
```

## Task List

### Phase 1: Policy logic
- [x] Task 1: Config + strategy classes with vote, validation and export (unit tested)

### Phase 2: Wire into GLPI
- [x] Task 2: Config form template + registration; integration through `canAnswerForm()`

### Checkpoint A
- [x] `make phpunit` (new + existing) green, `make phpstan` clean
- [x] Manual: the policy appears inactive in a form's Access controls tab, saves the
      languages, hides the form from the catalog for a non-listed language

### Phase 3: Lifecycle and end-to-end
- [x] Task 3: Uninstall cleanup
- [x] Task 4: Playwright spec

### Phase 4: Finish
- [x] Task 5: CHANGELOG, full `make test` (locales handled by CI workflows)

### Checkpoint: Complete
- [x] SPEC acceptance criteria 1–12 met
- [x] `make test` green (except 8 LDAP tests needing an LDAP server)
- [x] Human review before commit/PR

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Twig input naming must match what core's access-control form posts to `createConfigFromUserInput()` | High | Copy the field naming from core's `allow_list.html.twig`; the Task 2 integration test saves through the real form input path |
| Multi-select posts nothing when it is emptied, so the old value could survive | Med | A missing key → empty list; covered by a unit test |
| Language list for the select | Low | `$CFG_GLPI['languages']` is deprecated (11.0): use `Glpi\Locale\LanguageRegistry` (`has()` for validation, `all()` / `toLegacyArray()` for the select) |
| Tests depend on global `$_SESSION['glpilanguage']` | Low | Set and restore it in each test; `AdvancedFormsTestCase` resets session |
| Twig template path under the plugin namespace (`@advancedforms/...`) | Low | Same pattern as `ConfigManager::renderConfigForm()` |

## Parallelization

Task 3 only depends on Task 1 and can run alongside Task 2. Task 4 needs Task 2.

## Open Questions

None.
