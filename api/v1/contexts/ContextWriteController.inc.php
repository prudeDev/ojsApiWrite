<?php

/**
 * @file plugins/generic/issueApiWrite/api/v1/contexts/ContextWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ContextWriteController
 * @ingroup plugins_generic_issueApiWrite
 *
 * @brief Site-wide endpoints to bulk-create journals (contexts) and to export
 *        the current list of journals. Both require a Site Administrator.
 *
 * These augment the core api/v1/contexts resource. Because creating/listing
 * every journal is a site-level operation, both endpoints are only served from
 * the site-wide API (no journal in the request path):
 *
 *   POST /index.php/index/api/v1/contexts/bulk
 *   GET  /index.php/index/api/v1/contexts/export[?format=csv]
 */

class ContextWriteController {

	/**
	 * Maximum journals accepted in a single bulk request.
	 *
	 * Creating a journal runs OJS's full context initialization (~5-6 s each,
	 * dominated by UserGroupDAO::installSettings). Worse, every add() re-runs
	 * PluginRegistry::loadAllPlugins(), which re-registers hooks that accumulate
	 * within the PHP process, so each successive journal in the SAME request gets
	 * slower (roughly +1 s per item). A big single request therefore degrades
	 * quadratically and will hit PHP/web timeouts. Keep batches small and let the
	 * caller loop (each HTTP request is a fresh process that starts fast again).
	 */
	const MAX_BULK = 25;

	/** Columns used for CSV import/export, in order. */
	private $csvColumns = array('urlPath', 'name', 'acronym', 'description', 'primaryLocale', 'supportedLocales', 'supportedFormLocales', 'supportedSubmissionLocales', 'enabled');

	/**
	 * Bulk-create journals.
	 *
	 * Body (JSON): { "journals": [ { "urlPath": "...", "name": "...",
	 *   "acronym": "...", "description": "...", "primaryLocale": "en_US",
	 *   "enabled": true }, ... ] }
	 *
	 * `name`, `acronym` and `description` accept either a plain string (stored
	 * in the journal's primary locale) or an object keyed by locale
	 * ({ "en_US": "..." }). Every item is processed independently: a bad row is
	 * reported in `errors`/`skipped` and does not stop the rest.
	 *
	 * @return Response
	 */
	public function bulkAdd($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();

		// Site-wide only: creating journals has no meaning inside a journal.
		if ($request->getContext()) {
			return $response->withStatus(404)->withJsonError('api.submissions.404.siteWideEndpoint');
		}

		$body = $slimRequest->getParsedBody();
		$journals = isset($body['journals']) ? $body['journals'] : null;
		if (!is_array($journals) || empty($journals)) {
			return $response->withStatus(400)->withJson(array(
				'error' => 'api.400.invalidBody',
				'errorMessage' => 'Se requiere un arreglo "journals" con al menos un elemento.',
			));
		}

		// Guard against oversized batches that would time out (see MAX_BULK).
		if (count($journals) > self::MAX_BULK) {
			return $response->withStatus(400)->withJson(array(
				'error' => 'api.400.tooManyJournals',
				'errorMessage' => 'Demasiados journals en una sola petición (' . count($journals)
					. '). El máximo es ' . self::MAX_BULK . '. Envíalos en lotes más pequeños; '
					. 'cada petición es un proceso nuevo y arranca rápido de nuevo.',
			));
		}

		// Journal creation is slow (~5-6 s each); a full batch can exceed the
		// default PHP time limit. Lift it for this request (the web server may
		// still enforce its own timeout — keep batches small regardless).
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		$site = $request->getSite();
		$sitePrimaryLocale = $site->getPrimaryLocale();
		$allowedLocales = $site->getSupportedLocales();
		$contextService = Services::get('context');
		$contextDao = Application::getContextDAO();

		$created = array();
		$skipped = array();
		$errors = array();

		foreach ($journals as $index => $journal) {
			if (!is_array($journal)) {
				$errors[] = array('index' => $index, 'errors' => array('body' => 'Cada elemento debe ser un objeto.'));
				continue;
			}

			$urlPath = isset($journal['urlPath']) ? trim((string) $journal['urlPath']) : '';
			$locale = !empty($journal['primaryLocale']) ? trim((string) $journal['primaryLocale']) : $sitePrimaryLocale;

			// name is required by us (the schema allows null, a journal without a
			// name is useless).
			$name = $this->toLocalizedValue(isset($journal['name']) ? $journal['name'] : null, $locale);
			if ($urlPath === '' || $this->isBlankLocalized($name)) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => array('name' => 'urlPath y name son obligatorios.'));
				continue;
			}

