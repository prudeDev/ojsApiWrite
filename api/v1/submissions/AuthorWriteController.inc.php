<?php

/**
 * @file plugins/generic/issueApiWrite/api/v1/submissions/AuthorWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AuthorWriteController
 * @ingroup plugins_generic_issueApiWrite
 *
 * @brief CRUD for the authors (contributors) of a publication.
 *        Routes are injected into the core PKPSubmissionHandler.
 *
 *        Authorization: manager role + context is enforced by the core
 *        handler; each method additionally checks that the publication belongs
 *        to a submission in the current journal.
 */

import('classes.core.Services');

// ORCID public API — readable without OAuth, unlike the member API.
define('ISSUEAPI_ORCID_PUBLIC_API', 'https://pub.orcid.org/');
define('ISSUEAPI_ORCID_PUBLIC_API_SANDBOX', 'https://pub.sandbox.orcid.org/');
define('ISSUEAPI_ORCID_URL', 'https://orcid.org/');
define('ISSUEAPI_ORCID_URL_SANDBOX', 'https://sandbox.orcid.org/');

class AuthorWriteController {

	/**
	 * GET /submissions/{submissionId}/publications/{publicationId}/authors
	 */
	public function getAuthors($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$items = array();
		$iterator = Services::get('author')->getMany(array('publicationIds' => array($publication->getId())));
		foreach ($iterator as $author) {
			$items[] = Services::get('author')->getFullProperties($author, array(
				'request' => $request,
				'slimRequest' => $slimRequest,
			));
		}

		return $response->withJson(array('items' => $items, 'itemsMax' => count($items)), 200);
	}

	/**
	 * POST /submissions/{submissionId}/publications/{publicationId}/authors
	 */
	public function addAuthor($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = $this->readParams($slimRequest, $context);
		$params['publicationId'] = $publication->getId();
		if (empty($params['userGroupId'])) {
			$params['userGroupId'] = $this->getDefaultAuthorUserGroupId($context->getId());
		}

		// Enrich from the author's public ORCID record before storing.
		$orcidError = $this->applyOrcidProfile($params, $context);
		if ($orcidError) {
			return $response->withStatus(400)->withJson(array('orcid' => array($orcidError)));
		}

		$errors = Services::get('author')->validate(
			VALIDATE_ACTION_ADD, $params,
			$context->getSupportedSubmissionLocales(), $context->getPrimaryLocale()
		);
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$authorDao = DAORegistry::getDAO('AuthorDAO'); /* @var $authorDao AuthorDAO */
		$author = $authorDao->newDataObject();
		$author->setAllData($params);
		$author = Services::get('author')->add($author, $request);

		return $response->withJson($this->props($author, $request, $slimRequest), 201);
	}

	/**
	 * PUT /submissions/{submissionId}/publications/{publicationId}/authors/{authorId}
	 */
	public function editAuthor($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$author = Services::get('author')->get((int) $args['authorId']);
		if (!$author || $author->getData('publicationId') != $publication->getId()) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = $this->readParams($slimRequest, $context);
		// These are fixed by the route; never let the body move an author.
		unset($params['publicationId'], $params['id']);

		$orcidError = $this->applyOrcidProfile($params, $context);
		if ($orcidError) {
			return $response->withStatus(400)->withJson(array('orcid' => array($orcidError)));
		}

		$errors = Services::get('author')->validate(
			VALIDATE_ACTION_EDIT, $params,
			$context->getSupportedSubmissionLocales(), $context->getPrimaryLocale()
		);
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$author = Services::get('author')->edit($author, $params, $request);

		return $response->withJson($this->props($author, $request, $slimRequest), 200);
	}

	/**
	 * DELETE /submissions/{submissionId}/publications/{publicationId}/authors/{authorId}
	 */
	public function deleteAuthor($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$author = Services::get('author')->get((int) $args['authorId']);
		if (!$author || $author->getData('publicationId') != $publication->getId()) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$data = $this->props($author, $request, $slimRequest);
		Services::get('author')->delete($author);

		return $response->withJson($data, 200);
	}

	//
	// ORCID enrichment
	//

