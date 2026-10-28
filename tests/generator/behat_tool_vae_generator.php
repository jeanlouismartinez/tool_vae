<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat data generator for tool_vae.
 *
 * @package    tool_vae
 * @category   test
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Lets a scenario create an imported certification record, declarations and attestations, one line each.
 *
 * @package    tool_vae
 * @category   test
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_vae_generator extends behat_generator_base {
    /**
     * What a scenario may create.
     *
     * @return array entity name => how to generate it.
     */
    protected function get_creatable_entities(): array {
        return [
            'sources' => [
                'singular' => 'source',
                'datagenerator' => 'source',
                'required' => ['fichecode', 'ficherang'],
            ],
            'competencies' => [
                'singular' => 'competency',
                'datagenerator' => 'competency',
                'required' => ['fichecode', 'ficherang', 'shortname'],
            ],
            'declarations' => [
                'singular' => 'declaration',
                'datagenerator' => 'declaration',
                'required' => ['candidate', 'situation'],
                'switchids' => ['candidate' => 'userid'],
            ],
            'pieces' => [
                'singular' => 'piece',
                'datagenerator' => 'piece',
                'required' => ['declaration', 'nature', 'url'],
                'switchids' => ['declaration' => 'evidenceid'],
            ],
            'replacements' => [
                'singular' => 'replacement',
                'datagenerator' => 'replacement',
                'required' => ['fichecode', 'successorcode'],
            ],
            'correspondences' => [
                'singular' => 'correspondence',
                'datagenerator' => 'correspondence',
                'required' => ['from', 'to'],
            ],
            'aiproviders' => [
                'singular' => 'aiprovider',
                'datagenerator' => 'aiprovider',
                'required' => ['name'],
            ],
            'attestations' => [
                'singular' => 'attestation',
                'datagenerator' => 'attestation',
                'required' => ['declaration', 'attestant'],
                'switchids' => ['declaration' => 'evidenceid', 'attestant' => 'userid'],
            ],
        ];
    }

    /**
     * The candidate who declares, named by username.
     *
     * @param string $username
     * @return int
     */
    protected function get_candidate_id(string $username): int {
        return $this->get_user_id($username);
    }

    /**
     * The person who attests, named by username.
     *
     * @param string $username
     * @return int
     */
    protected function get_attestant_id(string $username): int {
        return $this->get_user_id($username);
    }

    /**
     * A declaration, named by the account of the situation it gives.
     *
     * The data generator's lookup: generate_items() has set componentdatagenerator before it
     * resolves the switchids.
     *
     * @param string $situation
     * @return int
     */
    protected function get_declaration_id(string $situation): int {
        return $this->componentdatagenerator->get_declaration_id($situation);
    }
}
