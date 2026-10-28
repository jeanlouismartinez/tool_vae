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

namespace tool_vae;

use tool_vae\local\ai_assistant;
use tool_vae\local\ai_screens;

/**
 * What the reformulation aid leaves in the core is left to Moodle when the account is deleted (T-70, decision of 27 October 2026).
 *
 * WHAT IS ASSERTED. That deleting an account erases NOTHING of what the core keeps for the aid - the text sent, the answer, the
 * acceptance of the AI policy - whether the account called the aid a minute ago or never did; that the deletion request Moodle
 * files at every deletion is the only door, and that approving it empties the text and the answer, removes the acceptance and
 * keeps a line of the core's register (the account number, the date, the provider, the model, the tokens, the success or the
 * failure and the provider's error message); and that the confirmation screen says exactly that.
 *
 * THE DOOR HAS TWO CONDITIONS, AND THE SCREEN SAYS WHICH ONE HOLDS (audit of 27 October 2026, g4-1 and g4-3). The site must file
 * the request on its own (`automaticdeletionrequests`), and its data registry must not define the purpose and the category of the
 * site: once it does, the core drops the context of the site from an approved deletion request, which is where the aid's texts are
 * tied, and the request is declared done with the texts still there. The test that names that hole is below, so that it is not
 * discovered on a site that configured its registry. Where either condition fails, the screen does not promise the erasure.
 *
 * WHY IT IS A DECISION AND NOT A DEFAULT. Until this decision the observer had the core erase those texts at once for an account
 * that had called the aid in the last two days (the life of the plugin's own register of calls), and left the others to the
 * request. Two rules, a limit that protected nothing, and a call into another component's privacy provider made outside its
 * procedure. The plugin now erases nothing of it: one door, Moodle's.
 *
 * REAL BYTES, NOT INVENTED ONES. The text is a work situation of the kind the screens of the plugin ask for, and the rows of the
 * core are written the way \core_ai\manager::process_action() writes them: the action the plugin built (so the prompt holds the
 * fixed instruction and the marker), the response, the register. The provider itself is never called.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\ai_screens::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\ai_assistant::class)]
final class ai_erasure_left_to_moodle_test extends \advanced_testcase {
    /** @var string What a candidate sent to the aid: a work situation. */
    private const SITUATION = 'Accompagnement au lever et à la toilette, en binôme avec une aide-soignante.';

    /** @var string What the provider answered. */
    private const ANSWER = 'Le matin, j\'accompagne les résidents au lever et à la toilette, en binôme avec une aide-soignante.';

    /** @var string What another component of the site wrote through the AI subsystem for the same person. */
    private const ELSEWHERE = 'Texte écrit dans l\'éditeur d\'une page d\'administration.';

    /**
     * @var string The register line the confirmation promises, in English: what the core keeps of a call after the erasure. No
     *      word may be left out: the account number is on it, and so is the provider's own message after a failure (the tests
     *      below read the very rows).
     */
    private const REGISTER = 'Even after the erasure, Moodle keeps a line in its register: your account number (which stays '
        . 'tied to your name in Moodle), the date, the provider, the model, the size of the exchange (in tokens), whether the '
        . 'call succeeded or failed and, after a failure, the error message of the provider.';

    /** @var int[] The ids of the rows the replaced call stored in the core, in the order of the calls. */
    private $stored = [];

    /**
     * An open aid, and the settings of the data privacy tool pinned so that the test does not depend on the installation: a
     * request is filed at each deletion, it waits for a person to approve it, and the registry of the site is not defined.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();

        set_config('aiopen', 1, 'tool_vae');
        set_config('automaticdeletionrequests', 1, 'tool_dataprivacy');
        set_config('automaticdatadeletionapproval', 0, 'tool_dataprivacy');
        unset_config('context_system_purpose', 'tool_dataprivacy');
        unset_config('context_system_category', 'tool_dataprivacy');
        $this->stored = [];
    }

    /**
     * Define the data registry of the site the way its administrator does: a purpose and a category for the site.
     *
     * The registry page of the data privacy tool ends in api::set_context_defaults() for the context level of the site; the
     * purpose and the category are created by the generator of the tool. The core reads the registry as defined (and stops
     * emptying what is tied to the context of the site) only when BOTH are set.
     *
     * @param bool $purpose Whether the purpose of the site is set.
     * @param bool $category Whether the category of the site is set.
     */
    private function define_the_registry(bool $purpose = true, bool $category = true): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_dataprivacy');
        if ($purpose && $category) {
            \tool_dataprivacy\api::set_context_defaults(
                CONTEXT_SYSTEM,
                $generator->create_category()->get('id'),
                $generator->create_purpose()->get('id')
            );

            return;
        }
        // Half a registry, which the core does not take for a defined one: one value set where the core keeps it.
        if ($purpose) {
            set_config('context_system_purpose', $generator->create_purpose()->get('id'), 'tool_dataprivacy');
        }
        if ($category) {
            set_config('context_system_category', $generator->create_category()->get('id'), 'tool_dataprivacy');
        }
    }

    /**
     * A user who holds the capability to use the aid in their own context and has accepted the site's AI policy.
     *
     * @return \stdClass
     */
    private function capable_user(): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $role = $generator->create_role();
        assign_capability('tool/vae:useai', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $user->id, \context_user::instance($user->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        \core_ai\manager::user_policy_accepted((int) $user->id, ai_assistant::core_context()->id);

        return $user;
    }

    /**
     * The assistant with one provider that answers, and a core that keeps what it is given the way the real one does.
     *
     * @return ai_assistant
     */
    private function assistant(): ai_assistant {
        return new ai_assistant(fn() => ['Fournisseur d\'essai'], function ($action) {
            $response = new \core_ai\aiactions\responses\response_generate_text(true);
            $response->set_response_data([
                'generatedcontent' => self::ANSWER, 'model' => 'mistral-small', 'prompttokens' => 120, 'completiontokens' => 40,
            ]);
            $this->stored[] = $this->store_like_the_core($action, $response);

            return $response;
        });
    }

    /**
     * Write what \core_ai\manager::process_action() writes once a provider has answered: the action, then the register.
     *
     * The register takes from the response what the core takes: the success, the error code and message, the model and the token
     * counts. A failed answer is written the same way, with its error message.
     *
     * @param \core_ai\aiactions\generate_text $action The action, with the user and the context it was asked in.
     * @param \core_ai\aiactions\responses\response_generate_text $response The answer.
     * @return int The id of the stored action.
     */
    private function store_like_the_core(
        \core_ai\aiactions\generate_text $action,
        \core_ai\aiactions\responses\response_generate_text $response
    ): int {
        global $DB;

        $actionid = $action->store($response);
        $data = $response->get_response_data();
        $DB->insert_record('ai_action_register', (object) [
            'actionname' => 'generate_text', 'success' => (int) $response->get_success(),
            'userid' => $action->get_configuration('userid'), 'contextid' => $action->get_configuration('contextid'),
            'provider' => 'aiprovider_ollama', 'errorcode' => $response->get_errorcode(),
            'errormessage' => $response->get_errormessage() !== '' ? $response->get_errormessage() : null,
            'timecreated' => time(), 'timecompleted' => time(), 'actionid' => $actionid, 'courseid' => -1,
            'model' => $data['model'] ?? 'mistral-small', 'prompttokens' => $data['prompttokens'] ?? null,
            'completiontokens' => $data['completiontokens'] ?? null,
        ]);

        return $actionid;
    }

    /**
     * What a call that the provider answered with an error leaves in the core, for a person, tied to the context of the site.
     *
     * The message is the provider's own, as the core stores it (manager::store_action_result() writes it unchanged).
     *
     * @param \stdClass $user
     * @return int The id of the stored action.
     */
    private function store_failed(\stdClass $user): int {
        $action = new \core_ai\aiactions\generate_text(ai_assistant::core_context()->id, (int) $user->id, self::SITUATION);
        $response = new \core_ai\aiactions\responses\response_generate_text(
            false,
            429,
            'rate_limit_exceeded',
            'Rate limit reached for the model: too many requests in a minute.'
        );

        return $this->store_like_the_core($action, $response);
    }

    /**
     * What another component of the site stored for a person through the AI subsystem, tied to the context of the site.
     *
     * @param \stdClass $user
     * @return int The id of the stored action.
     */
    private function store_elsewhere(\stdClass $user): int {
        $action = new \core_ai\aiactions\generate_text(ai_assistant::core_context()->id, (int) $user->id, self::ELSEWHERE);
        $response = new \core_ai\aiactions\responses\response_generate_text(true);
        $response->set_response_data(['generatedcontent' => 'Réponse affichée dans cet éditeur.']);

        return $this->store_like_the_core($action, $response);
    }

    /**
     * Delete an account the way an administrator does.
     *
     * @param \stdClass $user
     */
    private function delete_account(\stdClass $user): void {
        global $DB;

        $this->setAdminUser();
        delete_user($DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST));
    }

    /**
     * Approve the deletion request Moodle filed for the account, and let the task that processes it run.
     *
     * The method of tests/observer_test.php for the signature of a deleted manager: the request is read where Moodle files it, then
     * approved as the data protection officer would, then processed through the privacy provider of every component.
     *
     * @param \stdClass $user The deleted account.
     */
    private function approve_the_request_filed_for(\stdClass $user): void {
        $requests = \tool_dataprivacy\api::get_data_requests(
            (int) $user->id,
            [\tool_dataprivacy\api::DATAREQUEST_STATUS_AWAITING_APPROVAL],
            [\tool_dataprivacy\api::DATAREQUEST_TYPE_DELETE]
        );
        $this->assertCount(1, $requests, 'Moodle files a deletion request when an account is deleted.');

        $this->setAdminUser();
        ob_start();
        \tool_dataprivacy\api::approve_data_request((int) reset($requests)->get('id'));
        $this->runAdhocTasks('\tool_dataprivacy\task\process_data_request_task');
        ob_end_clean();
    }

    /**
     * The text and the answer of a stored action, as the core holds them.
     *
     * @param int $actionid
     * @return \stdClass
     */
    private function action_row(int $actionid): \stdClass {
        global $DB;

        return $DB->get_record('ai_action_generate_text', ['id' => $actionid], '*', MUST_EXIST);
    }

    /**
     * An account that called the aid a minute ago keeps its texts in the core when it is deleted, and loses them only when the
     * request Moodle files is approved.
     *
     * On the code before this decision the observer emptied them at once, because the plugin's register held a call of the last
     * two days.
     */
    public function test_an_account_that_just_used_the_aid_keeps_its_texts_until_moodles_request_is_approved(): void {
        global $DB;
        $user = $this->capable_user();
        $this->setUser($user);

        $this->assistant()->reformulate((int) $user->id, self::SITUATION);
        $elsewhere = $this->store_elsewhere($user);
        $actionid = $this->stored[0];

        // The premise: the plugin's register knows the call, and the core holds the text the plugin sent, instruction included.
        $this->assertSame(1, $DB->count_records('tool_vae_aicall', ['userid' => $user->id]));
        $this->assertStringContainsString(self::SITUATION, $this->action_row($actionid)->prompt);
        $this->assertStringContainsString('Reformule', $this->action_row($actionid)->prompt, 'The instruction travels with it.');
        $this->assertSame(self::ANSWER, $this->action_row($actionid)->generatedcontent);
        // The core finds it through the context of the site, for as long as the user id is known: that is what the request uses.
        $contexts = \core_ai\privacy\provider::get_contexts_for_userid((int) $user->id)->get_contextids();
        $this->assertContains((int) ai_assistant::core_context()->id, array_map('intval', $contexts));

        $this->delete_account($user);

        // The plugin has erased nothing: not the text, not the answer, not the acceptance of the policy, not the register.
        $row = $this->action_row($actionid);
        $this->assertStringContainsString(self::SITUATION, $row->prompt, 'The plugin emptied the text with the account.');
        $this->assertSame(self::ANSWER, $row->generatedcontent, 'The plugin emptied the answer with the account.');
        $this->assertTrue($DB->record_exists('ai_policy_register', ['userid' => $user->id]), 'The acceptance was removed.');
        $this->assertTrue($DB->record_exists('ai_action_register', ['actionid' => $actionid, 'actionname' => 'generate_text']));
        // What another component wrote for the same person is not touched either: the plugin does not choose among them.
        $this->assertSame(self::ELSEWHERE, $this->action_row($elsewhere)->prompt, 'The plugin emptied another component\'s text.');
        // The register of calls of the plugin goes with the account, as it always did: it is not the plugin's erasure of the core.
        $this->assertSame(0, $DB->count_records('tool_vae_aicall', ['userid' => $user->id]));

        $this->approve_the_request_filed_for($user);

        $row = $this->action_row($actionid);
        $this->assertSame('', $row->prompt, 'The approved request left the text.');
        $this->assertSame('', $row->generatedcontent, 'The approved request left the answer.');
        $this->assertFalse($DB->record_exists('ai_policy_register', ['userid' => $user->id]), 'The acceptance outlived it.');
        // The core keeps a line of its register after the erasure, and the screen says what is on it: the account number, the
        // date, the provider, the model, the token counts and the success.
        $register = $DB->get_record(
            'ai_action_register',
            ['actionid' => $actionid, 'actionname' => 'generate_text'],
            '*',
            MUST_EXIST
        );
        $this->assertSame('aiprovider_ollama', $register->provider);
        $this->assertSame('mistral-small', $register->model);
        $this->assertSame((int) $user->id, (int) $register->userid, 'The line is no longer tied to the account number.');
        $this->assertSame(120, (int) $register->prompttokens);
        $this->assertSame(40, (int) $register->completiontokens);
        $this->assertSame(1, (int) $register->success);
        $this->assertGreaterThan(0, (int) $register->timecreated);
        // And the account record keeps the name the number leads to: the deletion of an account does not blank it.
        $this->assertNotSame('', $DB->get_field('user', 'lastname', ['id' => $user->id], MUST_EXIST));
    }

    /**
     * After a failure the line of the register keeps the provider's own message, and the erasure leaves it: the screen says so.
     *
     * The text sent is emptied by the approved request; the register line of the same call is not touched, and holds the
     * account number, the code and the message the provider answered with.
     */
    public function test_after_a_failed_call_the_register_keeps_the_providers_message_through_the_erasure(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        \core_ai\manager::user_policy_accepted((int) $user->id, ai_assistant::core_context()->id);
        $actionid = $this->store_failed($user);
        $this->assertStringContainsString(self::SITUATION, $this->action_row($actionid)->prompt, 'The premise: the text is held.');

        $this->delete_account($user);
        $this->approve_the_request_filed_for($user);

        $this->assertSame('', $this->action_row($actionid)->prompt, 'The approved request left the text.');
        $register = $DB->get_record(
            'ai_action_register',
            ['actionid' => $actionid, 'actionname' => 'generate_text'],
            '*',
            MUST_EXIST
        );
        $this->assertSame((int) $user->id, (int) $register->userid);
        $this->assertSame(0, (int) $register->success);
        $this->assertSame(429, (int) $register->errorcode);
        $this->assertSame('Rate limit reached for the model: too many requests in a minute.', $register->errormessage);
        $this->assertNull($register->prompttokens, 'A failed call has no token count to keep.');
    }

    /**
     * The hole the screen is there to name: on a site that defined the purpose and the category of the site in its data
     * registry, the approved request is declared done and the texts are still there.
     *
     * The core keeps the context of the site out of the contexts an approved deletion request reaches as soon as the registry is
     * defined (it treats it as protected, since it is neither in a course nor in a user), so core_ai's privacy provider is never
     * asked to empty the texts the aid tied to that context. Nothing in the plugin works around it. This test does not say it is
     * right: it says it is so, and that the screen then has no promise of an erasure to make.
     */
    public function test_with_the_registry_defined_the_approved_request_is_done_and_the_texts_are_still_there(): void {
        global $DB;
        $user = $this->capable_user();
        $this->setUser($user);
        $this->assistant()->reformulate((int) $user->id, self::SITUATION);
        $actionid = $this->stored[0];
        $this->define_the_registry();
        $this->assertTrue(\tool_dataprivacy\data_registry::defaults_set(), 'The premise: the registry of the site is defined.');
        $this->assertFalse(ai_assistant::moodle_erases_the_texts(), 'The premise: the plugin knows the core will not empty them.');

        $this->delete_account($user);
        $this->approve_the_request_filed_for($user);

        $status = $DB->get_field(
            'tool_dataprivacy_request',
            'status',
            ['userid' => $user->id, 'type' => \tool_dataprivacy\api::DATAREQUEST_TYPE_DELETE],
            MUST_EXIST
        );
        $this->assertSame(
            \tool_dataprivacy\api::DATAREQUEST_STATUS_DELETED,
            (int) $status,
            'The request is declared done.'
        );
        $row = $this->action_row($actionid);
        $this->assertStringContainsString(self::SITUATION, $row->prompt, 'The core emptied the text: the hole is closed.');
        $this->assertSame(self::ANSWER, $row->generatedcontent, 'The core emptied the answer: the hole is closed.');
        $this->assertTrue($DB->record_exists('ai_policy_register', ['userid' => $user->id]), 'The core removed the acceptance.');
    }

    /**
     * An account that never called the aid is treated the same way: nothing before the approval, the emptying at the approval.
     *
     * The whole chain - the account deleted, the request filed, approved, processed, the texts emptied - had no test for an
     * account without a call. The texts here are another component's, which the core ties to the context of the site: the case
     * the audit of 26 October 2026 protected from the plugin, and that the request now settles with the others.
     */
    public function test_an_account_that_never_used_the_aid_is_treated_the_same_way(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        \core_ai\manager::user_policy_accepted((int) $user->id, ai_assistant::core_context()->id);
        $elsewhere = $this->store_elsewhere($user);
        $this->assertSame(0, $DB->count_records('tool_vae_aicall', ['userid' => $user->id]), 'The premise: no call.');

        $this->delete_account($user);

        $this->assertSame(self::ELSEWHERE, $this->action_row($elsewhere)->prompt, 'The plugin emptied what it did not produce.');
        $this->assertTrue($DB->record_exists('ai_policy_register', ['userid' => $user->id]));

        $this->approve_the_request_filed_for($user);

        $this->assertSame('', $this->action_row($elsewhere)->prompt, 'The approved request left the text.');
        $this->assertFalse($DB->record_exists('ai_policy_register', ['userid' => $user->id]));
        $this->assertTrue($DB->record_exists('ai_action_register', ['actionid' => $elsewhere, 'actionname' => 'generate_text']));
    }

    /**
     * The deletion of an account touches nobody else's texts.
     *
     * Two people have texts in the core, tied to the same context. The request is the first person's: it empties theirs.
     */
    public function test_the_approved_request_empties_the_texts_of_its_account_and_of_no_one_else(): void {
        $first = $this->capable_user();
        $second = $this->capable_user();
        $this->setUser($first);
        $this->assistant()->reformulate((int) $first->id, self::SITUATION);
        $this->setUser($second);
        $this->assistant()->reformulate((int) $second->id, self::SITUATION);
        [$ofthefirst, $ofthesecond] = $this->stored;

        $this->delete_account($first);
        $this->assertStringContainsString(self::SITUATION, $this->action_row($ofthefirst)->prompt, 'Emptied before the request.');
        $this->assertStringContainsString(self::SITUATION, $this->action_row($ofthesecond)->prompt);

        $this->approve_the_request_filed_for($first);

        $this->assertSame('', $this->action_row($ofthefirst)->prompt);
        $this->assertStringContainsString(self::SITUATION, $this->action_row($ofthesecond)->prompt, 'Another lost their text.');
        $this->assertSame(self::ANSWER, $this->action_row($ofthesecond)->generatedcontent);
    }

    /**
     * What the confirmation screen prints for a user.
     *
     * @return string HTML.
     */
    private function confirmation(): string {
        $this->setUser($this->getDataGenerator()->create_user());
        $params = ['source' => 'section', 'doctype' => 'faisabilite', 'section' => 'objectifs', 'slot' => 0];

        return ai_screens::confirm(
            $params,
            'Mon texte.',
            'Fournisseur',
            new \moodle_url('/admin/tool/vae/section.php', ['section' => 'objectifs']),
            'Retour'
        );
    }

    /**
     * The confirmation says where the texts stay and until when: the truth, in the words of the decision, on a site where
     * Moodle does empty them (requests filed on their own, registry of the site not defined: the state of setUp()).
     *
     * The pieces "until your data is erased" and "The use is logged" are the ones tests/behat/ai.feature reads on the real screen.
     */
    public function test_the_confirmation_says_that_moodle_keeps_the_texts_until_its_request_is_approved(): void {
        $html = $this->confirmation();

        $this->assertStringContainsString('are kept by Moodle in its own tables', $html);
        $this->assertStringContainsString('until your data is erased', $html);
        $this->assertStringContainsString('after your account is deleted', $html);
        $this->assertStringContainsString('once the deletion request that Moodle then files has been approved', $html);
        $this->assertStringContainsString(self::REGISTER, $html);
        $this->assertStringContainsString('The use is logged (the log holds no text).', $html);
        $this->assertStringNotContainsString('does not erase them by itself', $html);
        // The two promises that were not true: an erasure on the person's request that only a whole-account deletion gives, and
        // an erasure at once for whoever used the aid in the last two days.
        $this->assertStringNotContainsString('on your request', $html);
        $this->assertStringNotContainsString('at once', $html);
        $this->assertStringNotContainsString('last two days', $html);
    }

    /**
     * The states of the two conditions, and what the core does with the texts in each.
     *
     * Moodle empties them only when it files the request on its own AND the registry of the site does not define both the
     * purpose and the category of the site: a registry with one of the two is not a defined one for the core
     * (data_registry::defaults_set() asks for both).
     *
     * @return array[] Each: the setting automaticdeletionrequests, the purpose set, the category set, whether Moodle empties.
     */
    public static function what_moodle_does(): array {
        return [
            'requests on their own, registry not defined' => [1, false, false, true],
            'no request on its own, registry not defined' => [0, false, false, false],
            'requests on their own, registry defined' => [1, true, true, false],
            'no request on its own, registry defined' => [0, true, true, false],
            'requests on their own, only the purpose' => [1, true, false, true],
            'requests on their own, only the category' => [1, false, true, true],
        ];
    }

    /**
     * The plugin knows whether Moodle will empty the texts, from the two things the core decides it by.
     *
     * @param int $automatic The setting automaticdeletionrequests of the data privacy tool.
     * @param bool $purpose Whether the registry defines the purpose of the site.
     * @param bool $category Whether the registry defines the category of the site.
     * @param bool $erases Whether Moodle empties the texts.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('what_moodle_does')]
    public function test_the_plugin_knows_whether_moodle_will_empty_the_texts(
        int $automatic,
        bool $purpose,
        bool $category,
        bool $erases
    ): void {
        set_config('automaticdeletionrequests', $automatic, 'tool_dataprivacy');
        if ($purpose || $category) {
            $this->define_the_registry($purpose, $category);
        }

        $this->assertSame($erases, ai_assistant::moodle_erases_the_texts());
    }

    /**
     * The confirmation promises the erasure only where Moodle does it, and says the contrary where it does not.
     *
     * Where the site does not file the request, or has defined its registry, the sentence "once the deletion request that
     * Moodle then files has been approved" would be false: nothing is filed, or filed and approved without effect. The person
     * is told that Moodle does not empty the texts by itself, and whom to ask.
     *
     * @param int $automatic The setting automaticdeletionrequests of the data privacy tool.
     * @param bool $purpose Whether the registry defines the purpose of the site.
     * @param bool $category Whether the registry defines the category of the site.
     * @param bool $erases Whether Moodle empties the texts.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('what_moodle_does')]
    public function test_the_confirmation_promises_the_erasure_only_where_moodle_does_it(
        int $automatic,
        bool $purpose,
        bool $category,
        bool $erases
    ): void {
        set_config('automaticdeletionrequests', $automatic, 'tool_dataprivacy');
        if ($purpose || $category) {
            $this->define_the_registry($purpose, $category);
        }

        $html = $this->confirmation();

        $this->assertStringContainsString('are kept by Moodle in its own tables', $html);
        $this->assertStringContainsString('The use is logged (the log holds no text).', $html);
        if ($erases) {
            $this->assertStringContainsString('once the deletion request that Moodle then files has been approved', $html);
            $this->assertStringNotContainsString('does not erase them by itself', $html);
        } else {
            $this->assertStringContainsString('On this site, Moodle does not erase them by itself', $html);
            $this->assertStringContainsString('ask the site administrator', $html);
            $this->assertStringNotContainsString('until your data is erased', $html);
            $this->assertStringNotContainsString('once the deletion request that Moodle then files has been approved', $html);
            $this->assertStringNotContainsString('a line in its register', $html);
        }
        $this->assertStringNotContainsString('on your request', $html);
        $this->assertStringNotContainsString('at once', $html);
        $this->assertStringNotContainsString('last two days', $html);
    }

    /**
     * What each string that speaks of the erasure must say, in each language, and what it must not.
     *
     * Four strings carry the promise: the confirmation the person reads (two sentences, the one where Moodle empties the texts
     * and the one where it does not), the help of the setting that opens the aid, which the administrator reads, and the
     * declaration of the privacy register of Moodle. A wording put back that no longer matches the code - an erasure at once,
     * the two days, the plugin erasing itself, an administrator not told of the registry - turns a case red.
     *
     * @return array[] Each: the language, the key, the pieces it must hold, the pieces it must not.
     */
    public static function the_words_of_the_erasure(): array {
        $must = [
            'aiconfirmlogged' => [
                'en' => [
                    'The text and the answer are kept by Moodle in its own tables until your data is erased',
                    'after your account is deleted',
                    'once the deletion request that Moodle then files has been approved',
                    self::REGISTER,
                    'The use is logged (the log holds no text).',
                ],
                'fr' => [
                    'Le texte et la réponse sont conservés par Moodle dans ses propres tables jusqu\'à l\'effacement de '
                        . 'vos données',
                    'après la suppression de votre compte',
                    'une fois approuvée la demande de suppression que Moodle crée alors',
                    'Même après l\'effacement, Moodle garde une ligne de registre : le numéro de votre compte (qui reste '
                        . 'rattaché à votre nom dans Moodle), la date, le fournisseur, le modèle, la taille de l\'échange (en '
                        . 'jetons), la réussite ou l\'échec de l\'appel et, après un échec, le message d\'erreur du fournisseur.',
                    'L\'usage est consigné au journal (qui ne contient pas le texte).',
                ],
            ],
            'aiconfirmloggednoerase' => [
                'en' => [
                    'The text and the answer are kept by Moodle in its own tables.',
                    'On this site, Moodle does not erase them by itself, not even after your account is deleted',
                    'ask the site administrator',
                    'The use is logged (the log holds no text).',
                ],
                'fr' => [
                    'Le texte et la réponse sont conservés par Moodle dans ses propres tables.',
                    'Sur ce site, Moodle ne les efface pas de lui-même, pas même après la suppression de votre compte',
                    'demandez-le à l\'administrateur du site',
                    'L\'usage est consigné au journal (qui ne contient pas le texte).',
                ],
            ],
            'aiopen_desc' => [
                'en' => [
                    'erases none of them itself',
                    'Create automatic data deletion requests',
                    'Data registry',
                    'never empties',
                    'by hand',
                    'the error message of the provider',
                ],
                'fr' => [
                    'n\'en efface aucun lui-même',
                    'Créer automatiquement des demandes de suppression de données',
                    'Registre des données',
                    'ne vide jamais',
                    'à la main',
                    'le message d\'erreur du fournisseur',
                ],
            ],
            'privacy:metadata:core_ai' => [
                'en' => [
                    'erases none of them itself',
                    'data registry',
                    'never empties',
                    'by hand',
                    'the error message of the provider',
                ],
                'fr' => [
                    'n\'en efface aucun lui-même',
                    'registre des données',
                    'ne vide jamais',
                    'à la main',
                    'le message d\'erreur du fournisseur',
                ],
            ],
        ];
        // The promises that were not true, and the word the project does not use in French.
        $mustnot = [
            'en' => ['last two days', 'at once', 'on your request', 'straight away'],
            'fr' => ['deux derniers jours', 'deux jours', 'tout de suite', 'sur votre demande', 'candidat'],
        ];
        // The sentence for a site where Moodle does not empty the texts promises no erasure.
        $nopromise = [
            'en' => ['until your data is erased', 'once the deletion request that Moodle then files has been approved'],
            'fr' => ['jusqu\'à l\'effacement de vos données', 'une fois approuvée la demande de suppression que Moodle crée alors'],
        ];

        $cases = [];
        foreach ($must as $key => $bylanguage) {
            foreach ($bylanguage as $lang => $pieces) {
                $forbidden = $key === 'aiconfirmloggednoerase' ? array_merge($mustnot[$lang], $nopromise[$lang]) : $mustnot[$lang];
                $cases["$lang $key"] = [$lang, $key, $pieces, $forbidden];
            }
        }

        return $cases;
    }

    /**
     * Each string that speaks of the erasure says what the code does, in English and in French.
     *
     * Read from the plugin's own files: the French pack is not installed in the test site, and the string manager would answer
     * in English whatever language is asked. The rendered English screen is read by the tests above and by
     * tests/behat/ai.feature, so that a screen that stopped printing the string would not pass on the file alone.
     *
     * @param string $lang en or fr.
     * @param string $key The string.
     * @param string[] $pieces What it must hold.
     * @param string[] $forbidden What it must not.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('the_words_of_the_erasure')]
    public function test_the_strings_that_speak_of_the_erasure_say_what_the_code_does(
        string $lang,
        string $key,
        array $pieces,
        array $forbidden
    ): void {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

        $this->assertArrayHasKey($key, $string, "lang/$lang has no string $key.");
        foreach ($pieces as $piece) {
            $this->assertStringContainsStringIgnoringCase($piece, $string[$key], "lang/$lang $key no longer says: $piece");
        }
        foreach ($forbidden as $piece) {
            $this->assertStringNotContainsStringIgnoringCase($piece, $string[$key], "lang/$lang $key says again: $piece");
        }
    }
}
