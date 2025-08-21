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

namespace GlpiPlugin\Advancedforms\Tests\Front;

use DbTestCase;
use DOMElement;
use Glpi\Exception\RedirectException;
use LogicException;
use RuntimeException;
use Session;
use Symfony\Component\DomCrawler\Crawler;

// Temporary test case until we can the real WebTestCase working
abstract class FrontTestCase extends DbTestCase
{
    public function get(string $url, array $params = []): Crawler
    {
        $old_GET = $_GET;
        $_GET = $params;

        try {
            ob_start();
            $_SERVER['REQUEST_URI'] = GLPI_ROOT . $url;
            require(GLPI_ROOT . $url);
            $html = ob_get_clean();
        } finally {
            $_GET = $old_GET;
        }

        return new Crawler($html);
    }

    public function post(string $url, array $payload, bool $add_token = true): void
    {
        $old_POST = $_POST;
        $_POST = $payload;

        try {
            if ($add_token) {
                $_POST['_glpi_csrf_token'] = Session::getNewCSRFToken();
            }

            ob_start();
            $_SERVER['REQUEST_URI'] = GLPI_ROOT . $url;
            require(GLPI_ROOT . $url);
            ob_get_clean();
        } catch (RedirectException) {
            // In legacy files redirect exception mean success.
            ob_get_clean();
        } finally {
            $_POST = $old_POST;
        }
    }

    public function sendForm(
        string $form_content_url,
        array $query_params,
        array $form_values,
    ): void {
        // Get form html content
        $form_crawler = $this->get($form_content_url, $query_params);
        $form = $form_crawler->filter('form')->getNode(0);
        if (!$form instanceof DOMElement) {
            throw new RuntimeException("Failed to find form");
        }

        // Parse form attributes
        $url = $form->getAttribute('action');
        $method = $form->getAttribute('method');
        if (strtolower($method) !== "post") {
            throw new RuntimeException("Only POST forms are supported");
        }

        // Compute default payload from html
        $payload = [];
        foreach ($form_crawler->filter('input') as $input) {
            if (!$input instanceof DOMElement) {
                throw new LogicException(); // Impossible
            }

            // Skip unchecked checkboxes
            $type = strtolower($input->getAttribute('type'));
            if ($type === 'checkbox' && !$input->hasAttribute('checked')) {
                continue;
            }

            // Add value to payload
            $payload[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        // Load submit button value
        $submits = $form_crawler->filter('button[type=submit]');
        if (count($submits) > 1) {
            throw new RuntimeException("Only forms with a single submit are supported");
        }
        $submit = $submits->getNode(0);
        if (!$submit instanceof DOMElement) {
            throw new LogicException(); // Impossible
        }
        $payload[$submit->getAttribute('name')] = $submit->getAttribute('value');

        // Insert specified payload values
        foreach ($form_values as $key => $value) {
            if (!isset($payload[$key])) {
                throw new RuntimeException("Input '$key' does not exist");
            }
            $payload[$key] = $value;
        }

        $this->post($url, $payload, add_token: false);
    }
}
