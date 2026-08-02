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
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Advancedforms\Model\QuestionType\TableQuestion;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TableColumnQuestionConfigController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        path: 'TableColumnQuestionConfig',
        name: 'table_column_question_config',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $type = (string) $request->request->get('type', '');
        $column_index = $request->request->getInt('column_index', 0);
        $payload = $request->request->all();
        $extra_data = $payload['question_extra_data'] ?? [];

        $renderer = new TableQuestion();
        $html = $renderer->renderEmbeddedQuestionConfiguration(
            $type,
            max(0, $column_index),
            is_array($extra_data) ? $extra_data : [],
        );

        return new Response(
            $html,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
