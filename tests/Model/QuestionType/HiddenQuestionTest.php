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

use Glpi\Altcha\AltchaManager;
use Glpi\Controller\Form\SubmitAnswerController;
use Glpi\Form\AccessControl\ControlType\DirectAccess;
use Glpi\Form\AccessControl\ControlType\DirectAccessConfig;
use Glpi\Form\AnswersSet;
use Glpi\Form\Form;
use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Tests\FormBuilder;
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\HiddenQuestion;
use GlpiPlugin\Advancedforms\Tests\QuestionType\QuestionTypeTestCase;
use Override;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class HiddenQuestionTest extends QuestionTypeTestCase
{
    use FormTesterTrait;

    #[Override]
    protected function getTestedQuestionType(): QuestionTypeInterface&ConfigurableItemInterface
    {
        return new HiddenQuestion();
    }

    #[Override]
    protected function validateEditorRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $input = $html->filter('input[placeholder="Hidden value"]');
        $this->assertNotEmpty($input);
    }

    #[Override]
    protected function setDefaultValueBeforeHelpdeskRendering(): string
    {
        return 'my hidden value';
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenEnabled(
        Crawler $html,
    ): void {
        $input = $html->filter('input[value="my hidden value"]');
        $this->assertNotEmpty($input);
    }

    #[Override]
    protected function validateHelpdeskRenderingWhenDisabled(
        Crawler $html,
    ): void {
        $input = $html->filter('input[value="my hidden value"]');
        $this->assertEmpty($input);
    }

    public function testUnauthenticatedSubmissionSavesHiddenDefaultValue(): void
    {
        $this->enableConfigurableItem(HiddenQuestion::class);
        $builder = new FormBuilder("Anonymous form");
        $builder->addQuestion("My hidden question", HiddenQuestion::class, 'my hidden value');
        $builder->setUseDefaultAccessPolicies(false);
        $builder->addAccessControl(DirectAccess::class, new DirectAccessConfig(
            token: 'my_token',
            allow_unauthenticated: true,
        ));
        $form = $this->createForm($builder);
        $question_id = $this->getQuestionId($form, "My hidden question");

        $this->logOut();
        $request = Request::create('/Form/SubmitAnswers?token=my_token', 'POST', [
            'forms_id'             => $form->getID(),
            'answers_' . $question_id => 'tampered value',
            'altcha'               => $this->solveAltchaChallenge(),
        ]);
        $response = (new SubmitAnswerController(new NullLogger()))->__invoke($request);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $answers_set = $this->getLastAnswersSet($form);
        $answer = $answers_set->getAnswerByQuestionId($question_id);
        $this->assertNotNull($answer);
        $this->assertSame('my hidden value', $answer->getRawAnswer());
    }

    private function getLastAnswersSet(Form $form): AnswersSet
    {
        $rows = (new AnswersSet())->find(['forms_forms_id' => $form->getID()], ['id DESC'], 1);
        $this->assertCount(1, $rows);
        return AnswersSet::getById(current($rows)['id']);
    }

    private function solveAltchaChallenge(): string
    {
        $challenge = AltchaManager::getInstance()->generateChallenge();
        $number = null;
        for ($i = 0; $i <= $challenge->maxNumber; $i++) {
            if (hash_equals(hash('sha256', $challenge->salt . $i), $challenge->challenge)) {
                $number = $i;
                break;
            }
        }

        return base64_encode(json_encode([
            'algorithm' => $challenge->algorithm,
            'challenge' => $challenge->challenge,
            'number'    => $number,
            'salt'      => $challenge->salt,
            'signature' => $challenge->signature,
        ]));
    }
}