	/**
	 * Look up the author's public ORCID record and fill in any field the caller
	 * did not send explicitly. Values sent in the request always win.
	 *
	 * The record is identified either by the `orcid` in the request, or — when
	 * none is sent — by searching ORCID for the author's name. A name is not a
	 * unique identifier, so the search result is only trusted when it matches
	 * exactly one record; see findOrcidByName().
	 *
	 * Pass `"fetchOrcidProfile": false` to store the ORCID iD without querying.
	 *
	 * @param array $params (by ref) author params, enriched in place
	 * @param Context $context
	 * @return string|null  an error message, or null on success
	 */
	protected function applyOrcidProfile(&$params, $context) {
		// Not a schema field; consume it before it reaches validation.
		$fetch = !array_key_exists('fetchOrcidProfile', $params) || (bool) $params['fetchOrcidProfile'];
		unset($params['fetchOrcidProfile']);

		$sandbox = $this->usesOrcidSandbox($context->getId());
		$derived = false;

		if (!empty($params['orcid'])) {
			$orcidId = $this->normalizeOrcid($params['orcid']);
			if (!$orcidId) {
				return __('plugins.generic.issueApiWrite.orcid.invalid');
			}
		} else {
			if (!$fetch) {
				return null;
			}
			// No iD to go on. An unknown or ambiguous name is not an error: the
			// author is simply stored with what the caller sent.
			$orcidId = $this->findOrcidByName($params, $sandbox);
			if (!$orcidId) {
				return null;
			}
			$derived = true;
		}

		// Always store the canonical ORCID URL, as the orcidProfile plugin does.
		$params['orcid'] = ($sandbox ? ISSUEAPI_ORCID_URL_SANDBOX : ISSUEAPI_ORCID_URL) . $orcidId;

		if (!$fetch) {
			return null;
		}

		$profile = $this->fetchOrcidProfile($orcidId, $sandbox);
		if ($profile === null) {
			if ($derived) {
				// The caller never asked for this lookup, so don't fail their
				// request over it — and don't keep an iD we couldn't confirm.
				unset($params['orcid']);
				return null;
			}
			return __('plugins.generic.issueApiWrite.orcid.fetchFailed');
		}

		$locale = $context->getPrimaryLocale();
		// Only fill what the caller left out.
		foreach (array('givenName', 'familyName', 'biography', 'affiliation') as $field) {
			if (empty($params[$field]) && !empty($profile[$field])) {
				$params[$field] = array($locale => $profile[$field]);
			}
		}
		if (empty($params['country']) && !empty($profile['country'])) {
			$params['country'] = $profile['country'];
		}

		return null;
	}

	/**
	 * Find an author's ORCID iD from their name.
	 *
	 * Names are not unique identifiers, and a wrong iD would credit the article
	 * to another researcher — a worse outcome than storing no iD at all. So this
	 * is deliberately conservative: both name parts are required, and the result
	 * is only used when the search matches exactly one record. Zero matches or
	 * any ambiguity yields null, leaving the author as the caller sent them.
	 *
	 * @return string|null  a bare ORCID iD, or null when not unambiguous
	 */
	protected function findOrcidByName($params, $sandbox) {
		$givenName = $this->localizedParam($params, 'givenName');
		$familyName = $this->localizedParam($params, 'familyName');
		// A given name alone matches far too broadly to be worth querying.
		if (!$givenName || !$familyName) {
			return null;
		}

		$query = 'given-names:"' . $this->escapeOrcidQuery($givenName) . '"'
			. ' AND family-name:"' . $this->escapeOrcidQuery($familyName) . '"';
		$base = $sandbox ? ISSUEAPI_ORCID_PUBLIC_API_SANDBOX : ISSUEAPI_ORCID_PUBLIC_API;

		try {
			$res = Application::get()->getHttpClient()->request('GET', $base . 'v3.0/expanded-search/', array(
				'headers' => array('Accept' => 'application/json'),
				'query' => array('q' => $query, 'rows' => 2),
				'http_errors' => false,
				'timeout' => 10,
			));
			if ($res->getStatusCode() != 200) {
				return null;
			}
			$results = json_decode($res->getBody(), true);
		} catch (Exception $e) {
			error_log('[issueApiWrite] ORCID name search failed: ' . $e->getMessage());
			return null;
		}

		// Anything but a single hit is too weak to attach to a publication.
		if (!isset($results['num-found']) || (int) $results['num-found'] !== 1) {
			return null;
		}
		$orcidId = $this->orcidValue($results, array('expanded-result', 0, 'orcid-id'));

		return $orcidId ? $this->normalizeOrcid($orcidId) : null;
	}

	/**
	 * Read a param that may arrive as a plain string or as a locale => value map.
	 *
	 * @return string|null
	 */
	protected function localizedParam($params, $field) {
		if (!isset($params[$field])) {
			return null;
		}
		$value = $params[$field];
		if (is_array($value)) {
			// Any locale will do: a name is a name in all of them.
			foreach ($value as $localized) {
				if (is_string($localized) && trim($localized) !== '') {
					return trim($localized);
				}
			}
			return null;
		}
		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}

	/**
	 * Escape a value for use inside a quoted ORCID (Solr) query term.
	 */
	protected function escapeOrcidQuery($value) {
		return str_replace(array('\\', '"'), array('\\\\', '\\"'), $value);
	}

