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

namespace GlpiPlugin\Advancedforms\Tests\Model\ConditionHandler;

use Glpi\Form\Condition\Engine;
use Glpi\Form\Condition\EngineInput;
use Glpi\Form\Condition\LogicOperator;
use Glpi\Form\Condition\Type;
use Glpi\Form\Condition\ValueOperator;
use Glpi\Form\Condition\VisibilityStrategy;
use Glpi\Form\QuestionType\QuestionTypeItemDropdownExtraDataConfig;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Tests\FormBuilder;
use GlpiPlugin\Advancedforms\Model\QuestionType\TreeCascadeDropdownQuestion;
use GlpiPlugin\Advancedforms\Tests\AdvancedFormsTestCase;
use Location;
use Session;

/**
 * Tests that the CONTAINS/NOT_CONTAINS condition operators on a TreeCascadeDropdown
 * question match against the item's full completename (hierarchical path) rather
 * than just its own name.
 *
 * Regression: selecting a child item only matched conditions against its own
 * name, so conditions referencing an ancestor were always false.
 */
final class TreeCascadeItemAsTextConditionHandlerTest extends AdvancedFormsTestCase
{
    /**
     * Verify that CONTAINS evaluates to true when the searched text appears in
     * the completename of the selected item but not in its own name alone.
     */
    public function testContainsMatchesCompletename(): void
    {
        $this->login();
        $this->enableConfigurableItem(TreeCascadeDropdownQuestion::class);

        $entity_id = Session::getActiveEntity();

        $parent = $this->createItem(Location::class, [
            'name'         => 'Parent Location',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child Location',
            'locations_id' => $parent->getID(),
            'entities_id'  => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $form_builder = new FormBuilder("Test form");
        $form_builder->addQuestion(
            name: "My location",
            type: TreeCascadeDropdownQuestion::class,
            extra_data: $extra_data,
        );
        $form_builder->addQuestion("Dependent question", QuestionTypeShortText::class);
        $form_builder->setQuestionVisibility("Dependent question", VisibilityStrategy::VISIBLE_IF, [
            [
                'logic_operator' => LogicOperator::AND,
                'item_name'      => "My location",
                'item_type'      => Type::QUESTION,
                'value_operator' => ValueOperator::CONTAINS,
                'value'          => 'Parent Location',
            ],
        ]);

        $form = $this->createForm($form_builder);

        $answers = [
            $this->getQuestionId($form, "My location") => [
                'itemtype' => Location::class,
                'items_id' => $child->getID(),
            ],
        ];

        $engine = new Engine($form, new EngineInput($answers));
        $output = $engine->computeVisibility();

        $dependent_id = $this->getQuestionId($form, "Dependent question");
        $this->assertTrue(
            $output->isQuestionVisible($dependent_id),
            "Condition 'contains Parent Location' should match a child item "
            . "whose completename is 'Parent Location > Child Location'",
        );
    }

    /**
     * Verify that CONTAINS evaluates to true when the parent item itself is
     * selected (completename equals the searched text exactly, not via a child).
     */
    public function testContainsMatchesParentDirectly(): void
    {
        $this->login();
        $this->enableConfigurableItem(TreeCascadeDropdownQuestion::class);

        $entity_id = Session::getActiveEntity();

        $parent = $this->createItem(Location::class, [
            'name'         => 'Parent Location',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $form_builder = new FormBuilder("Test form parent selected");
        $form_builder->addQuestion(
            name: "My location",
            type: TreeCascadeDropdownQuestion::class,
            extra_data: $extra_data,
        );
        $form_builder->addQuestion("Dependent question", QuestionTypeShortText::class);
        $form_builder->setQuestionVisibility("Dependent question", VisibilityStrategy::VISIBLE_IF, [
            [
                'logic_operator' => LogicOperator::AND,
                'item_name'      => "My location",
                'item_type'      => Type::QUESTION,
                'value_operator' => ValueOperator::CONTAINS,
                'value'          => 'Parent Location',
            ],
        ]);

        $form = $this->createForm($form_builder);

        $answers = [
            $this->getQuestionId($form, "My location") => [
                'itemtype' => Location::class,
                'items_id' => $parent->getID(),
            ],
        ];

        $engine = new Engine($form, new EngineInput($answers));
        $output = $engine->computeVisibility();

        $dependent_id = $this->getQuestionId($form, "Dependent question");
        $this->assertTrue(
            $output->isQuestionVisible($dependent_id),
            "Condition 'contains Parent Location' should match when the parent item itself is selected",
        );
    }

    /**
     * Verify that NOT_CONTAINS evaluates to false when the searched text appears
     * in the completename of the selected item (via an ancestor).
     */
    public function testNotContainsMatchesCompletename(): void
    {
        $this->login();
        $this->enableConfigurableItem(TreeCascadeDropdownQuestion::class);

        $entity_id = Session::getActiveEntity();

        $parent = $this->createItem(Location::class, [
            'name'         => 'Parent Location',
            'locations_id' => 0,
            'entities_id'  => $entity_id,
        ]);

        $child = $this->createItem(Location::class, [
            'name'         => 'Child Location',
            'locations_id' => $parent->getID(),
            'entities_id'  => $entity_id,
        ]);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $form_builder = new FormBuilder("Test form not contains");
        $form_builder->addQuestion(
            name: "My location",
            type: TreeCascadeDropdownQuestion::class,
            extra_data: $extra_data,
        );
        $form_builder->addQuestion("Dependent question", QuestionTypeShortText::class);
        $form_builder->setQuestionVisibility("Dependent question", VisibilityStrategy::VISIBLE_IF, [
            [
                'logic_operator' => LogicOperator::AND,
                'item_name'      => "My location",
                'item_type'      => Type::QUESTION,
                'value_operator' => ValueOperator::NOT_CONTAINS,
                'value'          => 'Parent Location',
            ],
        ]);

        $form = $this->createForm($form_builder);

        $answers = [
            $this->getQuestionId($form, "My location") => [
                'itemtype' => Location::class,
                'items_id' => $child->getID(),
            ],
        ];

        $engine = new Engine($form, new EngineInput($answers));
        $output = $engine->computeVisibility();

        $dependent_id = $this->getQuestionId($form, "Dependent question");
        $this->assertFalse(
            $output->isQuestionVisible($dependent_id),
            "Condition 'not contains Parent Location' should NOT match a child item "
            . "whose completename is 'Parent Location > Child Location'",
        );
    }

    /**
     * Verify that both CONTAINS and NOT_CONTAINS evaluate to false when no item
     * is selected (items_id = 0), so the dependent question is hidden in both cases.
     *
     * This is non-obvious: a NOT_CONTAINS condition does NOT show the question
     * when no selection has been made — it also evaluates to false.
     */
    public function testNoSelectionHidesDependentQuestion(): void
    {
        $this->login();
        $this->enableConfigurableItem(TreeCascadeDropdownQuestion::class);

        $extra_data = json_encode(new QuestionTypeItemDropdownExtraDataConfig(
            itemtype: Location::class,
        ));

        $form_builder = new FormBuilder("Test form no selection");
        $form_builder->addQuestion(
            name: "My location",
            type: TreeCascadeDropdownQuestion::class,
            extra_data: $extra_data,
        );
        $form_builder->addQuestion("Dependent question", QuestionTypeShortText::class);
        $form_builder->setQuestionVisibility("Dependent question", VisibilityStrategy::VISIBLE_IF, [
            [
                'logic_operator' => LogicOperator::AND,
                'item_name'      => "My location",
                'item_type'      => Type::QUESTION,
                'value_operator' => ValueOperator::NOT_CONTAINS,
                'value'          => 'some location',
            ],
        ]);

        $form = $this->createForm($form_builder);

        $answers = [
            $this->getQuestionId($form, "My location") => [
                'itemtype' => Location::class,
                'items_id' => 0,
            ],
        ];

        $engine = new Engine($form, new EngineInput($answers));
        $output = $engine->computeVisibility();

        $dependent_id = $this->getQuestionId($form, "Dependent question");
        $this->assertFalse(
            $output->isQuestionVisible($dependent_id),
            "When no item is selected (items_id=0), NOT_CONTAINS evaluates to false "
            . "and the dependent question should be hidden",
        );
    }
}
