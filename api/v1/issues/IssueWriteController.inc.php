<?php

/**
 * @file plugins/generic/issueApiWrite/api/v1/issues/IssueWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssueWriteController
 * @ingroup plugins_generic_issueApiWrite
 *
 * @brief Create / update / delete handlers for issues (volumes), invoked by
 *        the routes registered in IssueApiWritePlugin.
 *
 *        Authorization (journal-manager role + context access) is already
 *        enforced by the core IssueHandler::authorize() plus the role
 *        assignments we attach in the plugin, so these methods can assume the
 *        caller is an authenticated manager of the current journal.
 */

import('classes.core.Services');
import('plugins.generic.issueApiWrite.classes.DoiHelper');

class IssueWriteController {

	/**
	 * POST /issues  -- create a new issue.
	 */
	public function add($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();

		$errors = $this->validate($params, $context, null);
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
		$issue = $issueDao->newDataObject();
		$issue->setJournalId($context->getId());
		$issue->setPublished(0);
		$this->applyParams($issue, $params, $context);
		$issue->stampModified();

		$issueDao->insertObject($issue);

		// The cover filename embeds the issue id, so this must run after insert.
		$this->saveCoverImage($issue, $params, $context, $request->getUser());

		// The DOI is a pub-id setting, written once the issue has an id.
		if (DoiHelper::requested($params)) {
			DoiHelper::store($issueDao, $issue->getId(), $params['doi']);
			$issue = $issueDao->getById($issue->getId(), $context->getId());
		}

		return $response->withJson($this->fullProps($issue, $request, $slimRequest), 201);
	}

	/**
	 * PUT /issues/{issueId}  -- update an existing issue.
	 */
	public function edit($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		$issue = $this->getContextIssue($args['issueId'], $context);
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();

		$errors = $this->validate($params, $context, $issue);
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$this->applyParams($issue, $params, $context);
		$issue->stampModified();

		$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
		$issueDao->updateObject($issue);

		$this->saveCoverImage($issue, $params, $context, $request->getUser());

		if (DoiHelper::requested($params)) {
			DoiHelper::store($issueDao, $issue->getId(), $params['doi']);
			$issue = $issueDao->getById($issue->getId(), $context->getId());
		}

		return $response->withJson($this->fullProps($issue, $request, $slimRequest), 200);
	}

	/**
	 * PUT /issues/{issueId}/publish  -- publish an issue.
	 *
	 * Replicates the core IssueGridHandler::publishIssue routine (minus the
	 * interactive pub-id form, CSRF check and user notifications, which do not
	 * apply to a token-authenticated API call): marks the issue published and
	 * current, then publishes the articles assigned to it.
	 *
	 * The response carries `articlesPublished` and `articlesFailed` alongside the
	 * issue, so a caller can see which articles went out.
	 */
	public function publish($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		$issue = $this->getContextIssue($args['issueId'], $context);
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$issue->setCurrent(1);
		$issue->setPublished(1);
		if (!$issue->getDatePublished()) {
			$issue->setDatePublished(Core::getCurrentDate());
		}

		$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
		$issueDao->updateCurrent($context->getId(), $issue);

		$articles = $this->publishIssueArticles($issue, $context);

		$issue = $issueDao->getById($issue->getId(), $context->getId());
		$data = $this->fullProps($issue, $request, $slimRequest);
		$data['articlesPublished'] = $articles['published'];
		$data['articlesFailed'] = $articles['failed'];

		return $response->withJson($data, 200);
	}

	/**
	 * Publish every article of the issue that is ready to go out.
	 *
	 * Covers both QUEUED and SCHEDULED publications: articles created through
	 * this plugin are QUEUED, so publishing the issue is the single step that
	 * puts a whole number online.
	 *
	 * Must run *after* the issue is marked published: PublicationService forces a
	 * publication back to SCHEDULED while its issue is unpublished (see
	 * PublicationService::publishPublicationBefore).
	 *
	 * Each article goes through the core's validatePublish() — the same gate the
	 * editorial UI uses. That is what keeps a manuscript still in review, or one
	 * missing required metadata, from being swept online by a bulk publish. A
	 * failing article is skipped and reported rather than aborting the issue: one
	 * incomplete article should not hold back the rest of the number. Fixing it
	 * and calling the endpoint again publishes the stragglers, so the operation
	 * is safe to repeat.
	 *
	 * @return array ['published' => array, 'failed' => array]
	 */
	protected function publishIssueArticles($issue, $context) {
		import('classes.submission.Submission');
		$published = array();
		$failed = array();

		$submissionsIterator = Services::get('submission')->getMany([
			'contextId' => $issue->getJournalId(),
			'issueIds' => $issue->getId(),
		]);
		foreach ($submissionsIterator as $submission) { /** @var Submission $submission */
			foreach ((array) $submission->getData('publications') as $publication) { /** @var Publication $publication */
				if ((int) $publication->getData('issueId') !== (int) $issue->getId()) {
					continue;
				}
				$status = (int) $publication->getData('status');
				// Anything else is already published, or declined.
				if ($status !== STATUS_QUEUED && $status !== STATUS_SCHEDULED) {
					continue;
				}

				$errors = Services::get('publication')->validatePublish(
					$publication, $submission,
					$context->getSupportedSubmissionLocales(),
					$context->getPrimaryLocale()
				);
				if (!empty($errors)) {
					$failed[] = array(
						'submissionId' => (int) $submission->getId(),
						'publicationId' => (int) $publication->getId(),
						'errors' => $errors,
					);
					continue;
				}

				Services::get('publication')->publish($publication);
				$published[] = array(
					'submissionId' => (int) $submission->getId(),
					'publicationId' => (int) $publication->getId(),
				);
			}
		}

		return array('published' => $published, 'failed' => $failed);
	}

