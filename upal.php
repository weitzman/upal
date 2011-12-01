<?php

/*
 * @file
 *   Test Framework for Drupal based on PHPUnit.
 *
 *   @todo
 *     - Simpletest's assertFalse casts to boolean whereas PHPUnit requires boolean. See testSiteWideContact().
 *     - hard coded TRUE at end of drupalLogin() because PHPUnit doesn't return
 *       anything from an assertion (unlike simpletest). Even if we fix drupalLogin(),
 *       we have to fix this to get 100% compatibility with simpletest.
 *     - setUp() only resets DB for mysql. Probably should use Drush and thus
 *       support postgres and sqlite easily. That buys us auto creation of upal DB
 *       as well.
 *     - Unlikely: Instead of DB restore, clone as per http://drupal.org/node/666956.
 *     - error() could log $caller info.
 *     - Fix random test failures.
 *     - Split into separate class files and add autoloader for upal.
 *     - Compare speed versus simpletest.
 *     - move upal_init() to a class thats called early in the suite.
 */

// class moved to its own file
include_once 'DrupalTestCase.class.php';

upal_init();


/*
 * Initialize our environment at the start of each run (i.e. suite).
 */
function upal_init() {
  // UNISH_DRUSH value can come from phpunit.xml or `which drush`.
  if (!defined('UNISH_DRUSH')) {
    // Let the UNISH_DRUSH environment variable override if set.
    $unish_drush = isset($_SERVER['UNISH_DRUSH']) ? $_SERVER['UNISH_DRUSH'] : NULL;
    $unish_drush = isset($GLOBALS['UNISH_DRUSH']) ? $GLOBALS['UNISH_DRUSH'] : $unish_drush;
    if (empty($unish_drush)) {
      // $unish_drush = Drush_TestCase::is_windows() ? exec('for %i in (drush) do @echo.   %~$PATH:i') : trim(`which drush`);
      $unish_drush = trim(`which drush`);
    }
    define('UNISH_DRUSH', $unish_drush);
  }

  // We read from globals here because env can be empty and ini did not work in quick test.
  define('UPAL_DB_URL', getenv('UPAL_DB_URL') ? getenv('UPAL_DB_URL') : (!empty($GLOBALS['UPAL_DB_URL']) ? $GLOBALS['UPAL_DB_URL'] : 'mysql://root:@127.0.0.1/upal'));

  // Make sure we use the right Drupal codebase.
  define('UPAL_ROOT', getenv('UPAL_ROOT') ? getenv('UPAL_ROOT') : (isset($GLOBALS['UPAL_ROOT']) ? $GLOBALS['UPAL_ROOT'] : realpath('.')));
  chdir(UPAL_ROOT);

  // The URL that browser based tests (ewwwww) should use.
  define('UPAL_WEB_URL', getenv('UPAL_WEB_URL') ? getenv('UPAL_WEB_URL') : (isset($GLOBALS['UPAL_WEB_URL']) ? $GLOBALS['UPAL_WEB_URL'] : 'http://upal'));


  // http credentials (optional)
  $GLOBALS['UPAL_HTTP_USER'] = getenv('UPAL_HTTP_USER') ? getenv('UPAL_HTTP_USER') : (isset($GLOBALS['UPAL_HTTP_USER']) ? $GLOBALS['UPAL_HTTP_USER'] : NULL);
  $GLOBALS['UPAL_HTTP_PASS'] = getenv('UPAL_HTTP_PASS') ? getenv('UPAL_HTTP_PASS') : (isset($GLOBALS['UPAL_HTTP_PASS']) ? $GLOBALS['UPAL_HTTP_PASS'] : NULL);

  // Set the env vars that Drupal expects. Largely copied from drush.
  $url = parse_url(UPAL_WEB_URL);

  if (array_key_exists('path', $url)) {
    $_SERVER['PHP_SELF'] = $url['path'] . '/index.php';
  }
  else {
    $_SERVER['PHP_SELF'] = '/index.php';
  }

  $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
  $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
  $_SERVER['REQUEST_METHOD']  = NULL;

  $_SERVER['SERVER_SOFTWARE'] = NULL;
  $_SERVER['HTTP_USER_AGENT'] = NULL;

  $_SERVER['HTTP_HOST'] = $url['host'];
  $_SERVER['SERVER_PORT'] = array_key_exists('port', $url) ? $url['port'] : NULL;
}
