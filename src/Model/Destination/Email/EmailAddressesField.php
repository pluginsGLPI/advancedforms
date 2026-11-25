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

namespace GlpiPlugin\Advancedforms\Model\Destination\Email;

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\Category;
use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Form;
use GLPIMailer;
use InvalidArgumentException;
use Override;

use function Safe\preg_split;

/**
 * Email addresses that receive the email in addition to the configured recipients.
 */
final class EmailAddressesField extends AbstractConfigField
{
    #[Override]
    public function getLabel(): string
    {
        return __('Additional email addresses', 'advancedforms');
    }

    #[Override]
    public function getConfigClass(): string
    {
        return SimpleValueConfig::class;
    }

    #[Override]
    public function getDefaultConfig(Form $form): SimpleValueConfig
    {
        return new SimpleValueConfig('');
    }

    /**
     * Valid addresses of the configured list, separated by commas or semicolons.
     *
     * @return list<string>
     */
    public function getEmailAddresses(SimpleValueConfig $config): array
    {
        return array_values(array_filter(
            $this->getConfiguredAddresses($config),
            GLPIMailer::validateAddress(...),
        ));
    }

    /**
     * All the addresses of the configured list, including the invalid ones
     * so that they stay visible in the config form.
     *
     * @return list<string>
     */
    public function getConfiguredAddresses(SimpleValueConfig $config): array
    {
        /** @var list<string> $parts */
        $parts = preg_split('/[,;]/', $config->getValue());

        $addresses = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '') {
                $addresses[strtolower($email)] ??= $email;
            }
        }

        return array_values($addresses);
    }

    /**
     * The tags dropdown submits one value per address, they are stored as a
     * list separated by commas.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    #[Override]
    public function prepareInput(array $input): array
    {
        /** @var array<string, mixed> $input */
        $input = parent::prepareInput($input);

        $field_input = $input[self::getKey()] ?? null;
        if (!is_array($field_input) || !is_array($field_input[SimpleValueConfig::VALUE] ?? null)) {
            return $input;
        }

        $emails = array_filter(
            array_map(
                fn(mixed $email): string => is_string($email) ? trim($email) : '',
                $field_input[SimpleValueConfig::VALUE],
            ),
            fn(string $email): bool => $email !== '',
        );
        $field_input[SimpleValueConfig::VALUE] = implode(', ', $emails);
        $input[self::getKey()] = $field_input;

        return $input;
    }

    /** @param array<string, mixed> $display_options */
    #[Override]
    public function renderConfigForm(
        Form $form,
        FormDestination $destination,
        JsonFieldInterface $config,
        string $input_name,
        array $display_options,
    ): string {
        if (!$config instanceof SimpleValueConfig) {
            throw new InvalidArgumentException("Unexpected config class");
        }

        return TemplateRenderer::getInstance()->render('@advancedforms/destination/email_addresses_field.html.twig', [
            'input_name' => $input_name . "[" . SimpleValueConfig::VALUE . "]",
            'addresses'  => $this->getConfiguredAddresses($config),
            'label'      => $this->getLabel(),
            'options'    => $display_options,
        ]);
    }

    #[Override]
    public function getWeight(): int
    {
        return 40;
    }

    #[Override]
    public function getCategory(): Category
    {
        return Category::ACTORS;
    }
}
