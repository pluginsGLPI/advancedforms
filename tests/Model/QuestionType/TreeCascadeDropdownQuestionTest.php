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
use GlpiPlugin\Advancedforms\Controller\TreeDropdownChildrenController;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\TreeCascadeDropdownQuestion;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Symfony\Component\HttpFoundation\Request;
use Location;
use Override;
use Session;
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

    #[Override]
    protected function getDefaultExtraDataForQuestionType(
        QuestionTypeInterface $type,
    ): ?string {
        return json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));
    }

    /**
     * Verify that the question type returns a non-empty display name.
     */
    public function testGetName(): void
    {
        $question_type = new TreeCascadeDropdownQuestion();
        $this->assertNotEmpty($question_type->getName());
    }

    /**
     * Verify that the question type returns the expected sitemap icon.
     */
    public function testGetIcon(): void
    {
        $question_type = new TreeCascadeDropdownQuestion();
        $this->assertEquals('ti ti-sitemap', $question_type->getIcon());
    }

    /**
     * Verify that the question type has a weight of 30 for ordering purposes.
     */
    public function testGetWeight(): void
    {
        $question_type = new TreeCascadeDropdownQuestion();
        $this->assertEquals(30, $question_type->getWeight());
    }

    /**
     * Verify that the allowed itemtypes include Location and ITILCategory under the Ticket category.
     */
    public function testAllowedItemtypes(): void
    {
        $expected = [
            'Common' => [
                Location::class,
                \State::class,
            ],
            'Assistance' => [
                \ITILCategory::class,
                \TaskCategory::class,
                \Glpi\Form\Category::class,
            ],
            'Types' => [
                \SoftwareLicenseType::class,
            ],
            'Management' => [
                \DocumentCategory::class,
                \BusinessCriticity::class,
            ],
            'Tools' => [
                \KnowbaseItemCategory::class,
            ],
            'Internet' => [
                \IPNetwork::class,
            ],
            'Software' => [
                \SoftwareCategory::class,
            ],
            'Others' => [
                \WebhookCategory::class,
            ],
        ];
        $question_type = new TreeCascadeDropdownQuestion();
        $allowed = $question_type->getAllowedItemtypes();

        foreach ($expected as $group => $itemtypes) {
            $this->assertArrayHasKey($group, $allowed);
            foreach ($itemtypes as $itemtype) {
                $this->assertContains($itemtype, $allowed[$group]);
            }
        }
    }

    /**
     * Verify that the configuration key matches the expected plugin setting name.
     */
    public function testGetConfigKey(): void
    {
        $this->assertEquals(
            'enable_tree_cascade_dropdown',
            TreeCascadeDropdownQuestion::getConfigKey(),
        );
    }

    /**
     * Verify that when rendering a tree cascade dropdown with a 3-level location
     * hierarchy (Root > Child > Grandchild), only the root-level items appear
     * in the first dropdown. Child and grandchild items must not be visible
     * until their parent is selected.
     */
    public function testHelpdeskRenderingWithLocationHierarchy(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $root = $this->createItem(Location::class, [
            'name'         => 'Root Location',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child Location',
            'locations_id' => $root->getID(),
            'entities_id'  => $entity_id,
        ]);

        $grandchild = $this->createItem(Location::class, [
            'name'         => 'Grandchild Location',
            'locations_id' => $child->getID(),
            'entities_id'  => $entity_id,
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

    /**
     * Verify that when a default value (child item ID) is set on the question,
     * the form pre-renders with at least two cascading selects: the first one
     * containing the root parent, and the second one with the child pre-selected.
     * A hidden input must also carry the default value.
     */
    public function testHelpdeskRenderingWithDefaultValue(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $root = $this->createItem(Location::class, [
            'name'         => 'Root A',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child A1',
            'locations_id' => $root->getID(),
            'entities_id'  => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $builder = new FormBuilder("Tree Cascade Preselected");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            $child->getID(),
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

    /**
     * Verify that when a subtree root is configured (root_items_id), only the
     * direct children of that subtree root are shown in the first dropdown.
     * The global root and the subtree root itself must not appear as options.
     */
    public function testHelpdeskRenderingWithSubtreeRoot(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $root = $this->createItem(Location::class, [
            'name'         => 'Global Root',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $subtree_root = $this->createItem(Location::class, [
            'name'         => 'Subtree Root',
            'locations_id' => $root->getID(),
            'entities_id'  => $entity_id,
        ]);

        $subtree_child = $this->createItem(Location::class, [
            'name'         => 'Subtree Child',
            'locations_id' => $subtree_root->getID(),
            'entities_id'  => $entity_id,
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

    /**
     * Verify that when selectable_tree_root is enabled, the subtree root item
     * itself appears as a selectable option in the first dropdown, while its
     * children do not appear at the same level.
     */
    public function testHelpdeskRenderingWithSelectableTreeRoot(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $subtree_root = $this->createItem(Location::class, [
            'name'         => 'Selectable Root',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Root Child',
            'locations_id' => $subtree_root->getID(),
            'entities_id'  => $entity_id,
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

    /**
     * Verify that the first dropdown only shows direct children of the tree root
     * (top-level items). Nested children (e.g. Location A1 under Location A)
     * must not appear alongside their parents in the first select.
     */
    public function testFirstDropdownShowsOnlyDirectChildren(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $root_a = $this->createItem(Location::class, [
            'name'         => 'Location A',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $root_b = $this->createItem(Location::class, [
            'name'         => 'Location B',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $child_a1 = $this->createItem(Location::class, [
            'name'         => 'Location A1',
            'locations_id' => $root_a->getID(),
            'entities_id'  => $entity_id,
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

    /**
     * Verify that dropdown options display the short "name" field rather than
     * the full "completename" path (which contains " > " separators).
     * This ensures a cleaner UI for each cascade level.
     */
    public function testDropdownShowsNameNotCompletename(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $parent = $this->createItem(Location::class, [
            'name'         => 'Parent Loc',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child Loc',
            'locations_id' => $parent->getID(),
            'entities_id'  => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $builder = new FormBuilder("Tree Cascade Name Only");
        $builder->addQuestion(
            "My location",
            TreeCascadeDropdownQuestion::class,
            $child->getID(),
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

    /**
     * Verify that the subtree depth limit prevents children beyond the configured depth from being returned by the children controller.
     */
    public function testSubtreeDepthIsEnforcedInChildrenController(): void
    {
        $this->login();
        $this->enableConfigurableItem(TreeCascadeDropdownQuestion::class);

        $entity_id = Session::getActiveEntity();
        $level1 = $this->createItem(Location::class, [
            'name'         => 'Level1',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);
        $level2 = $this->createItem(Location::class, [
            'name'         => 'Level2',
            'locations_id' => $level1->getID(),
            'entities_id'  => $entity_id,
        ]);
        $level3 = $this->createItem(Location::class, [
            'name'         => 'Level3',
            'locations_id' => $level2->getID(),
            'entities_id'  => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
            subtree_depth: 2,
        ));

        $builder = new FormBuilder("Depth limit test");
        $builder->addQuestion("Location", TreeCascadeDropdownQuestion::class, '', $extra_data);
        $form = $this->createForm($builder);

        $questions = $form->getQuestions();
        $question = array_values($questions)[0];

        $controller = new TreeDropdownChildrenController();

        $response_level1_children = $controller->__invoke(
            Request::create('', 'GET', ['questions_id' => $question->getID(), 'parent_id' => $level1->getID()]),
        );
        $this->assertStringContainsString('Level2', $response_level1_children->getContent());

        $response_level2_children = $controller->__invoke(
            Request::create('', 'GET', ['questions_id' => $question->getID(), 'parent_id' => $level2->getID()]),
        );
        $this->assertStringNotContainsString('Level3', $response_level2_children->getContent());
    }

    /**
     * Verify that when the parent itself exceeds the subtree depth limit, it is not selectable and no children are returned.
     */
    public function testParentExceedingDepthLimitIsNotSelectableAndHasNoChildren(): void
    {
        $this->login();
        $this->enableConfigurableItem(TreeCascadeDropdownQuestion::class);

        $entity_id = Session::getActiveEntity();
        $level1 = $this->createItem(Location::class, [
            'name'         => 'L1',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);
        $level2 = $this->createItem(Location::class, [
            'name'         => 'L2',
            'locations_id' => $level1->getID(),
            'entities_id'  => $entity_id,
        ]);
        $level3 = $this->createItem(Location::class, [
            'name'         => 'L3',
            'locations_id' => $level2->getID(),
            'entities_id'  => $entity_id,
        ]);
        $this->createItem(Location::class, [
            'name'         => 'L4',
            'locations_id' => $level3->getID(),
            'entities_id'  => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
            subtree_depth: 2,
        ));

        $builder = new FormBuilder("Depth selectable test");
        $builder->addQuestion("Location", TreeCascadeDropdownQuestion::class, '', $extra_data);
        $form = $this->createForm($builder);

        $questions = $form->getQuestions();
        $question = array_values($questions)[0];

        $controller = new TreeDropdownChildrenController();

        // Level3 exceeds subtree_depth=2; its child L4 must not be returned and
        // the parent_is_selectable placeholder (value="0") must not appear.
        $response = $controller->__invoke(
            Request::create('', 'GET', ['questions_id' => $question->getID(), 'parent_id' => $level3->getID()]),
        );
        $this->assertStringNotContainsString('value="0"', $response->getContent());
        $this->assertStringNotContainsString('L4', $response->getContent());
    }

    /**
     * Verify that when a question is configured with the "Test1" custom dropdown,
     * only items from that dropdown appear in the rendered form. Items from another
     * custom dropdown ("Test2"), which shares the same database table, must not appear.
     */
    public function testHelpdeskRenderingOnlyShowsItemsFromConfiguredCustomDropdown(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $test1_definition = $this->initDropdownDefinition('Test1');
        $test2_definition = $this->initDropdownDefinition('Test2');

        $test1_class = $test1_definition->getDropdownClassName();
        $test2_class = $test2_definition->getDropdownClassName();

        \Dropdown::resetItemtypesStaticCache();

        $this->createItem($test1_class, [
            'name'        => 'Item from Test1',
            'entities_id' => $entity_id,
        ]);
        $this->createItem($test2_class, [
            'name'        => 'Item from Test2',
            'entities_id' => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: $test1_class,
        ));

        $builder = new FormBuilder("Custom Dropdown Form");
        $builder->addQuestion(
            "TreeCascadeDropdown Question",
            TreeCascadeDropdownQuestion::class,
            '',
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $all_option_texts = $html->filter('.af-tree-cascade-select option')->each(
            fn(Crawler $node) => $node->text(),
        );

        $this->assertContains('Item from Test1', $all_option_texts);
        $this->assertNotContains('Item from Test2', $all_option_texts);
    }

    /**
     * Verify that when a default value is set on a TreeCascadeDropdown question
     */
    public function testHelpdeskRenderingWithCustomDropdownAndDefaultValue(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $test1_definition = $this->initDropdownDefinition('Test1');
        $test2_definition = $this->initDropdownDefinition('Test2');

        $test1_class = $test1_definition->getDropdownClassName();
        $test2_class = $test2_definition->getDropdownClassName();

        \Dropdown::resetItemtypesStaticCache();

        $foreign_key = $test1_class::getForeignKeyField();

        $parent = $this->createItem($test1_class, [
            'name'        => 'Parent Custom Item',
            'entities_id' => $entity_id,
        ]);
        $child = $this->createItem($test1_class, [
            'name'        => 'Child Custom Item',
            $foreign_key  => $parent->getID(),
            'entities_id' => $entity_id,
        ]);
        $this->createItem($test2_class, [
            'name'        => 'Other Dropdown Item',
            'entities_id' => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: $test1_class,
        ));

        $builder = new FormBuilder("Custom Dropdown Default Value Form");
        $builder->addQuestion(
            "My custom dropdown",
            TreeCascadeDropdownQuestion::class,
            $child->getID(),
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $hidden_input = $html->filter('input[type="hidden"][value="' . $child->getID() . '"]');
        $this->assertNotEmpty($hidden_input);

        $selects = $html->filter('.af-tree-cascade-select');

        $options = $selects->eq(0)->filter('option')->each(
            fn(Crawler $node) => $node->text(),
        );
        $this->assertContains('Parent Custom Item', $options);
        $this->assertNotContains('Other Dropdown Item', $options);

        $selected_option = $selects->eq(1)->filter('option[selected]');
        $this->assertNotEmpty($selected_option);
        $this->assertEquals('Child Custom Item', $selected_option->text());
    }

    /**
     * Verify that when a subtree root is configured for a custom dropdown
     */
    public function testHelpdeskRenderingWithCustomDropdownAndSubtreeRoot(): void
    {
        $this->login();
        $item = $this->getTestedQuestionType();
        $this->enableConfigurableItem($item);

        $entity_id = Session::getActiveEntity();

        $test1_definition = $this->initDropdownDefinition('Test1');
        $test2_definition = $this->initDropdownDefinition('Test2');

        $test1_class = $test1_definition->getDropdownClassName();
        $test2_class = $test2_definition->getDropdownClassName();

        \Dropdown::resetItemtypesStaticCache();

        $foreign_key = $test1_class::getForeignKeyField();

        [$root, $root_2] = $this->createItems($test1_class, [
            [
                'name'        => 'Root',
                'entities_id' => $entity_id,
            ],
            [
                'name'        => 'Root 2',
                'entities_id' => $entity_id,
            ],
        ]);
        $this->createItem($test1_class, [
            'name'        => 'Subtree Child',
            $foreign_key  => $root->getID(),
            'entities_id' => $entity_id,
        ]);
        $this->createItem($test1_class, [
            'name'        => 'Subtree Child 2',
            $foreign_key  => $root_2->getID(),
            'entities_id' => $entity_id,
        ]);
        $this->createItem($test2_class, [
            'name'        => 'Test 2 Option',
            'entities_id' => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: $test1_class,
            root_items_id: $root->getID(),
        ));

        $builder = new FormBuilder("Custom Dropdown Subtree Root Form");
        $builder->addQuestion(
            "My custom dropdown",
            TreeCascadeDropdownQuestion::class,
            '',
            $extra_data,
        );
        $form = $this->createForm($builder);

        $html = $this->renderHelpdeskForm($form);

        $all_option_texts = $html->filter('.af-tree-cascade-select option')->each(
            fn(Crawler $node) => $node->text(),
        );

        $this->assertContains('Subtree Child', $all_option_texts);
        $this->assertNotContains('Subtree Child 2', $all_option_texts);
        $this->assertNotContains('Root', $all_option_texts);
        $this->assertNotContains('Root 2', $all_option_texts);
        $this->assertNotContains('Test 2 Option', $all_option_texts);
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
