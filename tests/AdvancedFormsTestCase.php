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

namespace GlpiPlugin\Advancedforms\Tests;

use AuthLDAP;
use Config;
use DbTestCase;
use Glpi\Form\Form;
use Glpi\Form\Migration\TypesConversionMapper;
use Glpi\Form\QuestionType\QuestionTypeInterface;
use Glpi\Form\QuestionType\QuestionTypesManager;
use Glpi\Tests\FormBuilder;
use Glpi\Tests\FormTesterTrait;
use GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestion;
use GlpiPlugin\Advancedforms\Model\QuestionType\LdapQuestionConfig;
use GlpiPlugin\Advancedforms\Service\ConfigManager;
use GlpiPlugin\Advancedforms\Service\InitManager;
use InvalidArgumentException;
use ReflectionClass;

abstract class AdvancedFormsTestCase extends DbTestCase
{
    use FormTesterTrait;

    /** @return array<array{ConfigurableItemInterface&QuestionTypeInterface}> */
    final public static function provideQuestionTypes(): array
    {
        $types = ConfigManager::getInstance()->getConfigurableQuestionTypes();
        return array_map(fn($c): array => [$c], $types);
    }

    public function setUp(): void
    {
        parent::setUp();

        // Delete form related single instances
        $this->deleteSingletonInstance([
            QuestionTypesManager::class,
            TypesConversionMapper::class,
        ]);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    protected function enableConfigurableItem(
        ConfigurableItemInterface|string $item,
    ): void {
        $this->setConfigurableItemConfig($item, true);
        InitManager::getInstance()->init();
    }

    /** @var array<ConfigurableItemInterface|string> $items */
    protected function enableConfigurableItems(
        array $items,
    ): void {
        foreach ($items as $item) {
            $this->setConfigurableItemConfig($item, true);
        }
        InitManager::getInstance()->init();
    }

    protected function disableConfigurableItem(
        ConfigurableItemInterface|string $item,
    ): void {
        $this->setConfigurableItemConfig($item, false);
        InitManager::getInstance()->init();
    }

    /** @var array<ConfigurableItemInterface|string> $items */
    protected function disableConfigurableItems(
        array $items,
    ): void {
        foreach ($items as $item) {
            $this->setConfigurableItemConfig($item, false);
        }
        InitManager::getInstance()->init();
    }

    protected function setupAuthLdap(): AuthLDAP
    {
        return $this->createItem(AuthLDAP::class, [
            'name'          => 'openldap',
            'host'          => 'openldap',
            'basedn'        => 'dc=glpi,dc=org',
            'rootdn'        => 'cn=admin,dc=glpi,dc=org',
            'port'          => '389',
            'condition'     => '(objectClass=inetOrgPerson)',
            'login_field'   => 'uid',
            'sync_field'    => 'entryuuid',
            'use_tls'       => 0,
            'use_dn'        => 1,
            'is_active'     => 1,
            'rootdn_passwd' => 'admin',
            'use_bind'      => 1,
        ], ['rootdn_passwd']);
    }

    protected function createFormWithLdapQuestion(AuthLdap $ldap): Form
    {
        $builder = new FormBuilder("My form");
        $builder->addQuestion(
            name: "LDAP select",
            type: LdapQuestion::class,
            extra_data: json_encode(new LdapQuestionConfig(
                authldap_id: $ldap->getId(),
                ldap_filter: "(& (uid=*) (objectClass=inetOrgPerson))",
                ldap_attribute_id: 6, // User ID,
            )),
        );
        return $this->createForm($builder);
    }

    private function setConfigurableItemConfig(
        ConfigurableItemInterface|string $item,
        bool $enabled,
    ): void {
        if (
            is_string($item)
            && !is_a($item, ConfigurableItemInterface::class, true)
        ) {
            throw new InvalidArgumentException();
        }

        Config::setConfigurationValues('advancedforms', [
            $item::getConfigKey() => (int) $enabled,
        ]);
    }

    private function deleteSingletonInstance(array $classes)
    {
        foreach ($classes as $class) {
            $reflection_class = new ReflectionClass($class);
            if ($reflection_class->hasProperty('instance')) {
                $reflection_property = $reflection_class->getProperty('instance');
                $reflection_property->setValue(null, null);
            }
            if ($reflection_class->hasProperty('_instances')) {
                $reflection_property = $reflection_class->getProperty('_instances');
                $reflection_property->setValue(null, []);
            }
        }
    }
}