	/**
	 * DELETE /issues/{issueId}  -- delete an issue.
	 *
	 * NOTE: IssueDAO::deleteObject() cascades to the table of contents,
	 * galleys, custom orderings and pub-ids. Published articles that were
	 * assigned to this issue become unpublished. Handle with care.
	 */
	public function delete($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		$issue = $this->getContextIssue($args['issueId'], $context);
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$issueData = $this->fullProps($issue, $request, $slimRequest);

		$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
		$issueDao->deleteObject($issue);

		return $response->withJson($issueData, 200);
	}

	//
	// Issue galleys (full-issue files, e.g. a PDF)
	//

	/**
	 * GET /issues/{issueId}/galleys  -- list an issue's galleys.
	 */
	public function getGalleys($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		$issue = $this->getContextIssue($args['issueId'], $context);
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$galleyDao = DAORegistry::getDAO('IssueGalleyDAO'); /* @var $galleyDao IssueGalleyDAO */
		$items = array();
		foreach ((array) $galleyDao->getByIssueId($issue->getId()) as $galley) {
			$items[] = $this->galleyProps($galley, $issue, $request);
		}

		return $response->withJson(array('items' => $items, 'itemsMax' => count($items)), 200);
	}

	/**
	 * POST /issues/{issueId}/galleys  -- attach a galley from an uploaded file.
	 *
	 * Two-step upload: first POST the file to /api/v1/temporaryFiles to get a
	 * temporaryFileId, then send it here as `temporaryFileId`.
	 */
	public function addGalley($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();

		$issue = $this->getContextIssue($args['issueId'], $context);
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();

		// The uploaded file must exist and belong to the requesting user.
		$temporaryFileId = isset($params['temporaryFileId']) ? (int) $params['temporaryFileId'] : 0;
		if (!$temporaryFileId) {
			return $response->withStatus(400)->withJson(array('temporaryFileId' => array(__('form.fileRequired'))));
		}
		$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
		$temporaryFile = $temporaryFileDao->getTemporaryFile($temporaryFileId, $user->getId());
		if (!$temporaryFile) {
			return $response->withStatus(400)->withJson(array('temporaryFileId' => array(__('common.uploadFailed'))));
		}

		// Galley locale must be a supported form locale of the journal.
		$galleyLocale = isset($params['galleyLocale']) ? $params['galleyLocale'] : $context->getPrimaryLocale();
		if (!in_array($galleyLocale, $context->getSupportedFormLocales())) {
			return $response->withStatus(400)->withJson(array('galleyLocale' => array(__('editor.issues.galleyLocaleRequired'))));
		}

		// Move the temporary upload into the issue's file storage.
		import('classes.file.IssueFileManager');
		$issueFileManager = new IssueFileManager($issue->getId());
		$issueFile = $issueFileManager->fromTemporaryFile($temporaryFile);

		$galleyDao = DAORegistry::getDAO('IssueGalleyDAO'); /* @var $galleyDao IssueGalleyDAO */
		$galley = $galleyDao->newDataObject();
		$galley->setIssueId($issue->getId());
		$galley->setFileId($issueFile->getId());
		$galley->setLocale($galleyLocale);
		$galley->setLabel(!empty($params['label']) ? $params['label'] : 'PDF');
		if (!empty($params['urlPath'])) {
			$galley->setData('urlPath', $params['urlPath']);
		}
		$galleyDao->insertObject($galley);

		return $response->withJson($this->galleyProps($galley, $issue, $request), 201);
	}

