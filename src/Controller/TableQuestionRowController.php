<?php

/**
 * -------------------------------------------------------------------------
 * advancedforms plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 by the advancedforms plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/advancedforms
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Advancedforms\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Controller\Form\Utils\CanCheckAccessPolicies;
use Glpi\Form\Question;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TableQuestionRowController extends AbstractController
{
    use CanCheckAccessPolicies;

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        path: 'TableQuestionRow',
        name: 'table_question_row',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $questions_id = $request->query->getInt('questions_id', 0);
        $row_index = $request->query->getInt('row_index', -1);
        $render_identity = preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '',
            $request->query->getString('render_identity', ''),
        );

        if ($questions_id <= 0 || $row_index < 0) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $question = Question::getById($questions_id);
        if (!$question) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->checkFormAccessPolicies($question->getForm(), $request);

        $type = $question->getQuestionType();
        if (!$type instanceof TableQuestion) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        return new Response(
            $type->renderEndUserRow($question, $row_index, $render_identity),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
