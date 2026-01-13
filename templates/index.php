<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\NcwTools\AppInfo\Application::APP_ID, OCA\NcwTools\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\NcwTools\AppInfo\Application::APP_ID, OCA\NcwTools\AppInfo\Application::APP_ID . '-main');

?>

<div id="ncw_tools"></div>