	/**
	 * PUT /issues/{issueId}/galleys/{galleyId}  -- replace the file and/or edit
	 * the label of an existing galley.
	 *
	 * Send `temporaryFileId` (uploaded first to /temporaryFiles) to replace the
	 * PDF; the previous file is deleted. Send `label`/`galleyLocale` to edit
	 * those. Any field omitted is left unchanged.
	 */
	public function editGalley($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();

		$issue = $this->getContextIssue($args['issueId'], $context);
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$galleyDao = DAORegistry::getDAO('IssueGalleyDAO'); /* @var $galleyDao IssueGalleyDAO */
		$galley = $galleyDao->getById((int) $args['galleyId'], $issue->getId());
		if (!$galley) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();

		// Replace the file if a new upload was provided.
		if (!empty($params['temporaryFileId'])) {
			$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
			$temporaryFile = $temporaryFileDao->getTemporaryFile((int) $params['temporaryFileId'], $user->getId());
			if (!$temporaryFile) {
				return $response->withStatus(400)->withJson(array('temporaryFileId' => array(__('common.uploadFailed'))));
			}
			import('classes.file.IssueFileManager');
			$issueFileManager = new IssueFileManager($issue->getId());
			// Delete the previous file, then store the new one (mirrors IssueGalleyForm).
			if ($galley->getFileId()) {
				$issueFileManager->deleteById($galley->getFileId());
			}
			$issueFile = $issueFileManager->fromTemporaryFile($temporaryFile);
			$galley->setFileId($issueFile->getId());
		}

		if (array_key_exists('label', $params)) {
			$galley->setLabel($params['label']);
		}
		if (!empty($params['galleyLocale'])) {
			if (!in_array($params['galleyLocale'], $context->getSupportedFormLocales())) {
				return $response->withStatus(400)->withJson(array('galleyLocale' => array(__('editor.issues.galleyLocaleRequired'))));
			}
			$galley->setLocale($params['galleyLocale']);
		}
		if (array_key_exists('urlPath', $params)) {
			$galley->setData('urlPath', $params['urlPath']);
		}

		$galleyDao->updateObject($galley);

		return $response->withJson($this->galleyProps($galley, $issue, $request), 200);
	}

	/**
	 * DELETE /issues/{issueId}/galleys/{galleyId}  -- remove a galley and its file.
	 */
	public function deleteGalley($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		$issue = $this->getContextIssue($args['issueId'], $context);
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$galleyDao = DAORegistry::getDAO('IssueGalleyDAO'); /* @var $galleyDao IssueGalleyDAO */
		$galley = $galleyDao->getById((int) $args['galleyId'], $issue->getId());
		if (!$galley) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$data = $this->galleyProps($galley, $issue, $request);
		// deleteObject cascades to the stored file and galley settings.
		$galleyDao->deleteObject($galley);

		return $response->withJson($data, 200);
	}

	//
	// Helpers
	//

	/**
	 * Build a JSON payload for an issue galley.
	 */
	protected function galleyProps($galley, $issue, $request) {
		$dispatcher = $request->getDispatcher();
		return array(
			'id' => (int) $galley->getId(),
			'issueId' => (int) $issue->getId(),
			'fileId' => (int) $galley->getFileId(),
			'label' => $galley->getLabel(),
			'locale' => $galley->getLocale(),
			'seq' => $galley->getSequence(),
			'urlPath' => $galley->getData('urlPath'),
			'urlPublished' => $dispatcher->url(
				$request, ROUTE_PAGE, null, 'issue', 'download',
				array($issue->getId(), $galley->getId())
			),
		);
	}

	/**
	 * Load an issue by id and confirm it belongs to the current journal.
	 * Prevents editing issues from another context by guessing ids.
	 */
	protected function getContextIssue($issueId, $context) {
		if (!$context) {
			return null;
		}
		$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
		$issue = $issueDao->getById((int) $issueId, $context->getId());
		return $issue ?: null;
	}

	/**
	 * Minimal validation. Expand this to mirror classes/issue/form/IssueForm
	 * (unique volume/number/year rules, date formats, locale coverage, etc.).
	 *
	 * @return array field => message
	 */
	protected function validate($params, $context, $issue) {
		$errors = array();

		// An issue must show at least one identifying element, exactly as the
		// admin UI requires.
		$showsSomething = !empty($params['showVolume']) || !empty($params['showNumber'])
			|| !empty($params['showYear']) || !empty($params['showTitle']);
		// On update, fall back to the stored flags when the caller omits them.
		if ($issue && !array_key_exists('showVolume', $params) && !array_key_exists('showNumber', $params)
			&& !array_key_exists('showYear', $params) && !array_key_exists('showTitle', $params)) {
			$showsSomething = true;
		}
		if (!$showsSomething) {
			$errors['identification'] = array(__('editor.issues.issueIdentificationRequired'));
		}

		if (isset($params['volume']) && !ctype_digit((string) $params['volume'])) {
			$errors['volume'] = array('Volume must be a positive integer.');
		}
		if (isset($params['year']) && !ctype_digit((string) $params['year'])) {
			$errors['year'] = array('Year must be a positive integer.');
		}

		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate(
				$params['doi'], $context->getId(),
				$issue ? DoiHelper::get($issue) : null
			);
			if ($doiError) {
				$errors['doi'] = array($doiError);
			}
		}

