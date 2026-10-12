<?php

/**
 * @defgroup plugins_generic_issueApiWrite Issue API Write Plugin
 */

/**
 * @file plugins/generic/issueApiWrite/index.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_issueApiWrite
 * @brief Wrapper for the Issue API Write plugin.
 */
require_once('IssueApiWritePlugin.inc.php');

return new IssueApiWritePlugin();
