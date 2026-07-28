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

namespace GlpiPlugin\Advancedforms\Controller;

use CommonDBTM;
use Glpi\DBAL\QuerySubQuery;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Advancedforms\Model\QuestionType\ReservationQuestionConfig;
use ReservationItem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReservableItemsController extends AbstractReservationWidgetController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route(
        path: 'ReservationWidget/ReservableItems',
        name: 'reservation_widget_reservable_items',
        methods: 'POST',
    )]
    public function __invoke(Request $request): Response
    {
        $this->checkReservationAccess();

        /** @var array<mixed> $allowed_itemtypes */
        $allowed_itemtypes = $request->request->all('allowed_itemtypes');
        $search = $request->request->getString('search', '');

        // Same fallback as the question config: given types, or all reservable types.
        $allowed = array_values(array_filter($allowed_itemtypes, is_string(...)));
        $itemtypes = (new ReservationQuestionConfig($allowed))->getEffectiveAllowedItemtypes();

        $results = [];
        foreach ($itemtypes as $itemtype) {
            if (!is_string($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
                continue;
            }

            $results = [...$results, ...$this->getReservableItemsForType($itemtype, $search)];
        }

        return new JsonResponse($results);
    }

    /**
     * @param class-string<CommonDBTM> $itemtype
     * @return array<int, array{id: int, text: string, itemtype: string}>
     */
    private function getReservableItemsForType(string $itemtype, string $search): array
    {
        global $DB;

        $item = getItemForItemtype($itemtype);
        $where = [
            'itemtype' => $itemtype,
            'is_active' => 1,
        ];

        if ($search !== '') {
            $where['items_id'] = new QuerySubQuery([
                'SELECT' => 'id',
                'FROM' => $itemtype::getTable(),
                'WHERE' => ['name' => ['LIKE', sprintf('%%%s%%', $search)]],
            ]);
        }

        $rows = $DB->request([
            'FROM' => ReservationItem::getTable(),
            'WHERE' => $where,
        ]);

        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['items_id']) || !is_numeric($row['items_id'])) {
                continue;
            }

            if (!$item->getFromDB((int) $row['items_id'])) {
                continue;
            }

            $id = $row['id'] ?? null;
            $results[] = [
                'id' => is_numeric($id) ? (int) $id : 0,
                'text' => sprintf('%s (%s)', $item->getName(), $itemtype::getTypeName(1)),
                'itemtype' => $itemtype,
            ];
        }

        return $results;
    }
}
