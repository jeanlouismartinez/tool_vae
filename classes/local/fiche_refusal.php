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

namespace tool_vae\local;

/**
 * A refusal with a name: why an export, or a record of it, was not read (T-31, 0.47.0).
 *
 * NO MOODLE IN HERE. This class is thrown by fiche_converter, which scripts/convertir-fiche-rncp.php also runs on a
 * workstation with no site: it loads this file before the converter, by its path.
 *
 * A RUNTIME EXCEPTION, SO THAT NOTHING THAT EXISTED CHANGES. The converter threw a RuntimeException with an English
 * sentence; that sentence is still the message of the refusal (it is what the script prints and what the older tests read),
 * and the refusal adds what the screen needs: a reason, and the facts of the case as plain strings and integers. The
 * translation and the escaping are done where a sentence is written for a person (fiche_messages), never here; and no text
 * of an exception from the network travels in a refusal.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_refusal extends \RuntimeException {
    /** @var string No file was chosen. */
    public const ZIP_MISSING = 'zipmissing';

    /** @var string The file did not arrive whole. */
    public const UPLOAD = 'upload';

    /** @var string Not a readable zip file. */
    public const NOT_ZIP = 'notzip';

    /** @var string The archive is bigger than an export, or holds too many files. */
    public const ZIP_TOO_BIG = 'ziptoobig';

    /** @var string A file the export must hold is not in the archive. */
    public const ENTRY_MISSING = 'entrymissing';

    /** @var string Several files could be the one to read. */
    public const ENTRY_AMBIGUOUS = 'entryambiguous';

    /** @var string A file of the archive is damaged, encrypted, too big or lies about its size. */
    public const ENTRY_UNSAFE = 'entryunsafe';

    /** @var string A file of the archive is not UTF-8. */
    public const NOT_UTF8 = 'notutf8';

    /** @var string A column the export must carry is missing. */
    public const FORMAT = 'format';

    /** @var string A file of the archive is empty. */
    public const FILE_EMPTY = 'fileempty';

    /** @var string The name of the file does not say which day the export is from. */
    public const NO_DATE = 'nodate';

    /** @var string The date of the export is in the future. */
    public const DATE_FUTURE = 'datefuture';

    /** @var string No record number was given. */
    public const NO_NUMBER = 'nonumber';

    /** @var string A record number is not one. */
    public const BAD_NUMBER = 'number';

    /** @var string Too many record numbers. */
    public const TOO_MANY = 'toomany';

    /** @var string Another import is running. */
    public const BUSY = 'busy';

    /** @var string The preview is gone or is not this person's. */
    public const EXPIRED = 'expired';

    /** @var string The plan is too large to be kept. */
    public const PLAN_TOO_LARGE = 'plan';

    /** @var string Something nobody foresaw. */
    public const UNEXPECTED = 'unexpected';

    /** @var string The server could not reach data.gouv.fr. */
    public const FETCH_NETWORK = 'fetchnetwork';

    /** @var string data.gouv.fr did not answer in time. */
    public const FETCH_TIMEOUT = 'fetchtimeout';

    /** @var string The certificate of data.gouv.fr could not be checked. */
    public const FETCH_TLS = 'fetchtls';

    /** @var string The site blocks the address of data.gouv.fr. */
    public const FETCH_BLOCKED = 'fetchblocked';

    /** @var string data.gouv.fr answered with an error. */
    public const FETCH_HTTP = 'fetchhttp';

    /** @var string data.gouv.fr answered otherwise than expected. */
    public const FETCH_API = 'fetchapi';

    /** @var string data.gouv.fr offers no export that is recognised. */
    public const FETCH_NONE = 'fetchnone';

    /** @var string The dataset no longer states the licence the plugin writes. */
    public const FETCH_LICENCE = 'fetchlicence';

    /** @var string A redirection leaves the two hosts the plugin goes to. */
    public const FETCH_REDIRECT = 'fetchredirect';

    /** @var string The file announced is bigger than the plugin accepts. */
    public const FETCH_TOO_BIG = 'fetchtoobig';

    /** @var string The file received is not the one announced. */
    public const FETCH_DIGEST = 'fetchdigest';

    /** @var string A record is not in the export. */
    public const NOT_FOUND = 'notfound';

    /** @var string A record that is not in force (or whose state is not said) has no bloc in the export. */
    public const NO_BLOC = 'nobloc';

    /** @var string A record that IS in force has no bloc in the export: 236 RNCP records and every RS record, on 19 October 2026. */
    public const NO_BLOC_ACTIVE = 'noblocactive';

    /** @var string A bloc code is not of the expected form. */
    public const BAD_BLOC = 'blocform';

    /** @var string A bloc has no label. */
    public const NO_LABEL = 'nolabel';

    /** @var string The export says the same thing twice (a number twice in the standard file, a rank twice for a record). */
    public const DUPLICATE = 'duplicate';

    /** @var string A record has more blocs in the export than any record has. */
    public const TOO_MANY_BLOCS = 'blocsmany';

    /** @var string A text holds a control character. */
    public const CONTROL = 'control';

    /** @var string A text is longer than a label can be. */
    public const TOO_LONG = 'labelsize';

    /** @var string A message already written, and already safe, by the plugin or by the core (parameter html). */
    public const MOODLE = 'moodle';

    /** @var string The reason. */
    private string $reason;

    /** @var array The facts of the case: strings and integers only. */
    private array $params;

    /**
     * A refusal.
     *
     * @param string $reason One of the constants.
     * @param array $params Strings and integers only: the number, the codes, the name of the file, the host...
     * @param string $message The English sentence; "Refused: <reason>" when empty.
     * @throws \InvalidArgumentException A parameter is neither a string nor an integer.
     */
    public function __construct(string $reason, array $params = [], string $message = '') {
        foreach ($params as $name => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException("The parameter $name of a refusal must be a string or an integer.");
            }
        }
        parent::__construct($message === '' ? 'Refused: ' . $reason : $message);
        $this->reason = $reason;
        $this->params = $params;
    }

    /**
     * The reason.
     *
     * @return string
     */
    public function reason(): string {
        return $this->reason;
    }

    /**
     * The facts of the case.
     *
     * @return array
     */
    public function params(): array {
        return $this->params;
    }

    /**
     * The identifier of the sentence that tells it to a person.
     *
     * @return string
     */
    public function lang_key(): string {
        return 'fcimporterror' . $this->reason;
    }

    /**
     * As data, to be kept in a plan.
     *
     * @return array reason and params.
     */
    public function to_array(): array {
        return ['reason' => $this->reason, 'params' => $this->params];
    }

    /**
     * A refusal kept in a plan, back.
     *
     * @param array $data What to_array() gave.
     * @return self
     */
    public static function from_array(array $data): self {
        return new self((string) ($data['reason'] ?? self::UNEXPECTED), (array) ($data['params'] ?? []));
    }
}
