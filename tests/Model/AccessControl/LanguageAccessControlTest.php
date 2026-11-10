<?php

/**
 * -------------------------------------------------------------------------
 * advancedforms plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 by the advancedforms plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/advancedforms
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Advancedforms\Tests\Model\AccessControl;

use AbstractRightsDropdown;
use Glpi\Form\AccessControl\AccessVote;
use Glpi\Form\AccessControl\ControlType\AllowList;
use Glpi\Form\AccessControl\ControlType\AllowListConfig;
use Glpi\Form\AccessControl\ControlType\DirectAccess;
use Glpi\Form\AccessControl\ControlType\DirectAccessConfig;
use Glpi\Form\AccessControl\FormAccessControl;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\Export\Serializer\FormSerializer;
use Glpi\Form\Form;
use Glpi\Tests\FormBuilder;
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\AccessControl\LanguageAccessControl;
use GlpiPlugin\Advancedforms\Model\AccessControl\LanguageAccessControlConfig;
use GlpiPlugin\Advancedforms\Service\InitManager;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Session;
use Symfony\Component\DomCrawler\Crawler;
use User;

final class LanguageAccessControlTest extends AdvancedFormsTestCase
{
    use FormTesterTrait;

    private static FormSerializer $serializer;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$serializer = new FormSerializer();
    }

    /** @return iterable<string, array{string[], string, bool, AccessVote}> */
    public static function provideVotes(): iterable
    {
        yield 'listed language'              => [['en_GB', 'fr_FR', 'de_DE'], 'fr_FR', true, AccessVote::Grant];
        yield 'unlisted language'            => [['en_GB', 'de_DE', 'es_ES'], 'fr_FR', true, AccessVote::Deny];
        yield 'empty list'                   => [[], 'fr_FR', true, AccessVote::Deny];
        yield 'anonymous, listed language'   => [['en_GB', 'fr_FR', 'de_DE'], 'fr_FR', false, AccessVote::Abstain];
        yield 'anonymous, unlisted language' => [['en_GB', 'de_DE', 'es_ES'], 'fr_FR', false, AccessVote::Deny];
    }

    /** @param string[] $languages */
    #[DataProvider('provideVotes')]
    public function testCanAnswer(
        array $languages,
        string $session_language,
        bool $authenticated,
        AccessVote $expected,
    ): void {
        $this->login();
        $_SESSION['glpilanguage'] = $session_language;
        $parameters = $authenticated
            ? new FormAccessParameters(session_info: Session::getCurrentSessionInfo())
            : new FormAccessParameters();

        $vote = (new LanguageAccessControl())->canAnswer(
            new Form(),
            new LanguageAccessControlConfig($languages),
            $parameters,
        );

        $this->assertSame($expected, $vote);
    }

    public function testNeverAllowsUnauthenticated(): void
    {
        $this->assertFalse((new LanguageAccessControl())->allowUnauthenticated(
            new LanguageAccessControlConfig(['fr_FR', 'en_GB', 'de_DE']),
        ));
    }

    /** @return iterable<string, array{array<string, mixed>, string[]}> */
    public static function provideUserInput(): iterable
    {
        $key = LanguageAccessControl::INPUT_NAME;
        yield 'valid codes'          => [[$key => ['fr_FR', 'en_GB', 'de_DE']], ['fr_FR', 'en_GB', 'de_DE']];
        yield 'unknown code dropped' => [[$key => ['fr_FR', 'xx_XX', 'en_GB', '<b>']], ['fr_FR', 'en_GB']];
        yield 'duplicates removed'   => [[$key => ['fr_FR', 'en_GB', 'fr_FR']], ['fr_FR', 'en_GB']];
        yield 'non string dropped'   => [[$key => ['fr_FR', ['en_GB'], 12, 'de_DE']], ['fr_FR', 'de_DE']];
        yield 'empty select'        => [[$key => ''], []];
        yield 'missing key'         => [[], []];
    }

    /**
     * @param array<string, mixed> $input
     * @param string[] $expected
     */
    #[DataProvider('provideUserInput')]
    public function testCreateConfigFromUserInput(array $input, array $expected): void
    {
        $config = (new LanguageAccessControl())->createConfigFromUserInput($input);
        $this->assertSame($expected, $config->getLanguages());
    }

    public function testConfigJsonRoundTrip(): void
    {
        $config = new LanguageAccessControlConfig(['fr_FR', 'en_GB']);
        $copy = LanguageAccessControlConfig::jsonDeserialize($config->jsonSerialize());
        $this->assertSame(['fr_FR', 'en_GB'], $copy->getLanguages());
        $this->assertSame([], LanguageAccessControlConfig::jsonDeserialize([])->getLanguages());
    }

    public function testExportImportKeepsLanguages(): void
    {
        $this->login();
        $form = $this->createForm(
            (new FormBuilder())->addAccessControl(
                LanguageAccessControl::class,
                new LanguageAccessControlConfig(['fr_FR', 'en_GB']),
            ),
        );

        $copy = $this->exportAndImportForm($form);

        $this->assertSame(['fr_FR', 'en_GB'], $this->getAccessControl($copy, LanguageAccessControl::class)->getConfig()->getLanguages());
    }

    /** @return iterable<string, array{string[], bool, bool}> */
    public static function provideWarnings(): iterable
    {
        yield 'active, empty list'    => [[], true, true];
        yield 'active, with language' => [['fr_FR', 'en_GB'], true, false];
        yield 'inactive, empty list'  => [[], false, false];
        yield 'inactive, with language' => [['fr_FR', 'en_GB'], false, false];
    }

    /** @param string[] $languages */
    #[DataProvider('provideWarnings')]
    public function testWarnings(array $languages, bool $is_active, bool $has_warning): void
    {
        $this->login();
        $form = $this->createForm(
            (new FormBuilder())->addAccessControl(
                LanguageAccessControl::class,
                new LanguageAccessControlConfig($languages),
                $is_active,
            ),
        );

        // Go through core: this is the list displayed in the Access controls tab,
        // so it also fails if the policy is no longer registered.
        InitManager::getInstance()->init();
        $warnings = FormAccessControlManager::getInstance()->getWarnings($form);

        $expected = __('No language is selected in the "Allow specific languages" policy: it denies access to everyone.', 'advancedforms');
        $this->assertSame($has_warning, in_array($expected, $warnings, true));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function provideCanAnswerForm(): iterable
    {
        yield 'listed language, language policy only'     => ['en_GB', false, true];
        yield 'unlisted language, language policy only'   => ['fr_FR', false, false];
        yield 'listed language, "All users" + language'   => ['en_GB', true, true];
        yield 'unlisted language, "All users" + language' => ['fr_FR', true, false];
    }

    #[DataProvider('provideCanAnswerForm')]
    public function testCanAnswerFormThroughCore(
        string $session_language,
        bool $with_all_users,
        bool $expected,
    ): void {
        $this->login();
        $_SESSION['glpilanguage'] = $session_language;
        $builder = (new FormBuilder())
            ->setUseDefaultAccessPolicies(false)
            ->addAccessControl(
                LanguageAccessControl::class,
                new LanguageAccessControlConfig(['en_GB', 'de_DE', 'es_ES']),
            );
        if ($with_all_users) {
            $builder->addAccessControl(AllowList::class, new AllowListConfig(
                user_ids: [AbstractRightsDropdown::ALL_USERS],
            ));
        }

        $form = $this->createForm($builder);

        $can_answer = FormAccessControlManager::getInstance()->canAnswerForm(
            $form,
            new FormAccessParameters(session_info: Session::getCurrentSessionInfo()),
        );

        $this->assertSame($expected, $can_answer);
    }

    /** @return iterable<string, array{bool, string, bool}> */
    public static function provideAllowListAndLanguage(): iterable
    {
        yield 'allowed user, listed language'     => [true, 'fr_FR', true];
        yield 'allowed user, unlisted language'   => [true, 'es_ES', false];
        yield 'other user, listed language'       => [false, 'fr_FR', false];
        yield 'other user, unlisted language'     => [false, 'es_ES', false];
    }

    /** Next to another policy, the language only restricts it: it never lets in users the other policy refuses. */
    #[DataProvider('provideAllowListAndLanguage')]
    public function testAllowListAndLanguage(
        bool $in_allow_list,
        string $session_language,
        bool $expected,
    ): void {
        $this->login();
        $current_user_id = Session::getLoginUserID();
        $other_user_id = getItemByTypeName(User::class, 'tech', true);
        $form = $this->createForm(
            (new FormBuilder())
                ->setUseDefaultAccessPolicies(false)
                ->addAccessControl(AllowList::class, new AllowListConfig(
                    user_ids: [$in_allow_list ? $current_user_id : $other_user_id],
                ))
                ->addAccessControl(
                    LanguageAccessControl::class,
                    new LanguageAccessControlConfig(['fr_FR', 'en_GB', 'de_DE']),
                ),
        );
        $_SESSION['glpilanguage'] = $session_language;

        $can_answer = FormAccessControlManager::getInstance()->canAnswerForm(
            $form,
            new FormAccessParameters(session_info: Session::getCurrentSessionInfo()),
        );

        $this->assertSame($expected, $can_answer);
    }

    /** @return iterable<string, array{bool, array<string, string>, string, bool}> */
    public static function provideDirectAccess(): iterable
    {
        // Same rules through the direct link, logged in or not.
        foreach (['authenticated' => true, 'anonymous' => false] as $who => $authenticated) {
            yield "$who, listed language, no token"     => [$authenticated, [], 'fr_FR', false];
            yield "$who, listed language, with token"   => [$authenticated, ['token' => 'my_token'], 'fr_FR', true];
            yield "$who, unlisted language, with token" => [$authenticated, ['token' => 'my_token'], 'es_ES', false];
        }
    }

    /** @param array<string, string> $url_parameters */
    #[DataProvider('provideDirectAccess')]
    public function testDirectAccess(
        bool $authenticated,
        array $url_parameters,
        string $session_language,
        bool $expected,
    ): void {
        $this->login();
        $form = $this->createForm(
            (new FormBuilder())
                ->setUseDefaultAccessPolicies(false)
                ->addAccessControl(DirectAccess::class, new DirectAccessConfig(
                    token: 'my_token',
                    allow_unauthenticated: true,
                ))
                ->addAccessControl(
                    LanguageAccessControl::class,
                    new LanguageAccessControlConfig(['fr_FR', 'en_GB', 'de_DE']),
                ),
        );
        if (!$authenticated) {
            $this->logOut();
        }

        $_SESSION['glpilanguage'] = $session_language;

        $can_answer = FormAccessControlManager::getInstance()->canAnswerForm(
            $form,
            new FormAccessParameters(
                session_info: $authenticated ? Session::getCurrentSessionInfo() : null,
                url_parameters: $url_parameters,
            ),
        );

        $this->assertSame($expected, $can_answer);
    }

    public function testRenderConfigFormPreselectsSavedLanguages(): void
    {
        $this->login();
        $form = $this->createForm(
            (new FormBuilder())->addAccessControl(
                LanguageAccessControl::class,
                new LanguageAccessControlConfig(['fr_FR', 'de_DE']),
            ),
        );
        $control = $this->getAccessControl($form, LanguageAccessControl::class);

        $html = new Crawler((new LanguageAccessControl())->renderConfigForm($control));

        $name = $control->getNormalizedInputName(LanguageAccessControl::INPUT_NAME) . '[]';
        $select = $html->filter(sprintf('select[name="%s"][multiple]', $name));
        $this->assertCount(1, $select);
        $this->assertEqualsCanonicalizing(['fr_FR', 'de_DE'], $select->filter('option[selected]')->each(
            fn(Crawler $option): string => (string) $option->attr('value'),
        ));
    }

    public function testSaveThroughCoreInputPath(): void
    {
        $this->login();
        $form = $this->createForm(
            (new FormBuilder())->addAccessControl(
                LanguageAccessControl::class,
                new LanguageAccessControlConfig([]),
            ),
        );
        $control = $this->getAccessControl($form, LanguageAccessControl::class);

        // Same steps as front/form/access_control.form.php
        $input = ['id' => $control->getID(), LanguageAccessControl::INPUT_NAME => ['en_GB', 'xx_XX', 'de_DE']];
        $input['_config'] = $control->createConfigFromUserInput($input);
        $this->assertTrue($control->update($input));

        $control->getFromDB($control->getID());
        $this->assertSame(['en_GB', 'de_DE'], $control->getConfig()->getLanguages());
    }

    public function testPolicyIsRegisteredOnceAndInactiveByDefault(): void
    {
        $this->login();
        InitManager::getInstance()->init();
        InitManager::getInstance()->init();
        $form = $this->createForm(new FormBuilder());

        FormAccessControlManager::getInstance()->createMissingAccessControlsForForm($form);

        $rows = (new FormAccessControl())->find([
            Form::getForeignKeyField() => $form->getID(),
            'strategy'                 => LanguageAccessControl::class,
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) current($rows)['is_active']);
    }
}
