# Spec: Language access control policy (#46030)

## Objective

As a GLPI form administrator, I want an **"Allow specific languages"** option in a
form's **Access controls** tab, so that I can choose the GLPI languages in which the form
is available. It grants access to users whose GLPI language is in the list and
**denies** everyone else, so the language is never ignored when combined with other
policies: "All users" + "English" means "all users whose GLPI is in English".

Example: a user's GLPI is in French (`fr_FR`). The form's only active policy is
"Allow specific languages" with `en_GB` and `de_DE`. The form does not show for that
user in the service catalog or on helpdesk tiles, and opening its direct URL is refused.
The same user is still refused when another active policy (e.g. "All users") grants
them, because core blocks access as soon as one policy denies.

Delivered entirely by the **advancedforms** plugin through GLPI core's existing extension
point `FormAccessControlManager::registerPluginAccessControlPolicy()`. GLPI core is not
modified.

### Acceptance criteria

1. The Access controls tab of a form shows a new policy, **"Allow specific languages"**
   (translatable label, icon, active/inactive toggle like core policies).
2. Its config form is a multi-select of the languages in `Glpi\Locale\LanguageRegistry`.
   The selection is stored as language codes (e.g. `fr_FR`).
3. Vote (core: any `Deny` blocks; otherwise at least one `Grant` is needed):
   - current language **not in** list → `Deny`, authenticated or anonymous
   - current language **in** list, other active policies on the form → `Abstain`: the
     others decide who gets in, the language only narrows it ("group X" + French = French
     users of group X; Direct access + French = token still required, logged in or not)
   - current language **in** list, policy alone, authenticated → `Grant`
   - current language **in** list, policy alone, anonymous → `Abstain` (see 5)
4. The "current language" is the session language `$_SESSION['glpilanguage']`, i.e. the
   language GLPI is displayed in.
5. The policy never opens a form to anonymous users. `canAnswer()` never returns
   `Grant` when `!$parameters->isAuthenticated()`, and `allowUnauthenticated()`
   returns `false`. It still denies anonymous users whose language is not listed. The login redirect stays core's job.
   The guard only stops a token bypass: when Direct access allows anonymous users,
   an anonymous visitor without the token would otherwise be granted access through
   their session language.
6. An active policy with an empty language list denies everyone, and `getWarnings()`
   shows a warning in the Access controls tab.
7. Every place that calls core's `canAnswerForm()` enforces the policy automatically:
   the service catalog, helpdesk tiles, form render/submit, and form documents.
8. Form export/import keeps the selected languages. Language codes are not DB ids, so
   no mapping is needed.
9. The policy is registered whenever the plugin is active. There is **no** toggle on
   the plugin config page: those toggles are for question types, and each form's
   policy already has its own active switch.
10. Disabled by default: core creates any newly registered policy on each form with
    `is_active => false` (`createMissingStrategyForForm`), so no extra code is needed.
11. When the plugin is deactivated, core ignores the saved rows
    (`Form::getAccessControls()` skips strategies that are no longer valid), so forms
    keep working. On reactivation the saved configs are used again.
12. Plugin uninstall deletes the `FormAccessControl` rows whose `strategy` is
    `LanguageAccessControl`. This follows `InstallManager::uninstall()`, which already
    removes the plugin's own data.

### Non-goals

- Restricting individual questions or sections by language
- Detecting the language from the browser's `Accept-Language` header beyond what GLPI
  already puts in the session
- Translating form content (core already handles that)
- Any GLPI core change

## Tech Stack

PHP ≥ 8.2, GLPI 11/12 Forms (`Glpi\Form\AccessControl\*`), Twig, PHPUnit, Playwright.
No new dependency.

## Commands

All commands run from the plugin directory and go through `../../PluginsMakefile.mk`
(Docker):

```
Tests (single file): make phpunit c='tests/Model/AccessControl/LanguageAccessControlTest.php'
All lints/tests:     make test
Static analysis:     make phpstan
E2E:                 make playwright c='tests/e2e/specs/<spec>'
Locales:             handled by CI (locales-update-source.yml on push to main)
License headers:     make license-headers-check
```

## Project Structure

