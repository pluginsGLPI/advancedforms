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

use Dropdown;
use Glpi\Application\ImportMapGenerator;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeEmail;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestionConfig;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Session;
use Symfony\Component\DomCrawler\Crawler;

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

    /**
     * Regression test: custom dropdown definitions all share the same database
     * table (distinguished only by a foreign key to their definition), so a
     * column's option list must be scoped to its own definition. Without that
     * scoping, every "Item (custom dropdown)" column ends up offering entries
     * from every custom dropdown definition instead of just its own.
     */
    public function testEachColumnOnlyShowsItsOwnCustomDropdownEntries(): void
    {
        $test1_definition = $this->initDropdownDefinition('Test1');
        $test2_definition = $this->initDropdownDefinition('Test2');

        $test1_class = $test1_definition->getDropdownClassName();
        $test2_class = $test2_definition->getDropdownClassName();

        Dropdown::resetItemtypesStaticCache();

        $entity_id = Session::getActiveEntity();

        $this->createItem($test1_class, [
            'name'        => 'Item from Test1',
            'entities_id' => $entity_id,
        ]);
        $this->createItem($test2_class, [
            'name'        => 'Item from Test2',
            'entities_id' => $entity_id,
        ]);

        $html = $this->render([
            $this->column('Col1', QuestionTypeItemDropdown::class, itemtype: $test1_class),
            $this->column('Col2', QuestionTypeItemDropdown::class, itemtype: $test2_class),
        ]);

        $crawler = new Crawler($html);
        $selects = $crawler->filter('[data-af-table-body] [data-af-table-row] select');
        $this->assertSame(2, $selects->count());

        $col1_options = $selects->eq(0)->filter('option')->each(fn(Crawler $n): string => $n->text());
        $col2_options = $selects->eq(1)->filter('option')->each(fn(Crawler $n): string => $n->text());

        $this->assertContains('Item from Test1', $col1_options);
        $this->assertNotContains('Item from Test2', $col1_options);

        $this->assertContains('Item from Test2', $col2_options);
        $this->assertNotContains('Item from Test1', $col2_options);
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
