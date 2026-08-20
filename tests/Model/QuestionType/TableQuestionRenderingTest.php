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

namespace GlpiPlugin\Advancedforms\Tests\Model\QuestionType;

use Computer;
use Dropdown;
use Glpi\Application\ImportMapGenerator;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeItem;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Glpi\Form\QuestionType\QuestionTypeRequester;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Session;
use Symfony\Component\DomCrawler\Crawler;
use User;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * Covers what the end-user template hands over to the client-side validation
 * layer. The server reads the column configuration directly, so a mix-up in the
 * rendered payload is invisible to validateAnswer() and only shows up here.
 */
final class TableQuestionRenderingTest extends AdvancedFormsTestCase
{
    private const MODULE_PATH = '/plugins/advancedforms/js/modules/AfTableQuestion.js';

    private TableQuestion $type;

    public function setUp(): void
    {
        parent::setUp();
        $this->type = new TableQuestion();
        $this->login();
    }

    /**
     * Regression test for the reported bug: with a pattern on the first and last
     * of three columns, the middle column was reported as badly formatted while
     * the last column silently lost its client-side check.
     */
    public function testPatternsAreMappedToTheirOwnColumn(): void
    {
        $patterns = $this->renderedPatternColumns([
            $this->column('SRC IP', QuestionTypeShortText::class, pattern: '/^[0-9.]+$/'),
            $this->column('description', QuestionTypeShortText::class),
            $this->column('DST IP', QuestionTypeShortText::class, pattern: '/^[a-f:]+$/'),
        ]);

        $this->assertSame(
            ['col_0' => '/^[0-9.]+$/', 'col_2' => '/^[a-f:]+$/'],
            $patterns,
        );
    }

    public function testColumnWithoutPatternIsAbsentFromThePayload(): void
    {
        $patterns = $this->renderedPatternColumns([
            $this->column('SRC IP', QuestionTypeShortText::class, pattern: '/^[0-9.]+$/'),
            $this->column('description', QuestionTypeShortText::class),
            $this->column('DST IP', QuestionTypeShortText::class, pattern: '/^[0-9.]+$/'),
        ]);

        $this->assertArrayNotHasKey('col_1', $patterns);
    }

    public function testTableWithoutAnyPatternExposesAnEmptyPayload(): void
    {
        $patterns = $this->renderedPatternColumns([
            $this->column('Name', QuestionTypeShortText::class),
            $this->column('Comment', QuestionTypeShortText::class),
        ]);

        $this->assertSame([], $patterns);
    }

    public function testEveryColumnCanCarryItsOwnPattern(): void
    {
        $patterns = $this->renderedPatternColumns([
            $this->column('A', QuestionTypeShortText::class, pattern: '/^a$/'),
            $this->column('B', QuestionTypeShortText::class, pattern: '/^b$/'),
            $this->column('C', QuestionTypeShortText::class, pattern: '/^c$/'),
        ]);

        $this->assertSame(
            ['col_0' => '/^a$/', 'col_1' => '/^b$/', 'col_2' => '/^c$/'],
            $patterns,
        );
    }

    public function testOnlyTheLastColumnCarriesAPattern(): void
    {
        $patterns = $this->renderedPatternColumns([
            $this->column('A', QuestionTypeShortText::class),
            $this->column('B', QuestionTypeShortText::class),
            $this->column('C', QuestionTypeShortText::class, pattern: '/^c$/'),
        ]);

        $this->assertSame(['col_2' => '/^c$/'], $patterns);
    }

    public function testRequiredColumnsAreMappedToTheirOwnColumn(): void
    {
        $required = $this->renderedRequiredColumns([
            $this->column('A', QuestionTypeShortText::class, required: true),
            $this->column('B', QuestionTypeShortText::class),
            $this->column('C', QuestionTypeShortText::class, required: true),
        ]);

        $this->assertSame(['col_0', 'col_2'], $required);
    }

    public function testTableWithoutAnyRequiredColumnExposesAnEmptyList(): void
    {
        $required = $this->renderedRequiredColumns([
            $this->column('A', QuestionTypeShortText::class),
            $this->column('B', QuestionTypeShortText::class),
        ]);

        $this->assertSame([], $required);
    }

    /**
     * The HTML `pattern` attribute is implicitly anchored and drops flags, so it
     * disagrees with both the PHP and the JS check. It must not be emitted.
     */
    public function testNoHtmlPatternAttributeIsEmitted(): void
    {
        $html = $this->render([
            $this->column('Prefix', QuestionTypeShortText::class, pattern: '/^172\.23\./'),
        ]);

        $crawler = new Crawler($html);
        $inputs  = $crawler->filter('[data-af-table-body] input');

        $this->assertGreaterThan(0, $inputs->count());
        foreach ($inputs as $input) {
            $this->assertFalse(
                $input->hasAttribute('pattern'),
                'The end-user table must not rely on the anchored HTML pattern attribute.',
            );
        }
    }

