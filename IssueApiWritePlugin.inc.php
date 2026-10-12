<?php

/**
 * @file plugins/generic/issueApiWrite/IssueApiWritePlugin.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssueApiWritePlugin
 * @ingroup plugins_generic_issueApiWrite
 *
 * @brief Adds POST/PUT/DELETE endpoints to the existing api/v1/issues
 *        REST resource, without modifying OJS core.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class IssueApiWritePlugin extends GenericPlugin {

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success) {
			// Register the hook unconditionally. Generic plugins are loaded by
			// the Dispatcher before the request context is resolved, so
			// getEnabled() is unreliable here (it can fall back to the site
			// context and return false). We defer the enabled-check to the
			// callback, which runs at route-setup time once the context is
			// known. See APIHandler::setupEndpoints().
			HookRegistry::register('APIHandler::endpoints', array($this, 'addEndpoints'));
			// Every journal created from now on starts with this plugin (and DOI)
			// enabled. See enableForNewContext().
			HookRegistry::register('Context::add', array($this, 'enableForNewContext'));
		}
		return $success;
	}

	/**
	 * Plugins enabled automatically in every new journal: this one, so the API
	 * can write to the journal right away, and DOI, which the `doi` fields of
	 * the API need.
	 */
	protected $newContextPlugins = array('doipubidplugin');

	/**
	 * Hook callback for 'Context::add'.
	 *
	 * $args = array(&$context, $request)
	 *
	 * OJS loads every plugin (enabled or not) right before firing this hook
	 * when a journal is created, whether from this API (contexts/bulk) or from
	 * the OJS UI. The setting is idempotent: if the hook runs more than once
	 * for the same journal, nothing changes.
	 *
	 * @param string $hookName
	 * @param array $args
	 * @return boolean false, to let other hooks run
	 */
	public function enableForNewContext($hookName, $args) {
		$context = $args[0];
		$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO'); /* @var $pluginSettingsDao PluginSettingsDAO */
		foreach (array_merge(array($this->getName()), $this->newContextPlugins) as $pluginName) {
			$pluginSettingsDao->updateSetting($context->getId(), $pluginName, 'enabled', true, 'bool');
		}
		return false;
	}

	/**
	 * Hook callback for 'APIHandler::endpoints'.
	 *
	 * $args = array(&$endpoints, $apiHandler)
	 *
	 * We only augment the issues resource; every other API handler is left
	 * untouched.
	 *
	 * @param string $hookName
	 * @param array $args
	 * @return boolean false, to let other hooks run
	 */
	public function addEndpoints($hookName, $args) {
		$endpoints =& $args[0];
		$handler = $args[1];

		// The contexts (journals) resource is a special case: bulk-creating and
		// exporting journals is a site-wide, site-admin operation. It is served
		// from the site-wide API (no journal in the path), where the per-journal
		// getEnabled() check below would be false, so we wire it here — before
		// that check — guarded only by the ROLE_ID_SITE_ADMIN role. Available
		// whenever the plugin is installed, independent of per-journal enabling.
		if (is_a($handler, 'PKPContextHandler')) {
			$this->addContextEndpoints($endpoints, $handler->getEndpointPattern());
			return false;
		}

		// We only augment two core resources: issues and submissions.
		$isIssues = is_a($handler, 'IssueHandler');
		$isSubmissions = is_a($handler, 'PKPSubmissionHandler');
		if (!$isIssues && !$isSubmissions) {
			return false;
		}

		// The request context is resolved by now, so this enabled-check is
		// reliable. Skip injection if the plugin is disabled for this journal.
		$context = Application::get()->getRequest()->getContext();
		$contextId = $context ? $context->getId() : CONTEXT_SITE;
		if (!$this->getEnabled($contextId)) {
			return false;
		}

		// Only journal managers may write.
		$writeRoles = array(ROLE_ID_MANAGER);
		$basePattern = $handler->getEndpointPattern();

		if ($isSubmissions) {
			$this->addAuthorEndpoints($endpoints, $basePattern, $writeRoles);
			return false;
		}

		$this->import('api.v1.issues.IssueWriteController');
		$controller = new IssueWriteController();

		$endpoints['POST'][] = array(
			'pattern' => $basePattern,
			'handler' => array($controller, 'add'),
			'roles' => $writeRoles,
		);
		$endpoints['PUT'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}',
			'handler' => array($controller, 'edit'),
			'roles' => $writeRoles,
		);
		$endpoints['PUT'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}/publish',
			'handler' => array($controller, 'publish'),
			'roles' => $writeRoles,
		);
		$endpoints['DELETE'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}',
			'handler' => array($controller, 'delete'),
			'roles' => $writeRoles,
		);

		// Issue galleys (e.g. the full-issue PDF). Upload the file first via
		// POST /temporaryFiles, then reference the returned id here.
		$endpoints['GET'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}/galleys',
			'handler' => array($controller, 'getGalleys'),
			'roles' => $writeRoles,
		);
		$endpoints['POST'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}/galleys',
			'handler' => array($controller, 'addGalley'),
			'roles' => $writeRoles,
		);
		$endpoints['PUT'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}/galleys/{galleyId:\d+}',
			'handler' => array($controller, 'editGalley'),
			'roles' => $writeRoles,
		);
		$endpoints['DELETE'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}/galleys/{galleyId:\d+}',
			'handler' => array($controller, 'deleteGalley'),
			'roles' => $writeRoles,
		);

		// Basic article creation (submission + publication + PDF galley),
		// assigned to the issue and left unpublished.
		$this->import('api.v1.issues.ArticleWriteController');
		$articleController = new ArticleWriteController();
		$endpoints['POST'][] = array(
			'pattern' => $basePattern . '/{issueId:\d+}/articles',
			'handler' => array($articleController, 'add'),
			'roles' => $writeRoles,
		);

		return false;
	}

	/**
	 * Register the bulk-import and export routes on the core contexts resource.
	 *
	 * Both are site-wide, site-admin operations (the controller rejects the call
	 * if a journal is present in the request path). The static `/bulk` and
	 * `/export` path segments take precedence over the core `/{contextId}`
	 * placeholder in the router, so there is no collision.
	 *
	 * @param array $endpoints (by ref) the handler's endpoint definitions
	 * @param string $basePattern the contexts endpoint base pattern
	 */
	protected function addContextEndpoints(&$endpoints, $basePattern) {
		$this->import('api.v1.contexts.ContextWriteController');
		$controller = new ContextWriteController();

		$adminRoles = array(ROLE_ID_SITE_ADMIN);

		$endpoints['POST'][] = array(
			'pattern' => $basePattern . '/bulk',
			'handler' => array($controller, 'bulkAdd'),
			'roles' => $adminRoles,
		);
		$endpoints['GET'][] = array(
			'pattern' => $basePattern . '/export',
			'handler' => array($controller, 'export'),
			'roles' => $adminRoles,
		);
	}

	/**
	 * Register the author CRUD routes on the core submissions resource.
	 *
	 * @param array $endpoints (by ref) the handler's endpoint definitions
	 * @param string $basePattern the submissions endpoint base pattern
	 * @param array $writeRoles roles allowed to write
	 */
	protected function addAuthorEndpoints(&$endpoints, $basePattern, $writeRoles) {
		$this->import('api.v1.submissions.AuthorWriteController');
		$controller = new AuthorWriteController();

		$authorsBase = $basePattern . '/{submissionId:\d+}/publications/{publicationId:\d+}/authors';

		$endpoints['GET'][] = array(
			'pattern' => $authorsBase,
			'handler' => array($controller, 'getAuthors'),
			'roles' => $writeRoles,
		);
		$endpoints['POST'][] = array(
			'pattern' => $authorsBase,
			'handler' => array($controller, 'addAuthor'),
			'roles' => $writeRoles,
		);
		$endpoints['PUT'][] = array(
			'pattern' => $authorsBase . '/{authorId:\d+}',
			'handler' => array($controller, 'editAuthor'),
			'roles' => $writeRoles,
		);
		$endpoints['DELETE'][] = array(
			'pattern' => $authorsBase . '/{authorId:\d+}',
			'handler' => array($controller, 'deleteAuthor'),
			'roles' => $writeRoles,
		);

		// Article galleys (the article PDF): full CRUD, since the core
		// submission-files API needs a stage assignment we don't have.
		$this->import('api.v1.submissions.ArticleGalleyController');
		$galleyController = new ArticleGalleyController();
		$galleysBase = $basePattern . '/{submissionId:\d+}/publications/{publicationId:\d+}/galleys';

		$endpoints['GET'][] = array(
			'pattern' => $galleysBase,
			'handler' => array($galleyController, 'listArticleGalleys'),
			'roles' => $writeRoles,
		);
		$endpoints['POST'][] = array(
			'pattern' => $galleysBase,
			'handler' => array($galleyController, 'addArticleGalley'),
			'roles' => $writeRoles,
		);
		$endpoints['PUT'][] = array(
			'pattern' => $galleysBase . '/{galleyId:\d+}',
			'handler' => array($galleyController, 'editArticleGalley'),
			'roles' => $writeRoles,
		);
		$endpoints['DELETE'][] = array(
			'pattern' => $galleysBase . '/{galleyId:\d+}',
			'handler' => array($galleyController, 'deleteArticleGalley'),
			'roles' => $writeRoles,
		);
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.issueApiWrite.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.issueApiWrite.description');
	}
}