			// Cheap duplicate check first, so a repeated file is idempotent and
			// reported as skipped rather than as a validation error.
			if ($contextDao->getByPath($urlPath)) {
				$skipped[] = array('index' => $index, 'urlPath' => $urlPath, 'reason' => 'Ya existe un journal con esa ruta.');
				continue;
			}

			$params = array(
				'primaryLocale' => $locale,
				'name' => $name,
				'urlPath' => $urlPath,
				'enabled' => !array_key_exists('enabled', $journal) ? true : (bool) $journal['enabled'],
			);
			$acronym = $this->toLocalizedValue(isset($journal['acronym']) ? $journal['acronym'] : null, $locale);
			if (!$this->isBlankLocalized($acronym)) {
				$params['acronym'] = $acronym;
			}
			$description = $this->toLocalizedValue(isset($journal['description']) ? $journal['description'] : null, $locale);
			if (!$this->isBlankLocalized($description)) {
				$params['description'] = $description;
			}

			// Language configuration (the "Idiomas" settings): which locales are
			// enabled for the UI, for Forms and for Submissions. Each is a list of
			// locale codes. Accepts an array or a "en_US|es_ES" string.
			//   supportedLocales           -> UI
			//   supportedFormLocales        -> Forms
			//   supportedSubmissionLocales  -> Submissions
			foreach (array('supportedLocales', 'supportedFormLocales', 'supportedSubmissionLocales') as $localeProp) {
				if (array_key_exists($localeProp, $journal)) {
					$list = $this->toLocaleList($journal[$localeProp]);
					if (!empty($list)) {
						$params[$localeProp] = $list;
					}
				}
			}

