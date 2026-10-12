<?php

/**
 * @file plugins/generic/issueApiWrite/api/v1/submissions/ArticleGalleyController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleGalleyController
 * @ingroup plugins_generic_issueApiWrite
 *
 * @brief CRUD for the galleys (e.g. the PDF) of an article's publication.
 *        Routes are injected into the core PKPSubmissionHandler.
 *
 *        The core submission-files API can't be used for this in a headless
 *        setup: it requires an editorial stage assignment the API user doesn't
 *        have. Here authorization is manager-role + our own context check.
 */

import('classes.core.Services');
import('lib.pkp.classes.submission.SubmissionFile'); // SUBMISSION_FILE_* constants
import('plugins.generic.issueApiWrite.classes.DoiHelper');

class ArticleGalleyController {

	/**
	 * GET /submissions/{submissionId}/publications/{publicationId}/galleys
	 */
	public function listArticleGalleys($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $galleyDao ArticleGalleyDAO */
		$items = array();
		foreach ((array) $galleyDao->getByPublicationId($publication->getId())->toArray() as $galley) {
			$items[] = $this->props($galley);
		}

		return $response->withJson(array('items' => $items, 'itemsMax' => count($items)), 200);
	}

	/**
	 * POST /submissions/{submissionId}/publications/{publicationId}/galleys
	 *
	 * Body: temporaryFileId (required, the PDF), label, galleyLocale.
	 */
	public function addArticleGalley($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();

		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();
		$temporaryFile = $this->getUploadedFile($params, $user);
		if (!$temporaryFile) {
			return $response->withStatus(400)->withJson(array('temporaryFileId' => array(__('form.fileRequired'))));
		}

		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId());
			if ($doiError) {
				return $response->withStatus(400)->withJson(array('doi' => array($doiError)));
			}
		}

		$submission = Services::get('submission')->get($publication->getData('submissionId'));
		$locale = !empty($params['galleyLocale']) ? $params['galleyLocale'] : $context->getPrimaryLocale();

		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $galleyDao ArticleGalleyDAO */
		$galley = $galleyDao->newDataObject();
		$galley->setData('publicationId', $publication->getId());
		$galley->setLabel(!empty($params['label']) ? $params['label'] : 'PDF');
		$galley->setLocale($locale);
		$galleyId = $galleyDao->insertObject($galley);

		$this->storeFile($temporaryFile, $submission, $galleyId, $locale, $context, $user);

		if (DoiHelper::requested($params)) {
			DoiHelper::store($galleyDao, $galleyId, $params['doi']);
		}

		return $response->withJson($this->props($galleyDao->getById($galleyId, $publication->getId())), 201);
	}

	/**
	 * PUT /submissions/{submissionId}/publications/{publicationId}/galleys/{galleyId}
	 *
	 * Send temporaryFileId to replace the PDF (old file removed); send label to
	 * rename. Omitted fields are unchanged.
	 */
	public function editArticleGalley($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();

		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $galleyDao ArticleGalleyDAO */
		$galley = $galleyDao->getById((int) $args['galleyId'], $publication->getId());
		if (!$galley) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();

		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId(), DoiHelper::get($galley));
			if ($doiError) {
				return $response->withStatus(400)->withJson(array('doi' => array($doiError)));
			}
		}

		if (!empty($params['temporaryFileId'])) {
			$temporaryFile = $this->getUploadedFile($params, $user);
			if (!$temporaryFile) {
				return $response->withStatus(400)->withJson(array('temporaryFileId' => array(__('common.uploadFailed'))));
			}
			$submission = Services::get('submission')->get($publication->getData('submissionId'));
			$oldFileId = $galley->getFileId();
			// Storing the new file re-points the galley (SubmissionFileDAO); then
			// remove the previous submission file.
			$this->storeFile($temporaryFile, $submission, $galley->getId(), $galley->getLocale(), $context, $user);
			if ($oldFileId) {
				$oldFile = Services::get('submissionFile')->get($oldFileId);
				if ($oldFile) Services::get('submissionFile')->delete($oldFile);
			}
			$galley = $galleyDao->getById($galley->getId(), $publication->getId());
		}

		if (array_key_exists('label', $params)) {
			$galley->setLabel($params['label']);
			$galleyDao->updateObject($galley);
		}

		if (DoiHelper::requested($params)) {
			DoiHelper::store($galleyDao, $galley->getId(), $params['doi']);
			$galley = $galleyDao->getById($galley->getId(), $publication->getId());
		}

		return $response->withJson($this->props($galley), 200);
	}

	/**
	 * DELETE /submissions/{submissionId}/publications/{publicationId}/galleys/{galleyId}
	 */
	public function deleteArticleGalley($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$publication = $this->getContextPublication($args, $request);
		if (!$publication) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $galleyDao ArticleGalleyDAO */
		$galley = $galleyDao->getById((int) $args['galleyId'], $publication->getId());
		if (!$galley) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$data = $this->props($galley);

		// Remove the underlying file first, then the galley.
		if ($galley->getFileId()) {
			$file = Services::get('submissionFile')->get($galley->getFileId());
			if ($file) Services::get('submissionFile')->delete($file);
		}
		$galleyDao->deleteObject($galley);

		return $response->withJson($data, 200);
	}

	//
	// Helpers
	//

	/**
	 * Copy a temporary upload into the submission's files as a PROOF file
	 * associated to the galley. The REPRESENTATION assoc makes SubmissionFileDAO
	 * link the galley to this file automatically.
	 */
	protected function storeFile($temporaryFile, $submission, $galleyId, $locale, $context, $user) {
		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();
		$extension = $fileManager->parseFileExtension($temporaryFile->getOriginalFileName());
		$submissionDir = Services::get('submissionFile')->getSubmissionDir($context->getId(), $submission->getId());
		$fileId = Services::get('file')->add(
			$temporaryFile->getFilePath(),
			$submissionDir . '/' . uniqid() . '.' . $extension
		);

		$submissionFile = DAORegistry::getDAO('SubmissionFileDAO')->newDataObject();
		$submissionFile->setAllData(array(
			'fileId' => $fileId,
			'submissionId' => $submission->getId(),
			'uploaderUserId' => $user->getId(),
			'fileStage' => SUBMISSION_FILE_PROOF,
			'genreId' => $this->getDefaultGenreId($context->getId()),
			'assocType' => ASSOC_TYPE_REPRESENTATION,
			'assocId' => $galleyId,
			'name' => array($locale => $temporaryFile->getOriginalFileName()),
		));
		Services::get('submissionFile')->add($submissionFile, Application::get()->getRequest());
	}

	protected function getUploadedFile($params, $user) {
		if (empty($params['temporaryFileId'])) {
			return null;
		}
		$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
		return $temporaryFileDao->getTemporaryFile((int) $params['temporaryFileId'], $user->getId());
	}

	/**
	 * Load the publication from the route and confirm it belongs to a
	 * submission in the current journal.
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

	protected function getDefaultGenreId($contextId) {
		$genreDao = DAORegistry::getDAO('GenreDAO'); /* @var $genreDao GenreDAO */
		$genres = $genreDao->getEnabledByContextId($contextId);
		while ($genre = $genres->next()) {
			if ($genre->getCategory() == GENRE_CATEGORY_DOCUMENT) {
				return $genre->getId();
			}
		}
		return null;
	}

	/**
	 * JSON representation of an article galley.
	 */
	protected function props($galley) {
		return array(
			'id' => (int) $galley->getId(),
			'publicationId' => (int) $galley->getData('publicationId'),
			'submissionFileId' => $galley->getFileId() ? (int) $galley->getFileId() : null,
			'label' => $galley->getLabel(),
			'locale' => $galley->getLocale(),
			'seq' => $galley->getSequence(),
			'urlPath' => $galley->getData('urlPath'),
			'doi' => DoiHelper::get($galley),
		);
	}
}