    public function testRowTemplateAlsoOmitsTheHtmlPatternAttribute(): void
    {
        $html = $this->render([
            $this->column('Prefix', QuestionTypeShortText::class, pattern: '/^172\.23\./'),
        ]);

        // The cloned-row template is not part of the DOM tree, inspect the markup.
        $this->assertStringNotContainsString('pattern="', $html);
    }

    public function testCheckboxColumnPatternIsNotExposedToTheClient(): void
    {
        // The config UI only offers a pattern on text columns; a hand-crafted one
        // on a checkbox column has no input to validate and must be dropped.
        $patterns = $this->renderedPatternColumns([
            $this->column('Flag', QuestionTypeCheckbox::class, pattern: '/^yes$/'),
        ]);

        $this->assertSame([], $patterns);
    }

    public function testEmailColumnKeepsItsNativeInputType(): void
    {
        $html    = $this->render([$this->column('Contact', QuestionTypeEmail::class)]);
        $crawler = new Crawler($html);

        $this->assertSame(
            'email',
            $crawler->filter('[data-af-table-body] input')->first()->attr('type'),
        );
    }

    public function testASingleRowIsRenderedByDefault(): void
    {
        $html    = $this->render([$this->column('Name', QuestionTypeShortText::class)]);
        $crawler = new Crawler($html);

        $this->assertSame(1, $crawler->filter('[data-af-table-body] [data-af-table-row]')->count());
    }

    /**
     * Row bounds are now declared as native validation conditions, so the
     * renderer must not ship any min/max row hint to the client.
     */
    public function testNoRowBoundsAreExposedToTheClient(): void
    {
        $html    = $this->render([$this->column('Name', QuestionTypeShortText::class)]);
        $crawler = new Crawler($html);
        $table   = $crawler->filter('[data-af-table-question]')->first();

        $this->assertNull($table->attr('data-af-min-rows'));
        $this->assertNull($table->attr('data-af-max-rows'));
    }

    /**
     * Server-side errors are reported per question but injected by the core
     * renderer next to every input, so a table needs somewhere to gather them.
     */
    public function testASingleContainerIsProvidedForServerErrors(): void
    {
        $html    = $this->render([$this->column('Name', QuestionTypeShortText::class)]);
        $crawler = new Crawler($html);

        $this->assertSame(1, $crawler->filter('[data-af-table-errors]')->count());
    }

    /**
     * The module must be imported through its import map key. A `?v=` of our own
     * would not match that key, and would cost both the content-based cache
     * busting and the root_doc prefix.
     */
    public function testTheJsModuleIsImportedThroughTheImportMap(): void
    {
        $html = $this->render([$this->column('Name', QuestionTypeShortText::class)]);

        $this->assertStringContainsString(
            "from '" . self::MODULE_PATH . "'",
            $html,
        );
    }

    public function testTheImportMapVersionsTheModuleOnItsContent(): void
    {
        $imports = ImportMapGenerator::getInstance()->generate()['imports'];

        $this->assertArrayHasKey(
            self::MODULE_PATH,
            $imports,
            'setup.php must register the plugin modules directory.',
        );
        $this->assertMatchesRegularExpression(
            '#' . preg_quote(self::MODULE_PATH, '#') . '\?v=[0-9a-f]+$#',
            $imports[self::MODULE_PATH],
        );
    }

    public function testEachColumnGetsItsOwnCustomDropdownItemtypeInTheAjaxConfig(): void
    {
        $test1_definition = $this->initDropdownDefinition('Test1');
        $test2_definition = $this->initDropdownDefinition('Test2');

        $test1_class = $test1_definition->getDropdownClassName();
        $test2_class = $test2_definition->getDropdownClassName();

        Dropdown::resetItemtypesStaticCache();

        $html    = $this->render([
            $this->column('Col1', QuestionTypeItemDropdown::class, itemtype: $test1_class),
            $this->column('Col2', QuestionTypeItemDropdown::class, itemtype: $test2_class),
        ]);
        $configs = $this->renderedAjaxConfigs($html);

        $this->assertCount(2, $configs);
        $this->assertSame($test1_class, $configs[0]['params']['itemtype']);
        $this->assertSame($test2_class, $configs[1]['params']['itemtype']);
        $this->assertNotSame(
            $configs[0]['params']['_idor_token'],
            $configs[1]['params']['_idor_token'],
            "Each column must get its own IDOR token, or one column could query the other's scope.",
        );
    }

