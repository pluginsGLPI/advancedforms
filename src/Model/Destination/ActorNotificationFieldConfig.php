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

namespace GlpiPlugin\Advancedforms\Model\Destination;

use Glpi\DBAL\JsonFieldInterface;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldConfig;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\HasFieldWithQuestionId;
use Override;

/**
 * Disabled email followup sources, by actor role. Only `roles` is serialized.
 *
 * @phpstan-type RoleConfig array{
 *      disabled_strategies: list<string>,
 *      disabled_actors: list<string>,
 *      disabled_question_ids: list<int>
 * }
 */
#[HasFieldWithQuestionId(self::DISABLED_QUESTION_IDS, is_array: true, list_of_strategies_field: self::ROLES)]
final class ActorNotificationFieldConfig implements JsonFieldInterface
{
    public const ROLES = 'roles';

    public const DISABLED_STRATEGIES = 'disabled_strategies';

    public const DISABLED_ACTORS = 'disabled_actors';

    public const DISABLED_QUESTION_IDS = 'disabled_question_ids';

    /** @var array<string, ITILActorFieldConfig> */
    private array $actors_configs = [];

    /** @param array<string, RoleConfig> $roles */
    public function __construct(
        private readonly array $roles = [],
    ) {}

    /** @param array<mixed> $data */
    #[Override]
    public static function jsonDeserialize(array $data): self
    {
        $raw_roles = $data[self::ROLES] ?? [];
        if (!is_array($raw_roles)) {
            return new self();
        }

        $roles = [];
        foreach ($raw_roles as $role => $raw_role) {
            if (!is_string($role) || !is_array($raw_role)) {
                continue;
            }

            $roles[$role] = self::buildRoleConfig(
                strategies: self::filterStrings($raw_role[self::DISABLED_STRATEGIES] ?? []),
                actors: self::filterStrings($raw_role[self::DISABLED_ACTORS] ?? []),
                question_ids: self::filterIds($raw_role[self::DISABLED_QUESTION_IDS] ?? []),
            );
        }

        return new self($roles);
    }

    /** @return array{roles: array<string, RoleConfig>} */
    #[Override]
    public function jsonSerialize(): array
    {
        return [self::ROLES => $this->roles];
    }

    /**
     * Every list is always present: the core needs the question ids on duplication.
     *
     * @param list<string> $strategies
     * @param list<string> $actors
     * @param list<int>    $question_ids
     * @return RoleConfig
     */
    public static function buildRoleConfig(
        array $strategies = [],
        array $actors = [],
        array $question_ids = [],
    ): array {
        return [
            self::DISABLED_STRATEGIES   => $strategies,
            self::DISABLED_ACTORS       => $actors,
            self::DISABLED_QUESTION_IDS => $question_ids,
        ];
    }

    /** @param array<string, ITILActorFieldConfig> $actors_configs */
    public function withActorsConfigs(array $actors_configs): self
    {
        $config = clone $this;
        $config->actors_configs = $actors_configs;

        return $config;
    }

    public function getActorsConfig(string $role): ?ITILActorFieldConfig
    {
        return $this->actors_configs[$role] ?? null;
    }

    /** @return RoleConfig */
    public function getRoleConfig(string $role): array
    {
        return $this->roles[$role] ?? self::buildRoleConfig();
    }

    public function hasDisabledSources(string $role): bool
    {
        $role_config = $this->getRoleConfig($role);

        return $role_config[self::DISABLED_STRATEGIES] !== []
            || $role_config[self::DISABLED_ACTORS] !== []
            || $role_config[self::DISABLED_QUESTION_IDS] !== [];
    }

    public function isStrategyDisabled(string $role, ITILActorFieldStrategy $strategy): bool
    {
        return in_array($strategy->value, $this->getRoleConfig($role)[self::DISABLED_STRATEGIES], true);
    }

    /** @param string $actor_key `<foreign key>-<id>`, e.g. `users_id-12` */
    public function isActorDisabled(string $role, string $actor_key): bool
    {
        return in_array($actor_key, $this->getRoleConfig($role)[self::DISABLED_ACTORS], true);
    }

    public function isQuestionDisabled(string $role, int $question_id): bool
    {
        return in_array($question_id, $this->getRoleConfig($role)[self::DISABLED_QUESTION_IDS], true);
    }

    /** @return list<string> */
    private static function filterStrings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /** @return list<int> */
    private static function filterIds(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