```
src/Model/AccessControl/LanguageAccessControl.php        → NEW: ControlTypeInterface
src/Model/AccessControl/LanguageAccessControlConfig.php  → NEW: JsonFieldInterface, holds string[] languages
templates/access_control/language.html.twig              → NEW: config form (multi-select)
src/Service/InitManager.php      → add registerAccessControlPolicies(), unconditional, guarded against double registration
src/Service/InstallManager.php   → uninstall(): delete this policy's FormAccessControl rows
tests/Model/AccessControl/LanguageAccessControlTest.php  → NEW
tests/Service/InitManagerTest.php / InstallManagerTest.php → extend
tests/e2e/specs/…                → NEW: one happy path + one deny path
```

Core references to mirror: `src/Glpi/Form/AccessControl/ControlType/AllowList*.php` and
`templates/pages/admin/form/access_control/allow_list.html.twig`.

## Code Style

Follow the existing plugin: MIT license header, `final` classes, strict types, named
args, first-class callables, `__()` with domain `'advancedforms'`.

```php
final class LanguageAccessControl implements ControlTypeInterface
{
    #[Override]
    public function canAnswer(
        Form $form,
        JsonFieldInterface $config,
        FormAccessParameters $parameters,
    ): AccessVote {
        if (!$config instanceof LanguageAccessControlConfig) {
            throw new InvalidArgumentException('Invalid config class');
        }

        $language = $_SESSION['glpilanguage'] ?? null;
        if (!in_array($language, $config->getLanguages(), true)) {
            return AccessVote::Deny;
        }

        if ($this->hasOtherActivePolicies($form)) {
            return AccessVote::Abstain;
        }

        return $parameters->isAuthenticated() ? AccessVote::Grant : AccessVote::Abstain;
    }
}
```

`createConfigFromUserInput()` keeps only the codes that exist in
`Glpi\Locale\LanguageRegistry`. This is validation at the trust boundary.

## Testing Strategy

- **PHPUnit** (`tests/Model/AccessControl/`, extends `AdvancedFormsTestCase`):
  - with other active policies, the language only restricts them: allow list +
    language and direct access + language, logged in or not
  - alone, authenticated: listed language → `Grant`; unlisted language or empty list →
    `Deny`; anonymous: listed language → `Abstain`, unlisted → `Deny`; every list holds
    several languages
  - user input is cleaned: unknown codes are dropped, and a missing key gives an
    empty list
  - JSON serialize/deserialize round trip, and export/import round trip
  - the warning shows when the list is empty
  - integration, checked through `FormAccessControlManager::canAnswerForm()`, with
    Language(`en_GB`, `de_DE`, `es_ES`): with or without "All users", the user can
    answer in `en_GB` and is refused in `fr_FR`
- **InitManager test**: the policy is registered, and only once if `init()` runs again.
  A new form gets the policy with `is_active = false`.
- **InstallManager test**: uninstall removes this policy's rows and leaves core
  policies untouched.
- **Playwright**: configure the policy in the Access controls tab, then check that the
  form shows or is hidden in the service catalog after switching the user's language.

## Boundaries

- **Always:** use core's plugin policy API, `make test` green before commit, license
  headers, translatable strings, validate the selected languages server-side.
- **Ask first:** any DB schema or migration (none expected: config lives in core's
  `glpi_forms_accesscontrols_formaccesscontrols.config` JSON), any GLPI core change, new
  dependencies.
- **Never:** patch GLPI core, widen anonymous access, delete users' stored policy
  configs, remove or skip existing tests.

## Success Criteria

- Acceptance criteria 1–12 pass, through PHPUnit and the Playwright spec.
- `make test` (phpunit, phpstan, psalm, rector, cs) passes.
- Manual check: French UI plus a form whose only active policy allows English → the
  form is absent from the catalog, and its direct URL returns access denied.

## Open Questions

None.

## Resolved (2026-11-05)

- Vote: `Grant` when listed, `Deny` when not listed (decided 2026-11-05, replacing
  `Grant`/`Abstain`). The language is never ignored when combined with other policies.
- Next to other active policies, a listed language gives `Abstain`, not `Grant`
  (decided 2026-11-05): the policy works alone but never lets in users that another
  policy refuses (e.g. outside group X, or without the direct access token).
- Label: "Allow specific languages".
- Anonymous users: never `Grant` (option B), so the policy can't be used to get
  around Direct access's token; an unlisted language still gives `Deny`.
- No plugin config toggle: always registered while the plugin is active, and inactive
  by default on each form through core's own default.
