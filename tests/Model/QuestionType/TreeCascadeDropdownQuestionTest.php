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

use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Form\QuestionType\QuestionTypeItemDropdownExtraDataConfig;
use Glpi\Tests\FormBuilder;
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\TreeCascadeDropdownQuestion;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Location;
use Override;
use Symfony\Component\DomCrawler\Crawler;

final class TreeCascadeDropdownQuestionTest extends QuestionTypeTestCase
{
    use FormTesterTrait;

    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new TreeCascadeDropdownQuestion();
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $dropdown = $html->filter('select[name*="itemtype"]');
        $this->assertNotEmpty($dropdown);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $select = $html->filter('.af-tree-cascade-select');
        $this->assertNotEmpty($select);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(
        Crawler $html,
    ): void {
        $select = $html->filter('.af-tree-cascade-select');
        $this->assertEmpty($select);
    }

    public function testGetName(): void
    {
        $question_type = new TreeCascadeDropdownQuestion();
        $this->assertNotEmpty($question_type->getName());
    }

    public function testGetIcon(): void
    {
        $question_type = new TreeCascadeDropdownQuestion();
        $this->assertEquals('ti ti-sitemap', $question_type->getIcon());
    }

    public function testGetWeight(): void
    {
        $question_type = new TreeCascadeDropdownQuestion();
        $this->assertEquals(30, $question_type->getWeight());
    }

    public function testAllowedItemtypes(): void
    {
        $question_type = new TreeCascadeDropdownQuestion();
        $allowed = $question_type->getAllowedItemtypes();

        $this->assertArrayHasKey('Ticket', $allowed);
        $this->assertContains(Location::class, $allowed['Ticket']);
        $this->assertContains(\ITILCategory::class, $allowed['Ticket']);
    }

    public function testGetConfigKey(): void
    {
        $this->assertEquals(
            'enable_tree_cascade_dropdown',
            TreeCascadeDropdownQuestion::getConfigKey(),
        );
    }

    public function testHelpdeskRenderingWithLocationHierarchy(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $root = $this->createItem(Location::class, [
            'name'         => 'Root Location',
            'locations_id' => 0,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child Location',
            'locations_id' => $root->getID(),
        ]);

        $grandchild = $this->createItem(Location::class, [
            'name'         => 'Grandchild Location',
            'locations_id' => $child->getID(),
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $builder = new FormBuilder("Tree Cascade Form");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            '',
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $selects = $html->filter('.af-tree-cascade-select');
        $this->assertGreaterThanOrEqual(1, $selects->count());

        $first_select = $selects->eq(0);
        $options = $first_select->filter('option')->each(
            fn(Crawler $node) => $node->text(),
        );
        $this->assertContains('Root Location', $options);
        $this->assertNotContains('Child Location', $options);
        $this->assertNotContains('Grandchild Location', $options);
    }

    public function testHelpdeskRenderingWithDefaultValue(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $root = $this->createItem(Location::class, [
            'name'         => 'Root A',
            'locations_id' => 0,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child A1',
            'locations_id' => $root->getID(),
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $default_value = json_encode([
            'itemtype' => Location::class,
            'items_id' => $child->getID(),
        ]);

        $builder = new FormBuilder("Tree Cascade Preselected");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            $default_value,
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $hidden_input = $html->filter('input[type="hidden"][value="' . $child->getID() . '"]');
        $this->assertNotEmpty($hidden_input);

        $selects = $html->filter('.af-tree-cascade-select');
        $this->assertGreaterThanOrEqual(2, $selects->count());

        $first_options = $selects->eq(0)->filter('option')->each(
            fn(Crawler $node) => $node->text(),
        );
        $this->assertContains('Root A', $first_options);

        $second_select = $selects->eq(1);
        $selected_option = $second_select->filter('option[selected]');
        $this->assertNotEmpty($selected_option);
        $this->assertEquals('Child A1', $selected_option->text());
    }

    public function testHelpdeskRenderingWithSubtreeRoot(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $root = $this->createItem(Location::class, [
            'name'         => 'Global Root',
            'locations_id' => 0,
        ]);

        $subtree_root = $this->createItem(Location::class, [
            'name'         => 'Subtree Root',
            'locations_id' => $root->getID(),
        ]);

        $subtree_child = $this->createItem(Location::class, [
            'name'         => 'Subtree Child',
            'locations_id' => $subtree_root->getID(),
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
            root_items_id: $subtree_root->getID(),
        ));

        $builder = new FormBuilder("Tree Cascade Subtree");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            '',
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $selects = $html->filter('.af-tree-cascade-select');
        $this->assertGreaterThanOrEqual(1, $selects->count());

        $first_options = $selects->eq(0)->filter('option')->each(
            fn(Crawler $node) => $node->text(),
        );
        $this->assertContains('Subtree Child', $first_options);
        $this->assertNotContains('Global Root', $first_options);
        $this->assertNotContains('Subtree Root', $first_options);
    }

    public function testHelpdeskRenderingWithSelectableTreeRoot(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $subtree_root = $this->createItem(Location::class, [
            'name'         => 'Selectable Root',
            'locations_id' => 0,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Root Child',
            'locations_id' => $subtree_root->getID(),
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
            root_items_id: $subtree_root->getID(),
            selectable_tree_root: true,
        ));

        $builder = new FormBuilder("Tree Cascade Selectable Root");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            '',
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $selects = $html->filter('.af-tree-cascade-select');
        $this->assertGreaterThanOrEqual(1, $selects->count());

        $first_options = $selects->eq(0)->filter('option')->each(
            fn(Crawler $node) => $node->text(),
        );
        $this->assertContains('Selectable Root', $first_options);
        $this->assertNotContains('Root Child', $first_options);
    }

    public function testFirstDropdownShowsOnlyDirectChildren(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $root_a = $this->createItem(Location::class, [
            'name'         => 'Location A',
            'locations_id' => 0,
        ]);

        $root_b = $this->createItem(Location::class, [
            'name'         => 'Location B',
            'locations_id' => 0,
        ]);

        $child_a1 = $this->createItem(Location::class, [
            'name'         => 'Location A1',
            'locations_id' => $root_a->getID(),
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $builder = new FormBuilder("Tree Cascade Direct Children");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            '',
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $first_select = $html->filter('.af-tree-cascade-select')->eq(0);
        $options = $first_select->filter('option')->each(
            fn(Crawler $node) => $node->text(),
        );
        $this->assertContains('Location A', $options);
        $this->assertContains('Location B', $options);
        $this->assertNotContains('Location A1', $options);
    }

    public function testDropdownShowsNameNotCompletename(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $parent = $this->createItem(Location::class, [
            'name'         => 'Parent Loc',
            'locations_id' => 0,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child Loc',
            'locations_id' => $parent->getID(),
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $default_value = json_encode([
            'itemtype' => Location::class,
            'items_id' => $child->getID(),
        ]);

        $builder = new FormBuilder("Tree Cascade Name Only");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            $default_value,
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $all_option_texts = $html->filter('.af-tree-cascade-select option')->each(
            fn(Crawler $node) => $node->text(),
        );
        foreach ($all_option_texts as $text) {
            $this->assertStringNotContainsString(' > ', $text);
        }
    }

    private function renderHelpdeskForm(\Glpi\Form\Form $form): Crawler
    {
        $this->login();
        $controller = new \Glpi\Controller\Form\RendererController();
        $response = $controller->__invoke(
            \Symfony\Component\HttpFoundation\Request::create(
                '',
                'GET',
                ['id' => $form->getID()],
            ),
        );
        return new Crawler($response->getContent());
    }
}
