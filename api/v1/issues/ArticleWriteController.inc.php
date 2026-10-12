<?php

/**
 * @file plugins/generic/issueApiWrite/api/v1/issues/ArticleWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleWriteController
 * @ingroup plugins_generic_issueApiWrite
 *
 * @brief Create a basic article (submission + publication) assigned to an
 *        issue, with a PDF galley. The article is always created UNPUBLISHED;
 *        publishing is a separate step.
 *
 *        Authorization (manager role + context) is enforced by the core
 *        IssueHandler before these methods run.
 */

import('classes.core.Services');
import('lib.pkp.classes.submission.SubmissionFile'); // SUBMISSION_FILE_* constants
import('plugins.generic.issueApiWrite.classes.DoiHelper');

class ArticleWriteController {

	/**
	 * POST /issues/{issueId}/articles
	 *
	 * Body (JSON):
	 *   title           string | { locale: string }   (required)
	 *   sectionId       int      (optional; defaults to the journal's first section)
	 *   locale          string   (optional; defaults to the journal primary locale)
	 *   authorName      string   (optional; creates a single author)
	 *   temporaryFileId int      (optional; the PDF, uploaded first to /temporaryFiles)
	 *   galleyLabel     string   (optional; defaults to "PDF")
	 */
	public function add($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();

		$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
		$issue = $context ? $issueDao->getById((int) $args['issueId'], $context->getId()) : null;
		if (!$issue) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();
		$locale = !empty($params['locale']) ? $params['locale'] : $context->getPrimaryLocale();

		// --- validate title ---
		$title = isset($params['title']) ? $params['title'] : null;
		if (is_array($title)) {
			$title = isset($title[$locale]) ? $title[$locale] : reset($title);
		}
		if (empty($title)) {
			return $response->withStatus(400)->withJson(array('title' => array(__('form.dataRequired'))));
		}

		// Validate the DOI before creating anything.
		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId());
			if ($doiError) {
				return $response->withStatus(400)->withJson(array('doi' => array($doiError)));
			}
		}

		// Resolve the upload up front too: the galley is attached last, and a bad
		// file id discovered at that point would leave the submission and
		// publication already committed with no way to roll them back.
		$temporaryFile = null;
		if (!empty($params['temporaryFileId'])) {
			$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
			$temporaryFile = $temporaryFileDao->getTemporaryFile((int) $params['temporaryFileId'], $user->getId());
			if (!$temporaryFile) {
				return $response->withStatus(400)->withJson(array('temporaryFileId' => array(__('common.uploadFailed'))));
			}
		}

		// --- resolve section ---
		$sectionDao = DAORegistry::getDAO('SectionDAO'); /* @var $sectionDao SectionDAO */
		if (!empty($params['sectionId'])) {
			$section = $sectionDao->getById((int) $params['sectionId'], $context->getId());
		} else {
			$sections = $sectionDao->getByContextId($context->getId());
			$section = $sections->next();
		}
		if (!$section) {
			return $response->withStatus(400)->withJson(array('sectionId' => array('No valid section found.')));
		}

		// --- 1. Submission ---
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$submission = $submissionDao->newDataObject();
		$submission->setData('contextId', $context->getId());
		$submission->setData('locale', $locale);
		$submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
		$submission->setData('status', STATUS_QUEUED);
		$submission->setData('submissionProgress', 0);
		$submission = Services::get('submission')->add($submission, $request);

		// --- 2. Publication (assigned to the issue, unpublished) ---
		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */
		$publication = $publicationDao->newDataObject();
		$publication->setData('submissionId', $submission->getId());
		$publication->setData('sectionId', $section->getId());
		$publication->setData('issueId', $issue->getId());
		$publication->setData('status', STATUS_QUEUED);
		$publication->setData('title', array($locale => $title));
		$publication->setData('version', 1);
		$this->applyMetadata($publication, $params, $locale);
		$publication = Services::get('publication')->add($publication, $request);

		// The DOI is a pub-id setting, written once the publication has an id.
		if (DoiHelper::requested($params)) {
			DoiHelper::store($publicationDao, $publication->getId(), $params['doi']);
			$publication = Services::get('publication')->get($publication->getId());
		}

		// Point the submission at its current publication.
		$submission = Services::get('submission')->edit(
			$submission, array('currentPublicationId' => $publication->getId()), $request
		);

		// --- 3. Optional author ---
		if (!empty($params['authorName'])) {
			$authorDao = DAORegistry::getDAO('AuthorDAO'); /* @var $authorDao AuthorDAO */
			$userGroupId = $this->getDefaultAuthorUserGroupId($context->getId());
			$author = $authorDao->newDataObject();
			$author->setData('publicationId', $publication->getId());
			$author->setGivenName($params['authorName'], $locale);
			$author->setEmail($user->getEmail());
			$author->setData('seq', 0);
			if ($userGroupId) $author->setUserGroupId($userGroupId);
			$authorId = $authorDao->insertObject($author);
			// Make this author the primary contact of the publication.
			Services::get('publication')->edit($publication, array('primaryContactId' => $authorId), $request);
			$publication = Services::get('publication')->get($publication->getId());
		}

		// --- 4. Galley + PDF ---
		$galley = null;
		if ($temporaryFile) {
			$galley = $this->attachGalley(
				$submission, $publication, $context, $user, $temporaryFile,
				!empty($params['galleyLabel']) ? $params['galleyLabel'] : 'PDF',
				$locale
			);
		}

		return $response->withJson(array(
			'submissionId' => (int) $submission->getId(),
			'publicationId' => (int) $publication->getId(),
			'galleyId' => $galley ? (int) $galley->getId() : null,
			'issueId' => (int) $issue->getId(),
			'status' => 'unpublished',
			'title' => $title,
			'sectionId' => (int) $section->getId(),
			'doi' => DoiHelper::get($publication),
		), 201);
	}

	//
	// Helpers
	//

	/**
	 * Set publication metadata from the request. The controlled-vocab fields
	 * (keywords, subjects, disciplines, supportingAgencies) are persisted
	 * automatically by PublicationDAO when set as publication data.
	 *
	 * Accepted input for each field:
	 *   - abstract: string, or { locale: string }
	 *   - keywords/subjects/disciplines/supportingAgencies:
	 *       ["a","b"] (applied to $locale), or { locale: ["a","b"] }
	 */
	protected function applyMetadata($publication, $params, $locale) {
		// Abstract is a plain multilingual string.
		if (isset($params['abstract'])) {
			$abstract = $params['abstract'];
			if (is_string($abstract)) $abstract = array($locale => $abstract);
			$publication->setData('abstract', (array) $abstract);
		}

		// Controlled-vocabulary lists.
		foreach (array('keywords', 'subjects', 'disciplines', 'supportingAgencies') as $field) {
			if (!isset($params[$field])) {
				continue;
			}
			$value = $params[$field];
			// A flat list is treated as belonging to the article locale.
			if (is_array($value) && array_key_exists(0, $value)) {
				$value = array($locale => array_values($value));
			}
			$publication->setData($field, (array) $value);
		}
	}

	/**
	 * Create an ArticleGalley and attach the uploaded PDF to it. The submission
	 * file is created with assocType REPRESENTATION so IssueGalley/SubmissionFile
	 * wiring links it to the galley automatically.
	 *
	 * @param TemporaryFile $temporaryFile already resolved and owned by $user
	 * @return ArticleGalley
	 */
	protected function attachGalley($submission, $publication, $context, $user, $temporaryFile, $label, $locale) {
		// Create the galley container first.
		$galleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $galleyDao ArticleGalleyDAO */
		$galley = $galleyDao->newDataObject();
		$galley->setData('publicationId', $publication->getId());
		$galley->setLabel($label);
		$galley->setLocale($locale);
		$galleyId = $galleyDao->insertObject($galley);

		// Copy the physical file into the submission's file area.
		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();
		$extension = $fileManager->parseFileExtension($temporaryFile->getOriginalFileName());
		$submissionDir = Services::get('submissionFile')->getSubmissionDir($context->getId(), $submission->getId());
		$fileId = Services::get('file')->add(
			$temporaryFile->getFilePath(),
			$submissionDir . '/' . uniqid() . '.' . $extension
		);

		// Pick a genre (document category) for the file.
		$genreId = $this->getDefaultGenreId($context->getId());

		// Create the submission file; assoc REPRESENTATION auto-links the galley.
		$submissionFile = DAORegistry::getDAO('SubmissionFileDAO')->newDataObject();
		$submissionFile->setAllData(array(
			'fileId' => $fileId,
			'submissionId' => $submission->getId(),
			'uploaderUserId' => $user->getId(),
			'fileStage' => SUBMISSION_FILE_PROOF,
			'genreId' => $genreId,
			'assocType' => ASSOC_TYPE_REPRESENTATION,
			'assocId' => $galleyId,
			'name' => array($locale => $temporaryFile->getOriginalFileName()),
		));
		Services::get('submissionFile')->add($submissionFile, Application::get()->getRequest());

		return $galleyDao->getById($galleyId, $publication->getId());
	}

	/**
	 * Best-effort default genre (first enabled document-category genre).
	 */
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
	 * Find a user group in the Author role to attach the author to.
	 */
	protected function getDefaultAuthorUserGroupId($contextId) {
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
		$groups = $userGroupDao->getByRoleId($contextId, ROLE_ID_AUTHOR);
		$group = $groups->next();
		return $group ? $group->getId() : null;
	}
}
