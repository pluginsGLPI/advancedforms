# Todo: Language access control policy (#46030)

See [`plan.md`](plan.md) and [`../SPEC.md`](../SPEC.md).

## Task 1: Policy config and strategy classes

**Description:** Create `LanguageAccessControlConfig` (holds `string[] $languages`, JSON
round trip) and `LanguageAccessControl` implementing `ControlTypeInterface`: label
"Allow specific languages", icon, weight, the vote, input validation, warnings for an
empty list, export/import, and `allowUnauthenticated() = false`.
`renderConfigForm()` may return an empty string until Task 2.

**Acceptance criteria:**
- [x] Authenticated user: listed session language → `Grant`; not listed or empty
      list → `Deny`. Anonymous: listed → `Abstain`, not listed → `Deny`
- [x] `createConfigFromUserInput()` drops unknown codes and duplicates; a missing key
      gives an empty list
- [x] Config JSON round trip and export → import round trip keep the languages;
      `getWarnings()` is non-empty only when the list is empty

**Verification:**
- [x] `make phpunit c='tests/Model/AccessControl/LanguageAccessControlTest.php'`
- [x] `make phpstan`

**Dependencies:** None

**Files:**
- `src/Model/AccessControl/LanguageAccessControlConfig.php` (new)
- `src/Model/AccessControl/LanguageAccessControl.php` (new)
- `tests/Model/AccessControl/LanguageAccessControlTest.php` (new)

**Scope:** S

---

## Task 2: Config form and registration

**Description:** Add the multi-select Twig template (input naming copied from core's
`allow_list.html.twig`), implement `renderConfigForm()`, and register the policy in
`InitManager::init()` through a new `registerAccessControlPolicies()` that is guarded
against double registration.

**Acceptance criteria:**
- [x] The policy is registered once, even if `init()` runs twice; a new form gets it
      with `is_active = false`
- [x] `canAnswerForm()` with Language(`en_GB`, `de_DE`, `es_ES`), with or without
      "All users": true in `en_GB`, false in `fr_FR`
- [x] Token bypass guard: Direct access (anonymous allowed) + Language(`fr_FR`, `en_GB`,
      `de_DE`), anonymous: `fr_FR` with no token → false; `fr_FR` with the token →
      true; `es_ES` with the token → false
- [x] The config form renders and the saved languages are preselected

**Verification:**
- [x] PHPUnit: registration/default-inactive tests live in `LanguageAccessControlTest` (not `InitManagerTest`)
- [ ] Manual (Checkpoint A): enable the policy on a form, select English, switch the
      user to French → the form is gone from the service catalog and its direct URL
      is refused

**Dependencies:** Task 1

**Files:**
- `templates/access_control/language.html.twig` (new)
- `src/Model/AccessControl/LanguageAccessControl.php`
- `src/Service/InitManager.php`
- `tests/AdvancedFormsTestCase.php` (reset `FormAccessControlManager` singleton per test)
- `tests/Model/AccessControl/LanguageAccessControlTest.php`

**Scope:** M

---

## Checkpoint A
- [x] All PHPUnit tests green (except 8 LDAP tests needing an LDAP server), `make phpstan` clean
- [ ] Manual UI check above done
- [ ] Review with human before continuing

---

## Task 3: Uninstall cleanup

**Description:** `InstallManager::uninstall()` calls the new public
`uninstallAccessControlPolicies()`, tested directly (calling `uninstall()` in a test
would drop a table, which commits the test transaction). It deletes the `FormAccessControl` rows whose
`strategy` is `LanguageAccessControl::class`.

**Acceptance criteria:**
- [x] After uninstall, no rows for this strategy remain
- [x] Core policy rows (AllowList, DirectAccess) are left alone

**Verification:**
- [x] `make phpunit c='tests/Service/InstallManagerTest.php'`

**Dependencies:** Task 1

**Files:**
- `src/Service/InstallManager.php`
- `tests/Service/InstallManagerTest.php`

**Scope:** XS

---

## Task 4: Playwright spec

**Description:** E2E test that configures "Allow specific languages" in the Access
controls tab, then checks catalog visibility for a listed and a non-listed user
language.

**Acceptance criteria:**
- [x] Policy toggled on and a language saved through the UI
- [x] Listed language → form visible in the service catalog; non-listed → hidden

**Verification:**
- [x] `make playwright c='language_access_control'` (2 passed)

**Dependencies:** Task 2

**Files:**
- `tests/e2e/specs/language_access_control.spec.ts` (new)
- `tests/e2e/pages/…` (only if a page object is needed)

**Scope:** S

---

## Task 5: Changelog and full check

**Description:** Add a CHANGELOG entry and run the full quality gate. Locale extraction
is not needed: the `locales-update-source.yml` workflow regenerates
`locales/advancedforms.pot` on every push to `main`, and `locales-sync.yml` pulls
translations back.

**Acceptance criteria:**
- [x] CHANGELOG "Unreleased" entry for #46030
- [x] `make test` and `make license-headers-check` green (except 8 LDAP tests needing an LDAP server)

**Verification:**
- [x] `make test && make license-headers-check`

**Dependencies:** Tasks 1–4

**Files:**
- `CHANGELOG.md`

**Scope:** XS

---

## Checkpoint: Complete
- [x] SPEC acceptance criteria 1–12 met
- [x] `make test` green (except 8 LDAP tests needing an LDAP server)
- [ ] Human review before commit/PR