		// Cover image: must be an uploaded temporary file of the caller and a
		// valid image format. Validated here (before insert) so we never create
		// an issue for a bad upload.
		if (!empty($params['coverImageTemporaryFileId'])) {
			$user = Application::get()->getRequest()->getUser();
			$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
			$temporaryFile = $temporaryFileDao->getTemporaryFile((int) $params['coverImageTemporaryFileId'], $user->getId());
			if (!$temporaryFile) {
				$errors['coverImageTemporaryFileId'] = array(__('common.uploadFailed'));
			} else {
				import('classes.file.PublicFileManager');
				$publicFileManager = new PublicFileManager();
				if (!$publicFileManager->getImageExtension($temporaryFile->getFileType())) {
					$errors['coverImageTemporaryFileId'] = array(__('editor.issues.invalidCoverImageFormat'));
				}
			}
		}

		return $errors;
	}

	/**
	 * Copy incoming params onto the Issue object. Only touches fields that are
	 * present in the request so PUT can be used for partial updates.
	 */
	protected function applyParams($issue, $params, $context) {
		if (array_key_exists('volume', $params))    $issue->setVolume((int) $params['volume']);
		if (array_key_exists('number', $params))    $issue->setNumber($params['number']);
		if (array_key_exists('year', $params))      $issue->setYear((int) $params['year']);
		if (array_key_exists('showVolume', $params)) $issue->setShowVolume((int) (bool) $params['showVolume']);
		if (array_key_exists('showNumber', $params)) $issue->setShowNumber((int) (bool) $params['showNumber']);
		if (array_key_exists('showYear', $params))   $issue->setShowYear((int) (bool) $params['showYear']);
		if (array_key_exists('showTitle', $params))  $issue->setShowTitle((int) (bool) $params['showTitle']);
		if (array_key_exists('accessStatus', $params)) $issue->setAccessStatus((int) $params['accessStatus']);
		if (array_key_exists('datePublished', $params)) $issue->setDatePublished($params['datePublished']);

		// Localized fields: expect { "title": { "en_US": "..." } }
		foreach (array('title' => 'setTitle', 'description' => 'setDescription') as $field => $setter) {
			if (isset($params[$field]) && is_array($params[$field])) {
				foreach ($params[$field] as $locale => $value) {
					$issue->$setter($value, $locale);
				}
			}
		}

		// Cover image alt text (localized). Accepts a string (primary locale)
		// or a { locale: value } object.
		if (isset($params['coverImageAltText'])) {
			$alt = $params['coverImageAltText'];
			if (is_string($alt)) {
				$alt = array($this->coverLocale($params, $context) => $alt);
			}
			foreach ((array) $alt as $locale => $value) {
				$issue->setCoverImageAltText($value, $locale);
			}
		}
	}

	/**
	 * Copy an uploaded temporary file into the journal's public files as the
	 * issue cover image. Mirrors IssueForm: the file is named
	 * cover_issue_{issueId}_{locale}.{ext}. No-op if no cover was sent.
	 * Validation already ran in validate().
	 */
	protected function saveCoverImage($issue, $params, $context, $user) {
		if (empty($params['coverImageTemporaryFileId'])) {
			return;
		}
		$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
		$temporaryFile = $temporaryFileDao->getTemporaryFile((int) $params['coverImageTemporaryFileId'], $user->getId());
		if (!$temporaryFile) {
			return;
		}

		$locale = $this->coverLocale($params, $context);

		import('classes.file.PublicFileManager');
		$publicFileManager = new PublicFileManager();
		$newFileName = 'cover_issue_' . $issue->getId() . '_' . $locale
			. $publicFileManager->getImageExtension($temporaryFile->getFileType());
		$publicFileManager->copyContextFile($context->getId(), $temporaryFile->getFilePath(), $newFileName);

		$issue->setCoverImage($newFileName, $locale);
		DAORegistry::getDAO('IssueDAO')->updateObject($issue);
	}

	/**
	 * Which locale the cover image / alt text applies to.
	 */
	protected function coverLocale($params, $context) {
		return !empty($params['coverImageLocale']) ? $params['coverImageLocale'] : $context->getPrimaryLocale();
	}

	/**
	 * Build the same JSON payload the read endpoints return.
	 */
	protected function fullProps($issue, $request, $slimRequest) {
		return Services::get('issue')->getFullProperties($issue, array(
			'request' => $request,
			'slimRequest' => $slimRequest,
		));
	}
}