    public function testGlpiObjectColumnIsBackedByTheAjaxDropdownEndpoint(): void
    {
        $html    = $this->render([$this->column('Asset', QuestionTypeItem::class, itemtype: Computer::class)]);
        $configs = $this->renderedAjaxConfigs($html);

        $this->assertCount(1, $configs);
        $this->assertSame(Computer::class, $configs[0]['params']['itemtype']);
        $this->assertNotEmpty($configs[0]['params']['_idor_token']);
        $this->assertStringEndsWith('/ajax/getDropdownValue.php', $configs[0]['url']);
    }

    public function testGlpiObjectColumnHasNoPreFetchedOptions(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->createItem(Computer::class, ['name' => 'Computer ' . $i, 'entities_id' => Session::getActiveEntity()]);
        }

        $html    = $this->render([$this->column('Asset', QuestionTypeItem::class, itemtype: Computer::class)]);
        $crawler = new Crawler($html);
        $select  = $crawler->filter('[data-af-table-body] [data-af-table-row] select[data-af-needs-ajax-s2]');

        $this->assertSame(1, $select->count());

        $options = $select->filter('option');
        $this->assertLessThanOrEqual(1, $options->count());
        if ($options->count() === 1) {
            $this->assertSame('', $options->attr('value'));
            $this->assertNotNull($options->attr('disabled'));
        }
    }

    public function testActorColumnIsBackedByTheAjaxDropdownEndpointRestrictedToActiveUsers(): void
    {
        $html    = $this->render([$this->column('Owner', QuestionTypeRequester::class)]);
        $configs = $this->renderedAjaxConfigs($html);

        $this->assertCount(1, $configs);
        $this->assertSame(User::class, $configs[0]['params']['itemtype']);

        $condition_key = $configs[0]['params']['condition'];
        $this->assertNotSame('', $condition_key);
        $this->assertSame(
            ['is_active' => 1, 'is_deleted' => 0],
            $_SESSION['glpicondition'][$condition_key] ?? null,
        );
    }

    public function testAjaxColumnConfigIsAlsoPresentInTheRowCloneTemplate(): void
    {
        $html = $this->render([$this->column('Asset', QuestionTypeItem::class, itemtype: Computer::class)]);

        $this->assertSame(2, substr_count($html, 'data-af-needs-ajax-s2'));
    }

    /**
     * @return list<array<string, mixed>> Decoded `data-af-s2-config` payload
     *         for each ajax-backed select in the visible row, in column order.
     */
    private function renderedAjaxConfigs(string $html): array
    {
        $crawler = new Crawler($html);
        $selects = $crawler->filter('[data-af-table-body] [data-af-table-row] select[data-af-needs-ajax-s2]');

        return $selects->each(function (Crawler $n): array {
            $decoded = json_decode((string) $n->attr('data-af-s2-config'), associative: true);
            $this->assertIsArray($decoded, 'data-af-s2-config must hold a JSON object.');

            return $decoded;
        });
    }

    /**
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}> $columns
     * @return array<string, string> Decoded `data-af-pattern-cols` payload.
     */
    private function renderedPatternColumns(array $columns): array
    {
        $crawler = new Crawler($this->render($columns));
        $raw     = $crawler->filter('[data-af-table-question]')->first()->attr('data-af-pattern-cols');

        $decoded = json_decode((string) $raw, associative: true);
        $this->assertIsArray($decoded, 'data-af-pattern-cols must hold a JSON object.');

        return $decoded;
    }

    /**
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}> $columns
     * @return list<string> Parsed `data-af-required-cols` payload.
     */
    private function renderedRequiredColumns(array $columns): array
    {
        $crawler = new Crawler($this->render($columns));
        $raw     = $crawler->filter('[data-af-table-question]')->first()->attr('data-af-required-cols');

        return array_values(array_filter(explode(',', (string) $raw), fn(string $v): bool => $v !== ''));
    }

    /**
     * @param array<array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}> $columns
     */
    private function render(array $columns): string
    {
        $this->enableConfigurableItem($this->type);

        $builder = new FormBuilder('Rendering form');
        $builder->addQuestion(
            'Table',
            TableQuestion::class,
            extra_data: json_encode(new TableQuestionConfig(columns: $columns)),
        );
        $form = $this->createForm($builder);

        $question = Question::getById($this->getQuestionId($form, 'Table'));

        return $this->type->renderEndUserTemplate($question);
    }

    /**
     * @return array{name: string, question_type: string, required: bool, itemtype: string, pattern: string}
     */
    private function column(
        string $name,
        string $fqcn,
        bool $required = false,
        string $pattern = '',
        string $itemtype = '',
    ): array {
        return [
            TableQuestionConfig::COL_NAME          => $name,
            TableQuestionConfig::COL_QUESTION_TYPE => $fqcn,
            TableQuestionConfig::COL_REQUIRED      => $required,
            TableQuestionConfig::COL_ITEMTYPE      => $itemtype,
            TableQuestionConfig::COL_PATTERN       => $pattern,
        ];
    }
}
