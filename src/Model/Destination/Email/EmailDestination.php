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
use Glpi\DBAL\PrepareForCloneInterface;
use Glpi\Error\ErrorHandler;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AnswersSet;
use Glpi\Form\Clone\FormCloneHelper;
use Glpi\Form\Destination\AbstractConfigField;
use Glpi\Form\Destination\CommonITILField\ContentField;
use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;
use Glpi\Form\Destination\CommonITILField\TitleField;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationInterface;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Destination\HasFormTags;
use Glpi\Form\Export\Context\DatabaseMapper;
use Glpi\Form\Export\Serializer\DynamicExportDataField;
use Glpi\Form\Form;
use Glpi\Form\Tag\FormTagsManager;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use Override;
use ReflectionClass;
use Throwable;

/**
 * Send a customized email, which may contain form tags, when a form is submitted.
 */
final class EmailDestination implements FormDestinationInterface, PrepareForCloneInterface, ConfigurableItemInterface
{
    /** @return list<AbstractConfigField> */
    public function getConfigurableFields(): array
    {
        return [
            new TitleField(),
            new ContentField(),
            new EmailRecipientsField(),
            new EmailAddressesField(),
        ];
    }

    #[Override]
    public function getLabel(): string
    {
        return __('Email', 'advancedforms');
    }

    #[Override]
    public function getIcon(): string
    {
        return 'ti ti-mail';
    }

    #[Override]
    public function getWeight(): int
    {
        return 100;
    }

    #[Override]
    public function useDefaultConfigLayout(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $config */
    #[Override]
    public function createDestinationItems(
        Form $form,
        AnswersSet $answers_set,
        array $config,
    ): array {
        if (ConfigManager::getInstance()->isConfigurableItemEnabled($this)) {
            try {
                (new EmailSender())->send($form, $answers_set, $config);
            } catch (Throwable $e) {
                // Destinations are created inside the transaction that saves
                // the answers: an email must never cancel the submission.
                ErrorHandler::logCaughtException($e);
            }
        }

        // Nothing is linked to the answers: an email is not an item the
        // person who filled the form can open.
        return [];
    }

    #[Override]
    public function postCreateDestinationItems(
        Form $form,
        AnswersSet $answers_set,
        FormDestination $destination,
        array $created_items,
    ): void {}

    /** @param array<string, mixed> $config */
    #[Override]
    public function renderConfigForm(
        Form $form,
        FormDestination $destination,
        array $config,
    ): string {
        $fields = [];
        foreach ($this->getConfigurableFields() as $field) {
            $fields[$field::getKey()] = $field;
        }

        return TemplateRenderer::getInstance()->render(
            '@advancedforms/destination/email_destination_config.html.twig',
            [
                'item'           => $this,
                'form'           => $form,
                'destination'    => $destination,
                'config'         => $config,
                'title_field'      => $fields[TitleField::getKey()],
                'content_field'    => $fields[ContentField::getKey()],
                'recipients_field' => $fields[EmailRecipientsField::getKey()],
                'addresses_field'  => $fields[EmailAddressesField::getKey()],
                'can_update'     => FormDestination::canUpdate() && $destination->canUpdateItem(),
                'recipients_warning' => $this->getRecipientsWarning($form, $config),
            ],
        );
    }

    /**
     * Warn the form administrators when the email can be sent to an address
     * typed in the answers: the form can then send any text to anyone.
     *
     * @param array<string, mixed> $config
     */
    public function getRecipientsWarning(Form $form, array $config): ?string
    {
        $recipients_field = new EmailRecipientsField();
        $recipients_config = $recipients_field->getConfig($form, $config);
        if (
            !$recipients_config instanceof EmailRecipientsFieldConfig
            || !$recipients_field->usesTypedEmailAddresses($recipients_config, $form)
        ) {
            return null;
        }

        return FormAccessControlManager::getInstance()->allowUnauthenticatedAccess($form)
            ? self::getUnauthenticatedRecipientsWarning()
            : self::getTypedRecipientsWarning();
    }

    public static function getTypedRecipientsWarning(): string
    {
        return __('Anyone who can answer this form can have an email sent to any address.', 'advancedforms');
    }

    public static function getUnauthenticatedRecipientsWarning(): string
    {
        return __('Anyone can answer this form without being authenticated and have an email sent to any address.', 'advancedforms');
    }

    /**
     * Name of the input of a config field, as expected by the core macro that
     * renders the fields (see `AbstractCommonITILFormDestination::formatConfigInputName()`).
     */
    public function formatConfigInputName(string $field_key): string
    {
        if (str_ends_with($field_key, '[]')) {
            return "config[" . substr($field_key, 0, -2) . "][]";
        }

        return sprintf('config[%s]', $field_key);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    #[Override]
    public function prepareInputForClone(array $data): array
    {
        foreach ($this->getConfigurableFields() as $field) {
            $key = $field::getKey();
            if (!is_array($data[$key] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $field_config */
            $field_config = $data[$key];
            $data[$key] = FormCloneHelper::getInstance()->prepareCommonItilDestinationFieldInputForClone(
                $field,
                $field_config,
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $config */
    #[Override]
    public function exportDynamicConfig(array $config): DynamicExportDataField
    {
        $requirements = [];
        foreach ($this->getConfigurableFields() as $field) {
            $key = $field::getKey();
            if (!is_array($config[$key] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $field_config */
            $field_config = $config[$key];
            $export_data = $field->exportDynamicConfig($field_config, self::getItilDestinationPlaceholder());
            $config[$key] = $export_data->getData();
            array_push($requirements, ...$export_data->getRequirements());
        }

        return new DynamicExportDataField($config, $requirements);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    #[Override]
    public static function prepareDynamicConfigDataForImport(
        array $config,
        DatabaseMapper $mapper,
    ): array {
        $tags_manager = new FormTagsManager();
        foreach ((new self())->getConfigurableFields() as $field) {
            $key = $field::getKey();
            if (!is_array($config[$key] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $field_config */
            $field_config = $field::prepareDynamicConfigDataForImport(
                $config[$key],
                self::getItilDestinationPlaceholder(),
                $mapper,
            );

            // Same as core: fields holding form tags refer to questions by id.
            if (
                (new ReflectionClass($field))->getAttributes(HasFormTags::class) !== []
                && is_string($field_config[SimpleValueConfig::VALUE] ?? null)
            ) {
                $field_config[SimpleValueConfig::VALUE] = $tags_manager->replaceIdsInTags(
                    $field_config[SimpleValueConfig::VALUE],
                    $mapper,
                );
            }

            $config[$key] = $field_config;
        }

        return $config;
    }

    /**
     * The export and import methods of the core config fields are typed for
     * ITIL destinations, although the fields used here never read that
     * parameter. A ticket destination is given to satisfy the signature.
     */
    private static function getItilDestinationPlaceholder(): FormDestinationTicket
    {
        return new FormDestinationTicket();
    }

    #[Override]
    public static function getConfigKey(): string
    {
        return 'enable_destination_email';
    }

    #[Override]
    public function getConfigTitle(): string
    {
        return __('Email destination', 'advancedforms');
    }

    #[Override]
    public function getConfigDescription(): string
    {
        return __('Send a customized email, which may contain form tags, when the form is submitted.', 'advancedforms');
    }

    #[Override]
    public function getConfigIcon(): string
    {
        return $this->getIcon();
    }
}