			// Forms/Submissions locales must be a subset of the UI locales, as the
			// OJS settings form enforces. Compare against the effective UI locales
			// (the ones sent, or the site's default that add() would apply).
			$uiLocales = isset($params['supportedLocales']) ? $params['supportedLocales'] : $allowedLocales;
			$subsetError = null;
			foreach (array('supportedFormLocales' => 'Forms', 'supportedSubmissionLocales' => 'Submissions') as $prop => $label) {
				if (isset($params[$prop])) {
					$outside = array_diff($params[$prop], $uiLocales);
					if (!empty($outside)) {
						$subsetError = "Los locales de {$label} deben estar entre los de UI (supportedLocales). Fuera: " . implode(', ', $outside);
						break;
					}
				}
			}
			if ($subsetError !== null) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => array('supportedLocales' => $subsetError));
				continue;
			}

			// Reuse the core validator: it enforces the urlPath format regex,
			// uniqueness, that the primary locale is among the supported locales,
			// and that every locale list is installed on the site.
			$validationErrors = $contextService->validate(VALIDATE_ACTION_ADD, $params, $allowedLocales, $locale);
			if (!empty($validationErrors)) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => $validationErrors);
				continue;
			}

			try {
				$context = $contextDao->newDataObject();
				$context->setAllData($params);
				$context = $contextService->add($context, $request);
				$created[] = array(
					'id' => $context->getId(),
					'urlPath' => $context->getPath(),
					'name' => $context->getLocalizedName($locale),
				);
			} catch (Exception $e) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => array('exception' => $e->getMessage()));
			}
		}

		return $response->withJson(array(
			'summary' => array(
				'received' => count($journals),
				'created' => count($created),
				'skipped' => count($skipped),
				'errors' => count($errors),
			),
			'created' => $created,
			'skipped' => $skipped,
			'errors' => $errors,
		), 201);
	}

	/**
	 * Export every journal.
	 *
	 * `?format=csv` returns a CSV file with the same columns the bulk import
	 * accepts, so a site can be dumped and re-imported. Any other value (or
	 * none) returns JSON.
	 *
	 * @return Response
	 */
	public function export($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();

		// Site-wide only: this lists *all* journals on the installation.
		if ($request->getContext()) {
			return $response->withStatus(404)->withJsonError('api.submissions.404.siteWideEndpoint');
		}

		$params = $slimRequest->getQueryParams();
		$format = isset($params['format']) ? strtolower(trim($params['format'])) : 'json';

		$contextService = Services::get('context');
		$rows = array();
		// count high enough to dump every journal in one call.
		$contextsIterator = $contextService->getMany(array('count' => 10000, 'offset' => 0));
		foreach ($contextsIterator as $context) {
			$locale = $context->getPrimaryLocale();
			$rows[] = array(
				'id' => (int) $context->getId(),
				'urlPath' => $context->getPath(),
				'name' => $context->getLocalizedName($locale),
				'acronym' => (string) $context->getLocalizedAcronym(),
				'description' => (string) $context->getLocalizedData('description', $locale),
				'primaryLocale' => $locale,
				'supportedLocales' => (array) $context->getData('supportedLocales'),
				'supportedFormLocales' => (array) $context->getData('supportedFormLocales'),
				'supportedSubmissionLocales' => (array) $context->getData('supportedSubmissionLocales'),
				'enabled' => (bool) $context->getEnabled(),
			);
		}

		if ($format === 'csv') {
			$csv = $this->toCsv($rows);
			$response->getBody()->write($csv);
			return $response
				->withHeader('Content-Type', 'text/csv; charset=utf-8')
				->withHeader('Content-Disposition', 'attachment; filename="journals.csv"');
		}

		return $response->withJson(array(
			'itemsMax' => count($rows),
			'items' => $rows,
		), 200);
	}

	/**
	 * Normalize a value that may be a plain string or a {locale: value} object
	 * into the multilingual array OJS expects.
	 *
	 * @param mixed $value string|array|null
	 * @param string $locale fallback locale for a plain string
	 * @return array
	 */
	private function toLocalizedValue($value, $locale) {
		if (is_array($value)) {
			return $value;
		}
		if ($value === null || $value === '') {
			return array();
		}
		return array($locale => trim((string) $value));
	}

	/**
	 * Normalize a locale-list value into an array of unique locale codes.
	 * Accepts an array (["en_US","es_ES"]) or a string with codes separated by
	 * "|", "," or whitespace ("en_US|es_ES").
	 *
	 * @param mixed $value array|string|null
	 * @return array
	 */
	private function toLocaleList($value) {
		if (is_array($value)) {
			$list = $value;
		} elseif (is_string($value) && trim($value) !== '') {
			$list = preg_split('/[|,\s]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
		} else {
			return array();
		}
		$out = array();
		foreach ($list as $locale) {
			$locale = trim((string) $locale);
			if ($locale !== '' && !in_array($locale, $out, true)) {
				$out[] = $locale;
			}
		}
		return $out;
	}

	/**
	 * @param array $localized a {locale: value} array
	 * @return boolean true if there is no non-empty value in any locale
	 */
	private function isBlankLocalized($localized) {
		if (!is_array($localized)) {
			return true;
		}
		foreach ($localized as $value) {
			if (trim((string) $value) !== '') {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build a CSV string (with header) from the export rows.
	 *
	 * @param array $rows
	 * @return string
	 */
	private function toCsv($rows) {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, $this->csvColumns);
		foreach ($rows as $row) {
			fputcsv($handle, array(
				$row['urlPath'],
				$row['name'],
				$row['acronym'],
				$row['description'],
				$row['primaryLocale'],
				implode('|', (array) $row['supportedLocales']),
				implode('|', (array) $row['supportedFormLocales']),
				implode('|', (array) $row['supportedSubmissionLocales']),
				$row['enabled'] ? '1' : '0',
			));
		}
		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);
		// Prepend a UTF-8 BOM so Excel opens accents correctly.
		return "\xEF\xBB\xBF" . $csv;
	}
}
