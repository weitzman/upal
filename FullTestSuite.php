<?php
require_once(dirname(__FILE__) . '/drupal_test_case.php');
require_once(dirname(__FILE__) . '/UpaltestSuite.php');

/**
 * Alternative to letting PHPUnit handle class retrieval via
 * traversing the filesystem. Works around restrictions of PHPUnit
 * on running tests on multiple directories at once, without resorting
 * to group or testsuite definitions in a custom phpunit.xml file.
 *
 * Usage:
 * - "phpunit sapphire/tests/FullTestSuite.php"
 *    (all tests)
 * - "phpunit sapphire/tests/FullTestSuite.php '' module=sapphire,cms"
 *   (comma-separated modules. empty quotes are necessary to avoid PHPUnit argument confusion)
 *
 * See http://www.phpunit.de/manual/current/en/organizing-tests.html#organizing-tests.testsuite
 *
 * @package sapphire
 * @subpackage testing
 */
class FullTestSuite {

  /**
   * Called by the PHPUnit runner to gather runnable tests.
   *
   * @return PHPUnit_Framework_TestSuite
   */
  public static function suite() {
    $suite = new PHPUnit_Framework_TestSuite();
    $suite->setName('FullTestSuite');
    if(isset($_GET['module'])) {
      $classList = self::get_module_tests($_GET['module']);
    } else {
      $classList = self::get_all_tests();
    }
    $suite->addTestFiles($classList);
    //foreach($classList as $className) {
    //  // class_exists($className);
    //  $suite->addTest(new UpalTestSuite($className));
    //}

    return $suite;
  }

  /**
   * @return Array
   */
  public static function get_all_tests() {
    $classes = array();
    $prereqs = array(
      '/Users/mw/htd/d7/includes/filetransfer/filetransfer.inc',
      '/Users/mw/htd/d7/includes/mail.inc',
      // '/Users/mw/htd/d7/modules/simpletest/tests/upgrade/upgrade.test',
      '/Users/mw/htd/d7/modules/field/tests/field.test',
      '/Users/mw/htd/d7/modules/simpletest/tests/image.test',
      '/Users/mw/htd/d7/modules/taxonomy/taxonomy.test'
    );
    foreach ($prereqs as $prereq) {
      require_once $prereq;
    }
    $cmd = sprintf("find %s -iname '*.test'", escapeshellarg(UPAL_ROOT));
    exec($cmd, $output, $return);

    // These cases have 'validity' errors with phpunit. Needs research.
    $blacklist = array(
      '/Users/mw/htd/d7/modules/file/tests/file.test',
      '/Users/mw/htd/d7/modules/locale/locale.test',
      '/Users/mw/htd/d7/modules/simpletest/tests/upgrade/upgrade.test',
    );
    $output = array_diff($output, $blacklist);
    // Only take the first 'm' since we eventually run into problems
    foreach (array_slice($output, 0, 20) as $file) {
      $contents = self::_registry_parse_file(file_get_contents($file));
      $classes += $contents[2];
      include_once $file; // @todo use Drupal autoloader if possible.
      $files[] = $file;
    }

    // $classes = 'ViewsUIWizardTaggedWithTestCase VotingAPITestCase XMLRPCBasicTestCase XMLRPCMessagesTestCase XMLRPCValidator1IncTestCase';
    // $classes = explode(' ', $classes);
    // return $classes;
    return $files;

  }

  /**
 * Parse a file and save its function and class listings.
 *
 * Lifted from Drupal with some simplification.
 *
 * @param $contents
 *  Contents of the file we are going to parse as a string.
 */
function _registry_parse_file($contents) {
  if (preg_match_all('/^\s*(?:abstract|final)?\s*(class|interface)\s+([a-zA-Z0-9_]+)/m', $contents, $matches)) {
    return $matches;
  }
}

  /**
   * Run tests for one or more "modules".
   * A module is generally a toplevel folder, e.g. "mysite" or "sapphire".
   *
   * @param String $nameStr
   * @return Array
   */
  //protected static function get_module_tests($namesStr) {
  //  $tests = array();
  //  $names = explode(',', $namesStr);
  //  foreach($names as $name) {
  //    $classesForModule = ClassInfo::classes_for_folder($name);
  //    if($classesForModule) foreach($classesForModule as $class) {
  //      if(class_exists($class) && is_subclass_of($class, 'SapphireTest')) {
  //        $tests[] = $class;
  //      }
  //    }
  //  }
  //
  //  return $tests;
  //}
}
