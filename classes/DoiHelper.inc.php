<?php

/**
 * @file plugins/generic/issueApiWrite/classes/DoiHelper.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiHelper
 * @ingroup plugins_generic_issueApiWrite
 *
 * @brief Store and validate DOIs on issues, publications and article galleys.
 *
 *        In OJS a DOI is a "pub-id" (`pub-id::doi`) written through the DAO's
 *        changePubId()/deletePubId(). Note that OJS does NOT support DOIs on
 *        issue galleys (see DOIPubIdPlugin).
 */

class DoiHelper {

	/** Object types that accept a DOI, mapped to the ASSOC_TYPE used to
	 *  exclude the object itself from the duplicate search. */
	const TYPE_ISSUE = 'issue';
	const TYPE_PUBLICATION = 'publication';
	const TYPE_GALLEY = 'galley';

	/**
	 * Was a `doi` key sent at all? (Distinguishes "not sent" from "clear it".)
	 */
	public static function requested($params) {
		return array_key_exists('doi', $params);
	}

	/**
	 * Validate a DOI before writing it.
	 *
	 * @param string|null $doi
	 * @param int $contextId
	 * @param string|null $currentDoi the object's existing DOI, so re-sending it
	 *   unchanged on an edit is not flagged as a duplicate
	 * @return string|null  error message, or null when valid
	 */
	public static function validate($doi, $contextId, $currentDoi = null) {
		$doi = self::normalize($doi);
		if ($doi === null) {
			return null; // clearing the DOI is always allowed
		}
		// A DOI always starts with "10." followed by the registrant prefix.
		if (!preg_match('#^10\.\d{4,9}/\S+$#', $doi)) {
			return __('plugins.generic.issueApiWrite.doi.invalid');
		}
		if ($doi === self::normalize($currentDoi)) {
			return null; // unchanged
		}
		// Check the three object types OJS assigns DOIs to. We query their DAOs
		// directly: JournalDAO::anyPubIdExists() cannot be used here because it
		// calls SubmissionDAO::pubIdExists(), which no longer exists in 3.3.
		$daos = array(
			DAORegistry::getDAO('IssueDAO'),
			DAORegistry::getDAO('PublicationDAO'),
			DAORegistry::getDAO('ArticleGalleyDAO'),
		);
		foreach ($daos as $dao) {
			if ($dao->pubIdExists('doi', $doi, null, $contextId)) {
				return __('plugins.generic.issueApiWrite.doi.duplicate');
			}
		}
		return null;
	}

	/**
	 * Write (or clear) the DOI of an object.
	 *
	 * @param DAO $dao a DAO implementing changePubId()/deletePubId()
	 * @param int $objectId
	 * @param string|null $doi  empty/null clears the DOI
	 */
	public static function store($dao, $objectId, $doi) {
		$doi = self::normalize($doi);
		if ($doi === null) {
			$dao->deletePubId((int) $objectId, 'doi');
		} else {
			$dao->changePubId((int) $objectId, 'doi', $doi);
		}
	}

	/**
	 * Read the stored DOI of an object, or null.
	 */
	public static function get($object) {
		$doi = $object->getData('pub-id::doi');
		return $doi !== '' ? $doi : null;
	}

	/**
	 * Trim, and treat empty string as "no DOI".
	 */
	protected static function normalize($doi) {
		if ($doi === null) {
			return null;
		}
		$doi = trim((string) $doi);
		return $doi === '' ? null : $doi;
	}
}
