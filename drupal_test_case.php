<?php

/*
 * @file
 *   Test Framework for Drupal based on PHPUnit.
 *
 *   Save this file to Drupal root as drupal_test_case.php. Then run
 *     phpunit --bootstrap ./drupal_test_case.php FilterUnitTestCase modules/filter/filter.test
 */

/*
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
abstract class DrupalTestCase extends PHPUnit_Framework_TestCase {

  public function run(PHPUnit_Framework_TestResult $result = NULL) {
    $this->setPreserveGlobalState(FALSE);
    return parent::run($result);
  }

  function assertEqual($expected, $actual, $msg = NULL) {
    return $this->assertEquals($expected, $actual);
  }

  function verbose($message) {
    // $this->log($message, 'verbose');
  }

  function assertIdentical($first, $second, $message = '', $group = 'Other') {
    return $this->assertSame($first, $second, $message);
  }

  /**
   * Generates a random string containing letters and numbers.
   *
   * The string will always start with a letter. The letters may be upper or
   * lower case. This method is better for restricted inputs that do not
   * accept certain characters. For example, when testing input fields that
   * require machine readable values (i.e. without spaces and non-standard
   * characters) this method is best.
   *
   * @param $length
   *   Length of random string to generate.
   * @return
   *   Randomly generated string.
   */
  public static function randomName($length = 8) {
    $values = array_merge(range(65, 90), range(97, 122), range(48, 57));
    $max = count($values) - 1;
    $str = chr(mt_rand(97, 122));
    for ($i = 1; $i < $length; $i++) {
      $str .= chr($values[mt_rand(0, $max)]);
    }
    return $str;
  }
 }

/*
 * @runTestsInSeparateProcesses
 */
class DrupalUnitTestCase extends DrupalTestCase {
  function setUp() {
    parent::setUp();

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', UPAL_ROOT);
    }
    require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
    drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
  }
}

class DrupalWebTestCase extends DrupalTestCase {
  public function setUp() {
    parent::setUp();

    // Restore virgin files directory.
    rmdir(DRUPAL_ROOT . 'sites/upal/files');
    mkdir(DRUPAL_ROOT . 'sites/upal/files');
    // Restore virgin DB.
    $db = parse_url(UPAL_DB_URL);
    $cmd = sprintf('mysql -u%s -p%s -h%s -P%s %s < %s', $db['user'], $db['pass'], $db['host'], $db['port'], $db['path'], dirname(__FILE__) . '/drupal-7.4-standard.sql');
    $return = exec($cmd);

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', UPAL_ROOT);
    }
    require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
    drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

    // Enable modules for this test.
    $modules = func_get_args();
    if (isset($modules[0]) && is_array($modules[0])) {
      $modules = $modules[0];
    }
    if ($modules) {
      module_enable($modules, TRUE);
    }

    // Use the test mail class instead of the default mail handler class.
    variable_set('mail_system', array('default-system' => 'TestingMailSystem'));

  }
}

/*
 * Initialize our environment at the start of each run (i.e. suite).
 */
function upal_init() {
  // We read from globals here because env can be empty and ini did not work in quick test.
  define('UPAL_DB_URL', getenv('UPAL_DB_URL') ? getenv('UPAL_DB_URL') : (!empty($GLOBALS['UPAL_DB_URL']) ? $GLOBALS['UPAL_DB_URL'] : 'mysql://root:@127.0.0.1'));

  // Make sure we use the right Drupal codebase.
  define('UPAL_ROOT', getenv('UPAL_ROOT') ? getenv('UPAL_ROOT') : (isset($GLOBALS['UPAL_ROOT']) ? $GLOBALS['UPAL_ROOT'] : realpath('.')));
  chdir(UPAL_ROOT);

  // The URL that browser based tests (ewwwww) should use.
  define('UPAL_WEB_URL', getenv('UPAL_WEB_URL') ? getenv('UPAL_WEB_URL') : (isset($GLOBALS['UPAL_WEB_URL']) ? $GLOBALS['UPAL_WEB_URL'] : 'http://127.0.0.1/'));
  $url = parse_url(UPAL_WEB_URL);
  $_SERVER['HTTP_HOST'] = $url['host'];
  $_SERVER['SCRIPT_NAME'] = $url['path'];
  $_SERVER['REMOTE_ADDR'] = $url['host'];
}

 // This code is in global scope.
 // TODO: I would rather this code at top of file, but I get Fatal error: Class 'Drush_TestCase' not found
 upal_init();