	/**
	 * Extract the bare 16-digit ORCID iD from either the raw id or a full URL.
	 *
	 * @return string|null
	 */
	protected function normalizeOrcid($value) {
		if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/i', (string) $value, $m)) {
			return strtoupper($m[1]);
		}
		return null;
	}

	/**
	 * Whether the journal's orcidProfile plugin points at the ORCID sandbox.
	 */
	protected function usesOrcidSandbox($contextId) {
		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		if (!$plugin || !$plugin->getEnabled($contextId)) {
			return false;
		}
		$apiPath = (string) $plugin->getSetting($contextId, 'orcidProfileAPIPath');
		return strpos($apiPath, 'sandbox') !== false;
	}

	/**
	 * Read the public ORCID record: name and biography from /person, current
	 * affiliation from /employments. The public API needs no OAuth token.
	 *
	 * @return array|null  null when the record can't be read
	 */
	protected function fetchOrcidProfile($orcidId, $sandbox) {
		$base = $sandbox ? ISSUEAPI_ORCID_PUBLIC_API_SANDBOX : ISSUEAPI_ORCID_PUBLIC_API;
		$httpClient = Application::get()->getHttpClient();
		$options = array(
			'headers' => array('Accept' => 'application/json'),
			'http_errors' => false,
			'timeout' => 10,
		);

		try {
			$res = $httpClient->request('GET', $base . 'v3.0/' . urlencode($orcidId) . '/person', $options);
			if ($res->getStatusCode() != 200) {
				return null;
			}
			$person = json_decode($res->getBody(), true);
		} catch (Exception $e) {
			error_log('[issueApiWrite] ORCID person lookup failed: ' . $e->getMessage());
			return null;
		}

		$profile = array(
			'givenName' => $this->orcidValue($person, array('name', 'given-names', 'value')),
			'familyName' => $this->orcidValue($person, array('name', 'family-name', 'value')),
			'biography' => $this->orcidValue($person, array('biography', 'content')),
			'country' => $this->orcidValue($person, array('addresses', 'address', 0, 'country', 'value')),
			'affiliation' => null,
		);

		// Affiliation lives in the employments record; a failure here is not fatal.
		try {
			$res = $httpClient->request('GET', $base . 'v3.0/' . urlencode($orcidId) . '/employments', $options);
			if ($res->getStatusCode() == 200) {
				$employments = json_decode($res->getBody(), true);
				$profile['affiliation'] = $this->orcidValue($employments, array(
					'affiliation-group', 0, 'summaries', 0, 'employment-summary', 'organization', 'name'
				));
			}
		} catch (Exception $e) {
			error_log('[issueApiWrite] ORCID employments lookup failed: ' . $e->getMessage());
		}

		return $profile;
	}

	/**
	 * Safely walk a nested ORCID JSON structure.
	 */
	protected function orcidValue($data, $path) {
		foreach ($path as $key) {
			if (!is_array($data) || !isset($data[$key])) {
				return null;
			}
			$data = $data[$key];
		}
		return is_string($data) || is_numeric($data) ? $data : null;
	}

	//
	// Helpers
	//

	/**
	 * Load the publication from the route and confirm it belongs to a
	 * submission in the current journal.
	 *
	 * @return Publication|null
	 */
	protected function getContextPublication($args, $request) {
		$context = $request->getContext();
		if (!$context) {
			return null;
		}
		$submission = Services::get('submission')->get((int) $args['submissionId']);
		if (!$submission || $submission->getData('contextId') != $context->getId()) {
			return null;
		}
		$publication = Services::get('publication')->get((int) $args['publicationId']);
		if (!$publication || $publication->getData('submissionId') != $submission->getId()) {
			return null;
		}
		return $publication;
	}

	/**
	 * Read author params from the request body, normalising multilingual
	 * fields: a plain string is stored under the journal's primary locale.
	 */
	protected function readParams($slimRequest, $context) {
		$params = (array) $slimRequest->getParsedBody();
		$primaryLocale = $context->getPrimaryLocale();

		$multilingual = array('givenName', 'familyName', 'preferredPublicName', 'affiliation', 'biography');
		foreach ($multilingual as $field) {
			if (isset($params[$field]) && is_string($params[$field])) {
				$params[$field] = array($primaryLocale => $params[$field]);
			}
		}

		// Cast the scalar fields the schema expects.
		if (isset($params['seq'])) $params['seq'] = (int) $params['seq'];
		if (isset($params['userGroupId'])) $params['userGroupId'] = (int) $params['userGroupId'];
		if (isset($params['includeInBrowse'])) $params['includeInBrowse'] = (bool) $params['includeInBrowse'];

		return $params;
	}

	/**
	 * Full JSON representation of an author.
	 */
	protected function props($author, $request, $slimRequest) {
		return Services::get('author')->getFullProperties($author, array(
			'request' => $request,
			'slimRequest' => $slimRequest,
		));
	}

	/**
	 * First user group in the Author role, used as a sensible default.
	 */
	protected function getDefaultAuthorUserGroupId($contextId) {
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
		$groups = $userGroupDao->getByRoleId($contextId, ROLE_ID_AUTHOR);
		$group = $groups->next();
		return $group ? $group->getId() : null;
	}
}
