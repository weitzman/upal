<?php

/*
 * @file
 *   Test Framework for Drupal based on PHPUnit.
 *
 *   @todo
 *     - hard coded TRUE at end of drupalLogin() because PHPUnit doesn't return
 *       anything from an assertion (unlike simpletest). Even if we fix drupalLogin(),
 *       we have to fix this to get 100% compatibility with simpletest.
 *     - setUp() only resets DB for mysql. Probably should use Drush and thus
 *       support postgres and sqlite easily. That buys us auto creation of upal DB
 *       as well.
 *     - Perhaps do a SQL dump at start of suite instead of committing one to Git.
 *     - error() could log $caller info.
 *     - Fix verbose().
 *     - Split into separate class files and add autoloader for upal.
 *     - Compare speed versus simpletest.
 *     - move upal_init() to a class thats called early in the suite.
 */

/*
 * @todo: Perhaps move these annotations down to the instance classes and tests.
 *
 * @runTestsInSeparateProcess
 * @preserveGlobalState disabled
 */
abstract class DrupalTestCase extends PHPUnit_Framework_TestCase {

  /**
   * The profile to install as a basis for testing.
   *
   * @var string
   */
  protected $profile = 'standard';

  /**
   * The URL currently loaded in the internal browser.
   *
   * @var string
   */
  protected $url;

  /**
   * The handle of the current cURL connection.
   *
   * @var resource
   */
  protected $curlHandle;

  /**
   * The headers of the page currently loaded in the internal browser.
   *
   * @var Array
   */
  protected $headers;

  /**
   * The content of the page currently loaded in the internal browser.
   *
   * @var string
   */
  protected $content;

  /**
   * The content of the page currently loaded in the internal browser (plain text version).
   *
   * @var string
   */
  protected $plainTextContent;

  /**
   * The value of the Drupal.settings JavaScript variable for the page currently loaded in the internal browser.
   *
   * @var Array
   */
  protected $drupalSettings;

  /**
   * The parsed version of the page.
   *
   * @var SimpleXMLElement
   */
  protected $elements = NULL;

  /**
   * The current user logged in using the internal browser.
   *
   * @var bool
   */
  protected $loggedInUser = FALSE;

  /**
   * The current cookie file used by cURL.
   *
   * We do not reuse the cookies in further runs, so we do not need a file
   * but we still need cookie handling, so we set the jar to NULL.
   */
  protected $cookieFile = NULL;

  /**
   * Additional cURL options.
   *
   * DrupalWebTestCase itself never sets this but always obeys what is set.
   */
  protected $additionalCurlOptions = array();

  /**
   * The original user, before it was changed to a clean uid = 1 for testing purposes.
   *
   * @var object
   */
  protected $originalUser = NULL;

  /**
   * The original shutdown handlers array, before it was cleaned for testing purposes.
   *
   * @var array
   */
  protected $originalShutdownCallbacks = array();

  /**
   * HTTP authentication method
   */
  protected $httpauth_method = CURLAUTH_BASIC;

  /**
   * HTTP authentication credentials (<username>:<password>).
   */
  protected $httpauth_credentials = NULL;

  /**
   * The current session name, if available.
   */
  protected $session_name = NULL;

  /**
   * The current session ID, if available.
   */
  protected $session_id = NULL;

  /**
   * Whether the files were copied to the test files directory.
   */
  protected $generatedTestFiles = FALSE;

  /**
   * The number of redirects followed during the handling of a request.
   */
  protected $redirect_count;

  public function run(PHPUnit_Framework_TestResult $result = NULL) {
    $this->setPreserveGlobalState(FALSE);
    return parent::run($result);
  }

  /**
   * Fire an assertion that is always positive.
   *
   * @param $message
   *   The message to display along with the assertion.
   * @param $group
   *   The type of assertion - examples are "Browser", "PHP".
   * @return
   *   TRUE.
   */
  protected function pass($message = NULL, $group = 'Other') {
    return $this->assertTrue(TRUE, $message, $group);
  }

  /**
   * Fire an assertion that is always negative.
   *
   * @param $message
   *   The message to display along with the assertion.
   * @param $group
   *   The type of assertion - examples are "Browser", "PHP".
   * @return
   *   FALSE.
   */
  //protected function fail($message = NULL, $group = 'Other') {
  //  return $this->assertTrue(FALSE, $message, $group);
  //}

  /**
   * Fire an error assertion.
   *
   * @param $message
   *   The message to display along with the assertion.
   * @param $group
   *   The type of assertion - examples are "Browser", "PHP".
   * @param $caller
   *   The caller of the error.
   * @return
   *   FALSE.
   */
  protected function error($message = '', $group = 'Other', array $caller = NULL) {
    if ($group == 'User notice') {
      // Since 'User notice' is set by trigger_error() which is used for debug
      // set the message to a status of 'debug'.
      return $this->pass($message, $group);
    }
    // @todo Pass along $caller info.
    return $this->fail('exception: ' . $message, $group);
  }

  function assertEqual($expected, $actual, $msg = NULL) {
    return $this->assertEquals($expected, $actual);
  }

  function assertIdentical($first, $second, $message = '', $group = 'Other') {
    return $this->assertSame($first, $second, $message);
  }

  /**
   * Pass if the internal browser's URL matches the given path.
   *
   * @param $path
   *   The expected system path.
   * @param $options
   *   (optional) Any additional options to pass for $path to url().
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertUrl($path, array $options = array(), $message = '', $group = 'Other') {
    if (!$message) {
      $message = t('Current URL is @url.', array(
        '@url' => var_export(url($path, $options), TRUE),
      ));
    }
    $options['absolute'] = TRUE;
    return $this->assertEqual($this->getUrl(), url($path, $options), $message, $group);
  }

  /**
   * Pass if the raw text IS found on the loaded page, fail otherwise. Raw text
   * refers to the raw HTML that the page generated.
   *
   * @param $raw
   *   Raw (HTML) string to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertRaw($raw, $message = '', $group = 'Other') {
    if (!$message) {
      $message = t('Raw "@raw" found', array('@raw' => $raw));
    }
    return $this->assertContains($raw, $this->drupalGetContent(), $message, $group);
  }

  /**
   * Pass if the raw text is NOT found on the loaded page, fail otherwise. Raw text
   * refers to the raw HTML that the page generated.
   *
   * @param $raw
   *   Raw (HTML) string to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoRaw($raw, $message = '', $group = 'Other') {
    if (!$message) {
      $message = t('Raw "@raw" not found', array('@raw' => $raw));
    }
    return $this->assertNotContains($raw, $this->drupalGetContent(), $message, $group);
  }

  /**
   * Pass if the text IS found on the text version of the page. The text version
   * is the equivalent of what a user would see when viewing through a web browser.
   * In other words the HTML has been filtered out of the contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertText($text, $message = '', $group = 'Other') {
    return $this->assertTextHelper($text, $message, $group, FALSE);
  }

  /**
   * Pass if the text is NOT found on the text version of the page. The text version
   * is the equivalent of what a user would see when viewing through a web browser.
   * In other words the HTML has been filtered out of the contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoText($text, $message = '', $group = 'Other') {
    return $this->assertTextHelper($text, $message, $group, TRUE);
  }

  /**
   * Helper for assertText and assertNoText.
   *
   * It is not recommended to call this function directly.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @param $not_exists
   *   TRUE if this text should not exist, FALSE if it should.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertTextHelper($text, $message = '', $group, $not_exists) {
    if ($this->plainTextContent === FALSE) {
      $this->plainTextContent = filter_xss($this->drupalGetContent(), array());
    }
    if (!$message) {
      $message = !$not_exists ? t('"@text" found', array('@text' => $text)) : t('"@text" not found', array('@text' => $text));
    }
    return $this->assertTrue($not_exists == (strpos($this->plainTextContent, $text) === FALSE), $message, $group);
  }

  /**
   * Pass if the text is found ONLY ONCE on the text version of the page.
   *
   * The text version is the equivalent of what a user would see when viewing
   * through a web browser. In other words the HTML has been filtered out of
   * the contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertUniqueText($text, $message = '', $group = 'Other') {
    return $this->assertUniqueTextHelper($text, $message, $group, TRUE);
  }

  /**
   * Pass if the text is found MORE THAN ONCE on the text version of the page.
   *
   * The text version is the equivalent of what a user would see when viewing
   * through a web browser. In other words the HTML has been filtered out of
   * the contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoUniqueText($text, $message = '', $group = 'Other') {
    return $this->assertUniqueTextHelper($text, $message, $group, FALSE);
  }

  /**
   * Helper for assertUniqueText and assertNoUniqueText.
   *
   * It is not recommended to call this function directly.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @param $be_unique
   *   TRUE if this text should be found only once, FALSE if it should be found more than once.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertUniqueTextHelper($text, $message = '', $group, $be_unique) {
    if ($this->plainTextContent === FALSE) {
      $this->plainTextContent = filter_xss($this->drupalGetContent(), array());
    }
    if (!$message) {
      $message = '"' . $text . '"' . ($be_unique ? ' found only once' : ' found more than once');
    }
    $first_occurance = strpos($this->plainTextContent, $text);
    if ($first_occurance === FALSE) {
      return $this->assertTrue(FALSE, $message, $group);
    }
    $offset = $first_occurance + strlen($text);
    $second_occurance = strpos($this->plainTextContent, $text, $offset);
    return $this->assertTrue($be_unique == ($second_occurance === FALSE), $message, $group);
  }

  /**
   * Will trigger a pass if the Perl regex pattern is found in the raw content.
   *
   * @param $pattern
   *   Perl regex to look for including the regex delimiters.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertPattern($pattern, $message = '', $group = 'Other') {
    if (!$message) {
      $message = t('Pattern "@pattern" found', array('@pattern' => $pattern));
    }
    return $this->assertTrue((bool) preg_match($pattern, $this->drupalGetContent()), $message, $group);
  }

  /**
   * Will trigger a pass if the perl regex pattern is not present in raw content.
   *
   * @param $pattern
   *   Perl regex to look for including the regex delimiters.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoPattern($pattern, $message = '', $group = 'Other') {
    if (!$message) {
      $message = t('Pattern "@pattern" not found', array('@pattern' => $pattern));
    }
    return $this->assertTrue(!preg_match($pattern, $this->drupalGetContent()), $message, $group);
  }

  /**
   * Pass if the page title is the given string.
   *
   * @param $title
   *   The string the title should be.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertTitle($title, $message = '', $group = 'Other') {
    $actual = (string) current($this->xpath('//title'));
    if (!$message) {
      $message = t('Page title @actual is equal to @expected.', array(
        '@actual' => var_export($actual, TRUE),
        '@expected' => var_export($title, TRUE),
      ));
    }
    return $this->assertEqual($actual, $title, $message, $group);
  }

  /**
   * Pass if the page title is not the given string.
   *
   * @param $title
   *   The string the title should not be.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoTitle($title, $message = '', $group = 'Other') {
    $actual = (string) current($this->xpath('//title'));
    if (!$message) {
      $message = t('Page title @actual is not equal to @unexpected.', array(
        '@actual' => var_export($actual, TRUE),
        '@unexpected' => var_export($title, TRUE),
      ));
    }
    return $this->assertNotEqual($actual, $title, $message, $group);
  }

  /**
   * Asserts that a field exists in the current page by the given XPath.
   *
   * @param $xpath
   *   XPath used to find the field.
   * @param $value
   *   (optional) Value of the field to assert.
   * @param $message
   *   (optional) Message to display.
   * @param $group
   *   (optional) The group this message belongs to.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldByXPath($xpath, $value = NULL, $message = '', $group = 'Other') {
    $fields = $this->xpath($xpath);

    // If value specified then check array for match.
    $found = TRUE;
    if (isset($value)) {
      $found = FALSE;
      if ($fields) {
        foreach ($fields as $field) {
          if (isset($field['value']) && $field['value'] == $value) {
            // Input element with correct value.
            $found = TRUE;
          }
          elseif (isset($field->option)) {
            // Select element found.
            if ($this->getSelectedItem($field) == $value) {
              $found = TRUE;
            }
            else {
              // No item selected so use first item.
              $items = $this->getAllOptions($field);
              if (!empty($items) && $items[0]['value'] == $value) {
                $found = TRUE;
              }
            }
          }
          elseif ((string) $field == $value) {
            // Text area with correct text.
            $found = TRUE;
          }
        }
      }
    }
    return $this->assertTrue($fields && $found, $message, $group);
  }

  /**
   * Get the selected value from a select field.
   *
   * @param $element
   *   SimpleXMLElement select element.
   * @return
   *   The selected value or FALSE.
   */
  protected function getSelectedItem(SimpleXMLElement $element) {
    foreach ($element->children() as $item) {
      if (isset($item['selected'])) {
        return $item['value'];
      }
      elseif ($item->getName() == 'optgroup') {
        if ($value = $this->getSelectedItem($item)) {
          return $value;
        }
      }
    }
    return FALSE;
  }

  /**
   * Asserts that a field does not exist in the current page by the given XPath.
   *
   * @param $xpath
   *   XPath used to find the field.
   * @param $value
   *   (optional) Value of the field to assert.
   * @param $message
   *   (optional) Message to display.
   * @param $group
   *   (optional) The group this message belongs to.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldByXPath($xpath, $value = NULL, $message = '', $group = 'Other') {
    $fields = $this->xpath($xpath);

    // If value specified then check array for match.
    $found = TRUE;
    if (isset($value)) {
      $found = FALSE;
      if ($fields) {
        foreach ($fields as $field) {
          if ($field['value'] == $value) {
            $found = TRUE;
          }
        }
      }
    }
    return $this->assertFalse($fields && $found, $message, $group);
  }

  /**
   * Asserts that a field exists in the current page with the given name and value.
   *
   * @param $name
   *   Name of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldByName($name, $value = '', $message = '') {
    return $this->assertFieldByXPath($this->constructFieldXpath('name', $name), $value, $message ? $message : t('Found field by name @name', array('@name' => $name)), t('Browser'));
  }

  /**
   * Asserts that a field does not exist with the given name and value.
   *
   * @param $name
   *   Name of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldByName($name, $value = '', $message = '') {
    return $this->assertNoFieldByXPath($this->constructFieldXpath('name', $name), $value, $message ? $message : t('Did not find field by name @name', array('@name' => $name)), t('Browser'));
  }

  /**
   * Asserts that a field exists in the current page with the given id and value.
   *
   * @param $id
   *   Id of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldById($id, $value = '', $message = '') {
    return $this->assertFieldByXPath($this->constructFieldXpath('id', $id), $value, $message ? $message : t('Found field by id @id', array('@id' => $id)), t('Browser'));
  }

  /**
   * Asserts that a field does not exist with the given id and value.
   *
   * @param $id
   *   Id of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldById($id, $value = '', $message = '') {
    return $this->assertNoFieldByXPath($this->constructFieldXpath('id', $id), $value, $message ? $message : t('Did not find field by id @id', array('@id' => $id)), t('Browser'));
  }

  /**
   * Asserts that a checkbox field in the current page is checked.
   *
   * @param $id
   *   Id of field to assert.
   * @param $message
   *   Message to display.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldChecked($id, $message = '') {
    $elements = $this->xpath('//input[@id=:id]', array(':id' => $id));
    return $this->assertTrue(isset($elements[0]) && !empty($elements[0]['checked']), $message ? $message : t('Checkbox field @id is checked.', array('@id' => $id)), t('Browser'));
  }

  /**
   * Asserts that a checkbox field in the current page is not checked.
   *
   * @param $id
   *   Id of field to assert.
   * @param $message
   *   Message to display.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldChecked($id, $message = '') {
    $elements = $this->xpath('//input[@id=:id]', array(':id' => $id));
    return $this->assertTrue(isset($elements[0]) && empty($elements[0]['checked']), $message ? $message : t('Checkbox field @id is not checked.', array('@id' => $id)), t('Browser'));
  }

  /**
   * Asserts that a select option in the current page is checked.
   *
   * @param $id
   *   Id of select field to assert.
   * @param $option
   *   Option to assert.
   * @param $message
   *   Message to display.
   * @return
   *   TRUE on pass, FALSE on fail.
   *
   * @todo $id is unusable. Replace with $name.
   */
  protected function assertOptionSelected($id, $option, $message = '') {
    $elements = $this->xpath('//select[@id=:id]//option[@value=:option]', array(':id' => $id, ':option' => $option));
    return $this->assertTrue(isset($elements[0]) && !empty($elements[0]['selected']), $message ? $message : t('Option @option for field @id is selected.', array('@option' => $option, '@id' => $id)), t('Browser'));
  }

  /**
   * Asserts that a select option in the current page is not checked.
   *
   * @param $id
   *   Id of select field to assert.
   * @param $option
   *   Option to assert.
   * @param $message
   *   Message to display.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoOptionSelected($id, $option, $message = '') {
    $elements = $this->xpath('//select[@id=:id]//option[@value=:option]', array(':id' => $id, ':option' => $option));
    return $this->assertTrue(isset($elements[0]) && empty($elements[0]['selected']), $message ? $message : t('Option @option for field @id is not selected.', array('@option' => $option, '@id' => $id)), t('Browser'));
  }

  /**
   * Asserts that a field exists with the given name or id.
   *
   * @param $field
   *   Name or id of field to assert.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertField($field, $message = '', $group = 'Other') {
    return $this->assertFieldByXPath($this->constructFieldXpath('name', $field) . '|' . $this->constructFieldXpath('id', $field), NULL, $message, $group);
  }

  /**
   * Asserts that a field does not exist with the given name or id.
   *
   * @param $field
   *   Name or id of field to assert.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoField($field, $message = '', $group = 'Other') {
    return $this->assertNoFieldByXPath($this->constructFieldXpath('name', $field) . '|' . $this->constructFieldXpath('id', $field), NULL, $message, $group);
  }

  /**
   * Asserts that each HTML ID is used for just a single element.
   *
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to.
   * @param $ids_to_skip
   *   An optional array of ids to skip when checking for duplicates. It is
   *   always a bug to have duplicate HTML IDs, so this parameter is to enable
   *   incremental fixing of core code. Whenever a test passes this parameter,
   *   it should add a "todo" comment above the call to this function explaining
   *   the legacy bug that the test wishes to ignore and including a link to an
   *   issue that is working to fix that legacy bug.
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoDuplicateIds($message = '', $group = 'Other', $ids_to_skip = array()) {
    $status = TRUE;
    foreach ($this->xpath('//*[@id]') as $element) {
      $id = (string) $element['id'];
      if (isset($seen_ids[$id]) && !in_array($id, $ids_to_skip)) {
        $this->fail(t('The HTML ID %id is unique.', array('%id' => $id)), $group);
        $status = FALSE;
      }
      $seen_ids[$id] = TRUE;
    }
    return $this->assertTrue($status, $message, $group);
  }

  /**
   * Helper function: construct an XPath for the given set of attributes and value.
   *
   * @param $attribute
   *   Field attributes.
   * @param $value
   *   Value of field.
   * @return
   *   XPath for specified values.
   */
  protected function constructFieldXpath($attribute, $value) {
    $xpath = '//textarea[@' . $attribute . '=:value]|//input[@' . $attribute . '=:value]|//select[@' . $attribute . '=:value]';
    return $this->buildXPathQuery($xpath, array(':value' => $value));
  }

  /**
   * Asserts the page responds with the specified response code.
   *
   * @param $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param $message
   *   Message to display.
   * @return
   *   Assertion result.
   */
  protected function assertResponse($code, $message = '') {
    $curl_code = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
    $match = is_array($code) ? in_array($curl_code, $code) : $curl_code == $code;
    return $this->assertTrue($match, $message ? $message : t('HTTP response expected !code, actual !curl_code', array('!code' => $code, '!curl_code' => $curl_code)), t('Browser'));
  }

  /**
   * Asserts the page did not return the specified response code.
   *
   * @param $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param $message
   *   Message to display.
   *
   * @return
   *   Assertion result.
   */
  protected function assertNoResponse($code, $message = '') {
    $curl_code = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
    $match = is_array($code) ? in_array($curl_code, $code) : $curl_code == $code;
    return $this->assertFalse($match, $message ? $message : t('HTTP response not expected !code, actual !curl_code', array('!code' => $code, '!curl_code' => $curl_code)), t('Browser'));
  }

  /**
   * Asserts that the most recently sent e-mail message has the given value.
   *
   * The field in $name must have the content described in $value.
   *
   * @param $name
   *   Name of field or message property to assert. Examples: subject, body, id, ...
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   Message to display.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertMail($name, $value = '', $message = '') {
    $captured_emails = variable_get('drupal_test_email_collector', array());
    $email = end($captured_emails);
    return $this->assertTrue($email && isset($email[$name]) && $email[$name] == $value, $message, t('E-mail'));
  }

  /**
   * Asserts that the most recently sent e-mail message has the string in it.
   *
   * @param $field_name
   *   Name of field or message property to assert: subject, body, id, ...
   * @param $string
   *   String to search for.
   * @param $email_depth
   *   Number of emails to search for string, starting with most recent.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertMailString($field_name, $string, $email_depth) {
    $mails = $this->drupalGetMails();
    $string_found = FALSE;
    for ($i = sizeof($mails) -1; $i >= sizeof($mails) - $email_depth && $i >= 0; $i--) {
      $mail = $mails[$i];
      // Normalize whitespace, as we don't know what the mail system might have
      // done. Any run of whitespace becomes a single space.
      $normalized_mail = preg_replace('/\s+/', ' ', $mail[$field_name]);
      $normalized_string = preg_replace('/\s+/', ' ', $string);
      $string_found = (FALSE !== strpos($normalized_mail, $normalized_string));
      if ($string_found) {
        break;
      }
    }
    return $this->assertTrue($string_found, t('Expected text found in @field of email message: "@expected".', array('@field' => $field_name, '@expected' => $string)));
  }

  /**
   * Asserts that the most recently sent e-mail message has the pattern in it.
   *
   * @param $field_name
   *   Name of field or message property to assert: subject, body, id, ...
   * @param $regex
   *   Pattern to search for.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertMailPattern($field_name, $regex, $message) {
    $mails = $this->drupalGetMails();
    $mail = end($mails);
    $regex_found = preg_match("/$regex/", $mail[$field_name]);
    return $this->assertTrue($regex_found, t('Expected text found in @field of email message: "@expected".', array('@field' => $field_name, '@expected' => $regex)));
  }

  /**
   * Pass if a link with the specified label is found, and optional with the
   * specified index.
   *
   * @param $label
   *   Text between the anchor tags.
   * @param $index
   *   Link position counting from zero.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertLink($label, $index = 0, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[normalize-space(text())=:label]', array(':label' => $label));
    $message = ($message ?  $message : t('Link with label %label found.', array('%label' => $label)));
    return $this->assertTrue(isset($links[$index]), $message, $group);
  }

  /**
   * Pass if a link with the specified label is not found.
   *
   * @param $label
   *   Text between the anchor tags.
   * @param $index
   *   Link position counting from zero.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertNoLink($label, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[normalize-space(text())=:label]', array(':label' => $label));
    $message = ($message ?  $message : t('Link with label %label not found.', array('%label' => $label)));
    return $this->assertTrue(empty($links), $message, $group);
  }

  /**
   * Pass if a link containing a given href (part) is found.
   *
   * @param $href
   *   The full or partial value of the 'href' attribute of the anchor tag.
   * @param $index
   *   Link position counting from zero.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   *
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertLinkByHref($href, $index = 0, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[contains(@href, :href)]', array(':href' => $href));
    $message = ($message ?  $message : t('Link containing href %href found.', array('%href' => $href)));
    return $this->assertTrue(isset($links[$index]), $message, $group);
  }

  /**
   * Pass if a link containing a given href (part) is not found.
   *
   * @param $href
   *   The full or partial value of the 'href' attribute of the anchor tag.
   * @param $message
   *   Message to display.
   * @param $group
   *   The group this message belongs to, defaults to 'Other'.
   *
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertNoLinkByHref($href, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[contains(@href, :href)]', array(':href' => $href));
    $message = ($message ?  $message : t('No link containing href %href found.', array('%href' => $href)));
    return $this->assertTrue(empty($links), $message, $group);
  }

  function verbose($message) {
    if (strlen($message) < 500) {
      // $this->log($message, 'verbose');
    }
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

  /**
   * Create a user with a given set of permissions. The permissions correspond to the
   * names given on the privileges page.
   *
   * @param $permissions
   *   Array of permission names to assign to user.
   * @return
   *   A fully loaded user object with pass_raw property, or FALSE if account
   *   creation fails.
   */
  protected function drupalCreateUser($permissions = array('access comments', 'access content', 'post comments', 'skip comment approval')) {
    // Create a role with the given permission set.
    if (!($rid = $this->drupalCreateRole($permissions))) {
      return FALSE;
    }

    // Create a user assigned to that role.
    $edit = array();
    $edit['name']   = $this->randomName();
    $edit['mail']   = $edit['name'] . '@example.com';
    $edit['roles']  = array($rid => $rid);
    $edit['pass']   = user_password();
    $edit['status'] = 1;

    $account = user_save(drupal_anonymous_user(), $edit);

    $this->assertTrue(!empty($account->uid), t('User created with name %name and pass %pass', array('%name' => $edit['name'], '%pass' => $edit['pass'])), t('User login'));
    if (empty($account->uid)) {
      return FALSE;
    }

    // Add the raw password so that we can log in as this user.
    $account->pass_raw = $edit['pass'];
    return $account;
  }

  /**
   * Internal helper function; Create a role with specified permissions.
   *
   * @param $permissions
   *   Array of permission names to assign to role.
   * @param $name
   *   (optional) String for the name of the role.  Defaults to a random string.
   * @return
   *   Role ID of newly created role, or FALSE if role creation failed.
   */
  protected function drupalCreateRole(array $permissions, $name = NULL) {
    // Generate random name if it was not passed.
    if (!$name) {
      $name = $this->randomName();
    }

    // Check the all the permissions strings are valid.
    if (!$this->checkPermissions($permissions)) {
      return FALSE;
    }

    // Create new role.
    $role = new stdClass();
    $role->name = $name;
    user_role_save($role);
    user_role_grant_permissions($role->rid, $permissions);

    $this->assertTrue(isset($role->rid), t('Created role of name: @name, id: @rid', array('@name' => $name, '@rid' => (isset($role->rid) ? $role->rid : t('-n/a-')))), t('Role'));
    if ($role && !empty($role->rid)) {
      $count = db_query('SELECT COUNT(*) FROM {role_permission} WHERE rid = :rid', array(':rid' => $role->rid))->fetchField();
      $this->assertTrue($count == count($permissions), t('Created permissions: @perms', array('@perms' => implode(', ', $permissions))), t('Role'));
      return $role->rid;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Retrieves a Drupal path or an absolute path.
   *
   * @param $path
   *   Drupal path or URL to load into internal browser
   * @param $options
   *   Options to be forwarded to url().
   * @param $headers
   *   An array containing additional HTTP request headers, each formatted as
   *   "name: value".
   * @return
   *   The retrieved HTML string, also available as $this->drupalGetContent()
   */
  protected function drupalGet($path, array $options = array(), array $headers = array()) {
    $options['absolute'] = TRUE;

    // We re-using a CURL connection here. If that connection still has certain
    // options set, it might change the GET into a POST. Make sure we clear out
    // previous options.
    $out = $this->curlExec(array(CURLOPT_HTTPGET => TRUE, CURLOPT_URL => url($path, $options), CURLOPT_NOBODY => FALSE, CURLOPT_HTTPHEADER => $headers));
    $this->refreshVariables(); // Ensure that any changes to variables in the other thread are picked up.

    // Replace original page output with new output from redirected page(s).
    if ($new = $this->checkForMetaRefresh()) {
      $out = $new;
    }
    $this->verbose('GET request to: ' . $path .
                   '<hr />Ending URL: ' . $this->getUrl() .
                   '<hr />' . $out);
    return $out;
  }

  /**
   * Initializes the cURL connection.
   *
   * If the simpletest_httpauth_credentials variable is set, this function will
   * add HTTP authentication headers. This is necessary for testing sites that
   * are protected by login credentials from public access.
   * See the description of $curl_options for other options.
   */
  protected function curlInitialize() {
    global $base_url;

    if (!isset($this->curlHandle)) {
      $this->curlHandle = curl_init();
      $curl_options = array(
        CURLOPT_COOKIEJAR => $this->cookieFile,
        CURLOPT_URL => $base_url,
        CURLOPT_FOLLOWLOCATION => FALSE,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_SSL_VERIFYPEER => FALSE, // Required to make the tests run on https.
        CURLOPT_SSL_VERIFYHOST => FALSE, // Required to make the tests run on https.
        CURLOPT_HEADERFUNCTION => array(&$this, 'curlHeaderCallback'),
        CURLOPT_USERAGENT => 'Upal',
      );
      if (isset($this->httpauth_credentials)) {
        $curl_options[CURLOPT_HTTPAUTH] = $this->httpauth_method;
        $curl_options[CURLOPT_USERPWD] = $this->httpauth_credentials;
      }
      curl_setopt_array($this->curlHandle, $this->additionalCurlOptions + $curl_options);

      // By default, the child session name should be the same as the parent.
      $this->session_name = session_name();
    }
    // We set the user agent header on each request so as to use the current
    // time and a new uniqid.
    //if (preg_match('/simpletest\d+/', $this->databasePrefix, $matches)) {
    //  curl_setopt($this->curlHandle, CURLOPT_USERAGENT, drupal_generate_test_ua($matches[0]));
    //}
  }

  /**
   * Initializes and executes a cURL request.
   *
   * @param $curl_options
   *   An associative array of cURL options to set, where the keys are constants
   *   defined by the cURL library. For a list of valid options, see
   *   http://www.php.net/manual/function.curl-setopt.php
   * @param $redirect
   *   FALSE if this is an initial request, TRUE if this request is the result
   *   of a redirect.
   *
   * @return
   *   The content returned from the call to curl_exec().
   *
   * @see curlInitialize()
   */
  protected function curlExec($curl_options, $redirect = FALSE) {
    $this->curlInitialize();

    // cURL incorrectly handles URLs with a fragment by including the
    // fragment in the request to the server, causing some web servers
    // to reject the request citing "400 - Bad Request". To prevent
    // this, we strip the fragment from the request.
    // TODO: Remove this for Drupal 8, since fixed in curl 7.20.0.
    if (!empty($curl_options[CURLOPT_URL]) && strpos($curl_options[CURLOPT_URL], '#')) {
      $original_url = $curl_options[CURLOPT_URL];
      $curl_options[CURLOPT_URL] = strtok($curl_options[CURLOPT_URL], '#');
    }

    $url = empty($curl_options[CURLOPT_URL]) ? curl_getinfo($this->curlHandle, CURLINFO_EFFECTIVE_URL) : $curl_options[CURLOPT_URL];

    if (!empty($curl_options[CURLOPT_POST])) {
      // This is a fix for the Curl library to prevent Expect: 100-continue
      // headers in POST requests, that may cause unexpected HTTP response
      // codes from some webservers (like lighttpd that returns a 417 error
      // code). It is done by setting an empty "Expect" header field that is
      // not overwritten by Curl.
      $curl_options[CURLOPT_HTTPHEADER][] = 'Expect:';
    }
    curl_setopt_array($this->curlHandle, $this->additionalCurlOptions + $curl_options);

    if (!$redirect) {
      // Reset headers, the session ID and the redirect counter.
      $this->session_id = NULL;
      $this->headers = array();
      $this->redirect_count = 0;
    }

    $content = curl_exec($this->curlHandle);
    $status = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);

    // cURL incorrectly handles URLs with fragments, so instead of
    // letting cURL handle redirects we take of them ourselves to
    // to prevent fragments being sent to the web server as part
    // of the request.
    // TODO: Remove this for Drupal 8, since fixed in curl 7.20.0.
    if (in_array($status, array(300, 301, 302, 303, 305, 307)) && $this->redirect_count < variable_get('simpletest_maximum_redirects', 5)) {
      if ($this->drupalGetHeader('location')) {
        $this->redirect_count++;
        $curl_options = array();
        $curl_options[CURLOPT_URL] = $this->drupalGetHeader('location');
        $curl_options[CURLOPT_HTTPGET] = TRUE;
        return $this->curlExec($curl_options, TRUE);
      }
    }

    $this->drupalSetContent($content, isset($original_url) ? $original_url : curl_getinfo($this->curlHandle, CURLINFO_EFFECTIVE_URL));
    $message_vars = array(
      '!method' => !empty($curl_options[CURLOPT_NOBODY]) ? 'HEAD' : (empty($curl_options[CURLOPT_POSTFIELDS]) ? 'GET' : 'POST'),
      '@url' => isset($original_url) ? $original_url : $url,
      '@status' => $status,
      '!length' => format_size(strlen($this->drupalGetContent()))
    );
    $message = t('!method @url returned @status (!length).', $message_vars);
    $this->assertTrue($this->drupalGetContent() !== FALSE, $message, t('Browser'));
    return $this->drupalGetContent();
  }

  /**
   * Reads headers and registers errors received from the tested site.
   *
   * @see _drupal_log_error().
   *
   * @param $curlHandler
   *   The cURL handler.
   * @param $header
   *   An header.
   */
  protected function curlHeaderCallback($curlHandler, $header) {
    $this->headers[] = $header;

    // Errors are being sent via X-Drupal-Assertion-* headers,
    // generated by _drupal_log_error() in the exact form required
    // by DrupalWebTestCase::error().
    if (preg_match('/^X-Drupal-Assertion-[0-9]+: (.*)$/', $header, $matches)) {
      // Call DrupalWebTestCase::error() with the parameters from the header.
      call_user_func_array(array(&$this, 'error'), unserialize(urldecode($matches[1])));
    }

    // Save cookies.
    if (preg_match('/^Set-Cookie: ([^=]+)=(.+)/', $header, $matches)) {
      $name = $matches[1];
      $parts = array_map('trim', explode(';', $matches[2]));
      $value = array_shift($parts);
      $this->cookies[$name] = array('value' => $value, 'secure' => in_array('secure', $parts));
      if ($name == $this->session_name) {
        if ($value != 'deleted') {
          $this->session_id = $value;
        }
        else {
          $this->session_id = NULL;
        }
      }
    }

    // This is required by cURL.
    return strlen($header);
  }

  /**
   * Close the cURL handler and unset the handler.
   */
  protected function curlClose() {
    if (isset($this->curlHandle)) {
      curl_close($this->curlHandle);
      unset($this->curlHandle);
    }
  }

  /**
   * Parse content returned from curlExec using DOM and SimpleXML.
   *
   * @return
   *   A SimpleXMLElement or FALSE on failure.
   */
  protected function parse() {
    if (!$this->elements) {
      // DOM can load HTML soup. But, HTML soup can throw warnings, suppress
      // them.
      $htmlDom = new DOMDocument();
      @$htmlDom->loadHTML($this->drupalGetContent());
      if ($htmlDom) {
        $this->pass(t('Valid HTML found on "@path"', array('@path' => $this->getUrl())), t('Browser'));
        // It's much easier to work with simplexml than DOM, luckily enough
        // we can just simply import our DOM tree.
        $this->elements = simplexml_import_dom($htmlDom);
      }
    }
    if (!$this->elements) {
      $this->fail(t('Parsed page successfully.'), t('Browser'));
    }

    return $this->elements;
  }

  /**
   * Generate a token for the currently logged in user.
   */
  protected function drupalGetToken($value = '') {
    $private_key = drupal_get_private_key();
    return drupal_hmac_base64($value, $this->session_id . $private_key);
  }

  /**
   * Execute a POST request on a Drupal page.
   * It will be done as usual POST request with SimpleBrowser.
   *
   * @param $path
   *   Location of the post form. Either a Drupal path or an absolute path or
   *   NULL to post to the current page. For multi-stage forms you can set the
   *   path to NULL and have it post to the last received page. Example:
   *
   *   @code
   *   // First step in form.
   *   $edit = array(...);
   *   $this->drupalPost('some_url', $edit, t('Save'));
   *
   *   // Second step in form.
   *   $edit = array(...);
   *   $this->drupalPost(NULL, $edit, t('Save'));
   *   @endcode
   * @param  $edit
   *   Field data in an associative array. Changes the current input fields
   *   (where possible) to the values indicated. A checkbox can be set to
   *   TRUE to be checked and FALSE to be unchecked. Note that when a form
   *   contains file upload fields, other fields cannot start with the '@'
   *   character.
   *
   *   Multiple select fields can be set using name[] and setting each of the
   *   possible values. Example:
   *   @code
   *   $edit = array();
   *   $edit['name[]'] = array('value1', 'value2');
   *   @endcode
   * @param $submit
   *   Value of the submit button whose click is to be emulated. For example,
   *   t('Save'). The processing of the request depends on this value. For
   *   example, a form may have one button with the value t('Save') and another
   *   button with the value t('Delete'), and execute different code depending
   *   on which one is clicked.
   *
   *   This function can also be called to emulate an Ajax submission. In this
   *   case, this value needs to be an array with the following keys:
   *   - path: A path to submit the form values to for Ajax-specific processing,
   *     which is likely different than the $path parameter used for retrieving
   *     the initial form. Defaults to 'system/ajax'.
   *   - triggering_element: If the value for the 'path' key is 'system/ajax' or
   *     another generic Ajax processing path, this needs to be set to the name
   *     of the element. If the name doesn't identify the element uniquely, then
   *     this should instead be an array with a single key/value pair,
   *     corresponding to the element name and value. The callback for the
   *     generic Ajax processing path uses this to find the #ajax information
   *     for the element, including which specific callback to use for
   *     processing the request.
   *
   *   This can also be set to NULL in order to emulate an Internet Explorer
   *   submission of a form with a single text field, and pressing ENTER in that
   *   textfield: under these conditions, no button information is added to the
   *   POST data.
   * @param $options
   *   Options to be forwarded to url().
   * @param $headers
   *   An array containing additional HTTP request headers, each formatted as
   *   "name: value".
   * @param $form_html_id
   *   (optional) HTML ID of the form to be submitted. On some pages
   *   there are many identical forms, so just using the value of the submit
   *   button is not enough. For example: 'trigger-node-presave-assign-form'.
   *   Note that this is not the Drupal $form_id, but rather the HTML ID of the
   *   form, which is typically the same thing but with hyphens replacing the
   *   underscores.
   * @param $extra_post
   *   (optional) A string of additional data to append to the POST submission.
   *   This can be used to add POST data for which there are no HTML fields, as
   *   is done by drupalPostAJAX(). This string is literally appended to the
   *   POST data, so it must already be urlencoded and contain a leading "&"
   *   (e.g., "&extra_var1=hello+world&extra_var2=you%26me").
   */
  protected function drupalPost($path, $edit, $submit, array $options = array(), array $headers = array(), $form_html_id = NULL, $extra_post = NULL) {
    $submit_matches = FALSE;
    $ajax = is_array($submit);
    if (isset($path)) {
      $this->drupalGet($path, $options);
    }
    if ($this->parse()) {
      $edit_save = $edit;
      // Let's iterate over all the forms.
      $xpath = "//form";
      if (!empty($form_html_id)) {
        $xpath .= "[@id='" . $form_html_id . "']";
      }
      $forms = $this->xpath($xpath);
      foreach ($forms as $form) {
        // We try to set the fields of this form as specified in $edit.
        $edit = $edit_save;
        $post = array();
        $upload = array();
        $submit_matches = $this->handleForm($post, $edit, $upload, $ajax ? NULL : $submit, $form);
        $action = isset($form['action']) ? $this->getAbsoluteUrl((string) $form['action']) : $this->getUrl();
        if ($ajax) {
          $action = $this->getAbsoluteUrl(!empty($submit['path']) ? $submit['path'] : 'system/ajax');
          // Ajax callbacks verify the triggering element if necessary, so while
          // we may eventually want extra code that verifies it in the
          // handleForm() function, it's not currently a requirement.
          $submit_matches = TRUE;
        }

        // We post only if we managed to handle every field in edit and the
        // submit button matches.
        if (!$edit && ($submit_matches || !isset($submit))) {
          $post_array = $post;
          if ($upload) {
            // TODO: cURL handles file uploads for us, but the implementation
            // is broken. This is a less than elegant workaround. Alternatives
            // are being explored at #253506.
            foreach ($upload as $key => $file) {
              $file = drupal_realpath($file);
              if ($file && is_file($file)) {
                $post[$key] = '@' . $file;
              }
            }
          }
          else {
            foreach ($post as $key => $value) {
              // Encode according to application/x-www-form-urlencoded
              // Both names and values needs to be urlencoded, according to
              // http://www.w3.org/TR/html4/interact/forms.html#h-17.13.4.1
              $post[$key] = urlencode($key) . '=' . urlencode($value);
            }
            $post = implode('&', $post) . $extra_post;
          }
          $out = $this->curlExec(array(CURLOPT_URL => $action, CURLOPT_POST => TRUE, CURLOPT_POSTFIELDS => $post, CURLOPT_HTTPHEADER => $headers));
          // Ensure that any changes to variables in the other thread are picked up.
          $this->refreshVariables();

          // Replace original page output with new output from redirected page(s).
          if ($new = $this->checkForMetaRefresh()) {
            $out = $new;
          }
          $this->verbose('POST request to: ' . $path .
                         '<hr />Ending URL: ' . $this->getUrl() .
                         '<hr />Fields: ' . highlight_string('<?php ' . var_export($post_array, TRUE), TRUE) .
                         '<hr />' . $out);
          return $out;
        }
      }
      // We have not found a form which contained all fields of $edit.
      foreach ($edit as $name => $value) {
        $this->fail(t('Failed to set field @name to @value', array('@name' => $name, '@value' => $value)));
      }
      if (!$ajax && isset($submit)) {
        $this->assertTrue($submit_matches, t('Found the @submit button', array('@submit' => $submit)));
      }
      $this->fail(t('Found the requested form fields at @path', array('@path' => $path)));
    }
  }

  /**
   * Check to make sure that the array of permissions are valid.
   *
   * @param $permissions
   *   Permissions to check.
   * @param $reset
   *   Reset cached available permissions.
   * @return
   *   TRUE or FALSE depending on whether the permissions are valid.
   */
  protected function checkPermissions(array $permissions, $reset = FALSE) {
    $available = &drupal_static(__FUNCTION__);

    if (!isset($available) || $reset) {
      $available = array_keys(module_invoke_all('permission'));
    }

    $valid = TRUE;
    foreach ($permissions as $permission) {
      if (!in_array($permission, $available)) {
        $this->fail(t('Invalid permission %permission.', array('%permission' => $permission)), t('Role'));
        $valid = FALSE;
      }
    }
    return $valid;
  }

  /**
   * Log in a user with the internal browser.
   *
   * If a user is already logged in, then the current user is logged out before
   * logging in the specified user.
   *
   * Please note that neither the global $user nor the passed in user object is
   * populated with data of the logged in user. If you need full access to the
   * user object after logging in, it must be updated manually. If you also need
   * access to the plain-text password of the user (set by drupalCreateUser()),
   * e.g. to log in the same user again, then it must be re-assigned manually.
   * For example:
   * @code
   *   // Create a user.
   *   $account = $this->drupalCreateUser(array());
   *   $this->drupalLogin($account);
   *   // Load real user object.
   *   $pass_raw = $account->pass_raw;
   *   $account = user_load($account->uid);
   *   $account->pass_raw = $pass_raw;
   * @endcode
   *
   * @param $user
   *   User object representing the user to log in.
   *
   * @see drupalCreateUser()
   */
  protected function drupalLogin(stdClass $user) {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $edit = array(
      'name' => $user->name,
      'pass' => $user->pass_raw
    );
    $this->drupalPost('user', $edit, t('Log in'));

    // If a "log out" link appears on the page, it is almost certainly because
    // the login was successful.
    $pass = $this->assertLink(t('Log out'), 0, t('User %name successfully logged in.', array('%name' => $user->name)), t('User login'));

    if (1 || $pass) {
      // TODO: declare this var
      $this->loggedInUser = $user;
    }
  }

  /*
   * Logs a user out of the internal browser, then check the login page to confirm logout.
   */
  protected function drupalLogout() {
    // Make a request to the logout page, and redirect to the user page, the
    // idea being if you were properly logged out you should be seeing a login
    // screen.
    $this->drupalGet('user/logout');
    $this->drupalGet('user');
    $pass = $this->assertField('name', t('Username field found.'), t('Logout'));
    $pass = $pass && $this->assertField('pass', t('Password field found.'), t('Logout'));

    if ($pass) {
      $this->loggedInUser = FALSE;
    }
  }

  /**
   * Creates a node based on default settings.
   *
   * @param $settings
   *   An associative array of settings to change from the defaults, keys are
   *   node properties, for example 'title' => 'Hello, world!'.
   * @return
   *   Created node object.
   */
  protected function drupalCreateNode($settings = array()) {
    // Populate defaults array.
    $settings += array(
      'body'      => array(LANGUAGE_NONE => array(array())),
      'title'     => $this->randomName(8),
      'comment'   => 2,
      'changed'   => REQUEST_TIME,
      'moderate'  => 0,
      'promote'   => 0,
      'revision'  => 1,
      'log'       => '',
      'status'    => 1,
      'sticky'    => 0,
      'type'      => 'page',
      'revisions' => NULL,
      'language'  => LANGUAGE_NONE,
    );

    // Use the original node's created time for existing nodes.
    if (isset($settings['created']) && !isset($settings['date'])) {
      $settings['date'] = format_date($settings['created'], 'custom', 'Y-m-d H:i:s O');
    }

    // If the node's user uid is not specified manually, use the currently
    // logged in user if available, or else the user running the test.
    if (!isset($settings['uid'])) {
      if ($this->loggedInUser) {
        $settings['uid'] = $this->loggedInUser->uid;
      }
      else {
        global $user;
        $settings['uid'] = $user->uid;
      }
    }

    // Merge body field value and format separately.
    $body = array(
      'value' => $this->randomName(32),
      'format' => filter_default_format(),
    );
    $settings['body'][$settings['language']][0] += $body;

    $node = (object) $settings;
    node_save($node);

    // Small hack to link revisions to our test user.
    db_update('node_revision')
      ->fields(array('uid' => $node->uid))
      ->condition('vid', $node->vid)
      ->execute();
    return $node;
  }

  /**
   * Follows a link by name.
   *
   * Will click the first link found with this link text by default, or a
   * later one if an index is given. Match is case insensitive with
   * normalized space. The label is translated label. There is an assert
   * for successful click.
   *
   * @param $label
   *   Text between the anchor tags.
   * @param $index
   *   Link position counting from zero.
   * @return
   *   Page on success, or FALSE on failure.
   */
  protected function clickLink($label, $index = 0) {
    $url_before = $this->getUrl();
    $urls = $this->xpath('//a[normalize-space(text())=:label]', array(':label' => $label));

    if (isset($urls[$index])) {
      $url_target = $this->getAbsoluteUrl($urls[$index]['href']);
    }

    $this->assertTrue(isset($urls[$index]), t('Clicked link %label (@url_target) from @url_before', array('%label' => $label, '@url_target' => $url_target, '@url_before' => $url_before)), t('Browser'));

    if (isset($url_target)) {
      return $this->drupalGet($url_target);
    }
    return FALSE;
  }

  /**
   * Takes a path and returns an absolute path.
   *
   * @param $path
   *   A path from the internal browser content.
   * @return
   *   The $path with $base_url prepended, if necessary.
   */
  protected function getAbsoluteUrl($path) {
    global $base_url, $base_path;

    $parts = parse_url($path);
    if (empty($parts['host'])) {
      // Ensure that we have a string (and no xpath object).
      $path = (string) $path;
      // Strip $base_path, if existent.
      $length = strlen($base_path);
      if (substr($path, 0, $length) === $base_path) {
        $path = substr($path, $length);
      }
      // Ensure that we have an absolute path.
      if ($path[0] !== '/') {
        $path = '/' . $path;
      }
      // Finally, prepend the $base_url.
      $path = $base_url . $path;
    }
    return $path;
  }

  /**
   * Get the current url from the cURL handler.
   *
   * @return
   *   The current url.
   */
  protected function getUrl() {
    return $this->url;
  }

  /**
   * Gets the HTTP response headers of the requested page. Normally we are only
   * interested in the headers returned by the last request. However, if a page
   * is redirected or HTTP authentication is in use, multiple requests will be
   * required to retrieve the page. Headers from all requests may be requested
   * by passing TRUE to this function.
   *
   * @param $all_requests
   *   Boolean value specifying whether to return headers from all requests
   *   instead of just the last request. Defaults to FALSE.
   * @return
   *   A name/value array if headers from only the last request are requested.
   *   If headers from all requests are requested, an array of name/value
   *   arrays, one for each request.
   *
   *   The pseudonym ":status" is used for the HTTP status line.
   *
   *   Values for duplicate headers are stored as a single comma-separated list.
   */
  protected function drupalGetHeaders($all_requests = FALSE) {
    $request = 0;
    $headers = array($request => array());
    foreach ($this->headers as $header) {
      $header = trim($header);
      if ($header === '') {
        $request++;
      }
      else {
        if (strpos($header, 'HTTP/') === 0) {
          $name = ':status';
          $value = $header;
        }
        else {
          list($name, $value) = explode(':', $header, 2);
          $name = strtolower($name);
        }
        if (isset($headers[$request][$name])) {
          $headers[$request][$name] .= ',' . trim($value);
        }
        else {
          $headers[$request][$name] = trim($value);
        }
      }
    }
    if (!$all_requests) {
      $headers = array_pop($headers);
    }
    return $headers;
  }

  /**
   * Gets the value of an HTTP response header. If multiple requests were
   * required to retrieve the page, only the headers from the last request will
   * be checked by default. However, if TRUE is passed as the second argument,
   * all requests will be processed from last to first until the header is
   * found.
   *
   * @param $name
   *   The name of the header to retrieve. Names are case-insensitive (see RFC
   *   2616 section 4.2).
   * @param $all_requests
   *   Boolean value specifying whether to check all requests if the header is
   *   not found in the last request. Defaults to FALSE.
   * @return
   *   The HTTP header value or FALSE if not found.
   */
  protected function drupalGetHeader($name, $all_requests = FALSE) {
    $name = strtolower($name);
    $header = FALSE;
    if ($all_requests) {
      foreach (array_reverse($this->drupalGetHeaders(TRUE)) as $headers) {
        if (isset($headers[$name])) {
          $header = $headers[$name];
          break;
        }
      }
    }
    else {
      $headers = $this->drupalGetHeaders();
      if (isset($headers[$name])) {
        $header = $headers[$name];
      }
    }
    return $header;
  }

  /**
   * Gets the current raw HTML of requested page.
   */
  protected function drupalGetContent() {
    return $this->content;
  }

  /**
   * Gets the value of the Drupal.settings JavaScript variable for the currently loaded page.
   */
  protected function drupalGetSettings() {
    return $this->drupalSettings;
  }

  /**
   * Gets an array containing all e-mails sent during this test case.
   *
   * @param $filter
   *   An array containing key/value pairs used to filter the e-mails that are returned.
   * @return
   *   An array containing e-mail messages captured during the current test.
   */
  protected function drupalGetMails($filter = array()) {
    $captured_emails = variable_get('drupal_test_email_collector', array());
    $filtered_emails = array();

    foreach ($captured_emails as $message) {
      foreach ($filter as $key => $value) {
        if (!isset($message[$key]) || $message[$key] != $value) {
          continue 2;
        }
      }
      $filtered_emails[] = $message;
    }

    return $filtered_emails;
  }

  /**
   * Check for meta refresh tag and if found call drupalGet() recursively. This
   * function looks for the http-equiv attribute to be set to "Refresh"
   * and is case-sensitive.
   *
   * @return
   *   Either the new page content or FALSE.
   */
  protected function checkForMetaRefresh() {
    if (strpos($this->drupalGetContent(), '<meta ') && $this->parse()) {
      $refresh = $this->xpath('//meta[@http-equiv="Refresh"]');
      if (!empty($refresh)) {
        // Parse the content attribute of the meta tag for the format:
        // "[delay]: URL=[page_to_redirect_to]".
        if (preg_match('/\d+;\s*URL=(?P<url>.*)/i', $refresh[0]['content'], $match)) {
          return $this->drupalGet($this->getAbsoluteUrl(decode_entities($match['url'])));
        }
      }
    }
    return FALSE;
  }

  /**
   * Retrieves only the headers for a Drupal path or an absolute path.
   *
   * @param $path
   *   Drupal path or URL to load into internal browser
   * @param $options
   *   Options to be forwarded to url().
   * @param $headers
   *   An array containing additional HTTP request headers, each formatted as
   *   "name: value".
   * @return
   *   The retrieved headers, also available as $this->drupalGetContent()
   */
  protected function drupalHead($path, array $options = array(), array $headers = array()) {
    $options['absolute'] = TRUE;
    $out = $this->curlExec(array(CURLOPT_NOBODY => TRUE, CURLOPT_URL => url($path, $options), CURLOPT_HTTPHEADER => $headers));
    $this->refreshVariables(); // Ensure that any changes to variables in the other thread are picked up.
    return $out;
  }

  /**
   * Handle form input related to drupalPost(). Ensure that the specified fields
   * exist and attempt to create POST data in the correct manner for the particular
   * field type.
   *
   * @param $post
   *   Reference to array of post values.
   * @param $edit
   *   Reference to array of edit values to be checked against the form.
   * @param $submit
   *   Form submit button value.
   * @param $form
   *   Array of form elements.
   * @return
   *   Submit value matches a valid submit input in the form.
   */
  protected function handleForm(&$post, &$edit, &$upload, $submit, $form) {
    // Retrieve the form elements.
    $elements = $form->xpath('.//input[not(@disabled)]|.//textarea[not(@disabled)]|.//select[not(@disabled)]');
    $submit_matches = FALSE;
    foreach ($elements as $element) {
      // SimpleXML objects need string casting all the time.
      $name = (string) $element['name'];
      // This can either be the type of <input> or the name of the tag itself
      // for <select> or <textarea>.
      $type = isset($element['type']) ? (string) $element['type'] : $element->getName();
      $value = isset($element['value']) ? (string) $element['value'] : '';
      $done = FALSE;
      if (isset($edit[$name])) {
        switch ($type) {
          case 'text':
          case 'textarea':
          case 'hidden':
          case 'password':
            $post[$name] = $edit[$name];
            unset($edit[$name]);
            break;
          case 'radio':
            if ($edit[$name] == $value) {
              $post[$name] = $edit[$name];
              unset($edit[$name]);
            }
            break;
          case 'checkbox':
            // To prevent checkbox from being checked.pass in a FALSE,
            // otherwise the checkbox will be set to its value regardless
            // of $edit.
            if ($edit[$name] === FALSE) {
              unset($edit[$name]);
              continue 2;
            }
            else {
              unset($edit[$name]);
              $post[$name] = $value;
            }
            break;
          case 'select':
            $new_value = $edit[$name];
            $options = $this->getAllOptions($element);
            if (is_array($new_value)) {
              // Multiple select box.
              if (!empty($new_value)) {
                $index = 0;
                $key = preg_replace('/\[\]$/', '', $name);
                foreach ($options as $option) {
                  $option_value = (string) $option['value'];
                  if (in_array($option_value, $new_value)) {
                    $post[$key . '[' . $index++ . ']'] = $option_value;
                    $done = TRUE;
                    unset($edit[$name]);
                  }
                }
              }
              else {
                // No options selected: do not include any POST data for the
                // element.
                $done = TRUE;
                unset($edit[$name]);
              }
            }
            else {
              // Single select box.
              foreach ($options as $option) {
                if ($new_value == $option['value']) {
                  $post[$name] = $new_value;
                  unset($edit[$name]);
                  $done = TRUE;
                  break;
                }
              }
            }
            break;
          case 'file':
            $upload[$name] = $edit[$name];
            unset($edit[$name]);
            break;
        }
      }
      if (!isset($post[$name]) && !$done) {
        switch ($type) {
          case 'textarea':
            $post[$name] = (string) $element;
            break;
          case 'select':
            $single = empty($element['multiple']);
            $first = TRUE;
            $index = 0;
            $key = preg_replace('/\[\]$/', '', $name);
            $options = $this->getAllOptions($element);
            foreach ($options as $option) {
              // For single select, we load the first option, if there is a
              // selected option that will overwrite it later.
              if ($option['selected'] || ($first && $single)) {
                $first = FALSE;
                if ($single) {
                  $post[$name] = (string) $option['value'];
                }
                else {
                  $post[$key . '[' . $index++ . ']'] = (string) $option['value'];
                }
              }
            }
            break;
          case 'file':
            break;
          case 'submit':
          case 'image':
            if (isset($submit) && $submit == $value) {
              $post[$name] = $value;
              $submit_matches = TRUE;
            }
            break;
          case 'radio':
          case 'checkbox':
            if (!isset($element['checked'])) {
              break;
            }
            // Deliberate no break.
          default:
            $post[$name] = $value;
        }
      }
    }
    return $submit_matches;
  }

  /**
   * Builds an XPath query.
   *
   * Builds an XPath query by replacing placeholders in the query by the value
   * of the arguments.
   *
   * XPath 1.0 (the version supported by libxml2, the underlying XML library
   * used by PHP) doesn't support any form of quotation. This function
   * simplifies the building of XPath expression.
   *
   * @param $xpath
   *   An XPath query, possibly with placeholders in the form ':name'.
   * @param $args
   *   An array of arguments with keys in the form ':name' matching the
   *   placeholders in the query. The values may be either strings or numeric
   *   values.
   * @return
   *   An XPath query with arguments replaced.
   */
  protected function buildXPathQuery($xpath, array $args = array()) {
    // Replace placeholders.
    foreach ($args as $placeholder => $value) {
      // XPath 1.0 doesn't support a way to escape single or double quotes in a
      // string literal. We split double quotes out of the string, and encode
      // them separately.
      if (is_string($value)) {
        // Explode the text at the quote characters.
        $parts = explode('"', $value);

        // Quote the parts.
        foreach ($parts as &$part) {
          $part = '"' . $part . '"';
        }

        // Return the string.
        $value = count($parts) > 1 ? 'concat(' . implode(', \'"\', ', $parts) . ')' : $parts[0];
      }
      $xpath = preg_replace('/' . preg_quote($placeholder) . '\b/', $value, $xpath);
    }
    return $xpath;
  }

  /**
   * Perform an xpath search on the contents of the internal browser. The search
   * is relative to the root element (HTML tag normally) of the page.
   *
   * @param $xpath
   *   The xpath string to use in the search.
   * @return
   *   The return value of the xpath search. For details on the xpath string
   *   format and return values see the SimpleXML documentation,
   *   http://us.php.net/manual/function.simplexml-element-xpath.php.
   */
  protected function xpath($xpath, array $arguments = array()) {
    if ($this->parse()) {
      $xpath = $this->buildXPathQuery($xpath, $arguments);
      $result = $this->elements->xpath($xpath);
      // Some combinations of PHP / libxml versions return an empty array
      // instead of the documented FALSE. Forcefully convert any falsish values
      // to an empty array to allow foreach(...) constructions.
      return $result ? $result : array();
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get all option elements, including nested options, in a select.
   *
   * @param $element
   *   The element for which to get the options.
   * @return
   *   Option elements in select.
   */
  protected function getAllOptions(SimpleXMLElement $element) {
    $options = array();
    // Add all options items.
    foreach ($element->option as $option) {
      $options[] = $option;
    }

    // Search option group children.
    if (isset($element->optgroup)) {
      foreach ($element->optgroup as $group) {
        $options = array_merge($options, $this->getAllOptions($group));
      }
    }
    return $options;
  }

  /**
   * Reset all data structures after having enabled new modules.
   *
   * This method is called by DrupalWebTestCase::setUp() after enabling
   * the requested modules. It must be called again when additional modules
   * are enabled later.
   */
  protected function resetAll() {
    // Reset all static variables.
    drupal_static_reset();
    // Reset the list of enabled modules.
    module_list(TRUE);

    // Reset cached schema for new database prefix. This must be done before
    // drupal_flush_all_caches() so rebuilds can make use of the schema of
    // modules enabled on the cURL side.
    drupal_get_schema(NULL, TRUE);

    // Perform rebuilds and flush remaining caches.
    drupal_flush_all_caches();

    // Reload global $conf array and permissions.
    $this->refreshVariables();
    $this->checkPermissions(array(), TRUE);
  }

  /**
   * Refresh the in-memory set of variables. Useful after a page request is made
   * that changes a variable in a different thread.
   *
   * In other words calling a settings page with $this->drupalPost() with a changed
   * value would update a variable to reflect that change, but in the thread that
   * made the call (thread running the test) the changed variable would not be
   * picked up.
   *
   * This method clears the variables cache and loads a fresh copy from the database
   * to ensure that the most up-to-date set of variables is loaded.
   */
  protected function refreshVariables() {
    global $conf;
    cache_clear_all('variables', 'cache_bootstrap');
    $conf = variable_initialize();
  }

  /**
   * Sets the raw HTML content. This can be useful when a page has been fetched
   * outside of the internal browser and assertions need to be made on the
   * returned page.
   *
   * A good example would be when testing drupal_http_request(). After fetching
   * the page the content can be set and page elements can be checked to ensure
   * that the function worked properly.
   */
  protected function drupalSetContent($content, $url = 'internal:') {
    $this->content = $content;
    $this->url = $url;
    $this->plainTextContent = FALSE;
    $this->elements = FALSE;
    $this->drupalSettings = array();
    if (preg_match('/jQuery\.extend\(Drupal\.settings, (.*?)\);/', $content, $matches)) {
      $this->drupalSettings = drupal_json_decode($matches[1]);
    }
  }

  /**
   * Sets the value of the Drupal.settings JavaScript variable for the currently loaded page.
   */
  protected function drupalSetSettings($settings) {
    $this->drupalSettings = $settings;
  }

 }

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

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', UPAL_ROOT);
    }
    $site = DRUPAL_ROOT . '/sites/upal';

    // Restore virgin files directory.
    $files_dir = "$site/files";
    if (file_exists($files_dir)) {
      exec('rm -rf ' . escapeshellarg($files_dir), $output, $return);
    }
    mkdir("$site/files", 0777, TRUE);

    // Restore virgin DB.
    $db = parse_url(UPAL_DB_URL);
    // TODO: replace with drush sql_query.
    if (isset($db['user'])) {
      $parts[] = '-u' . $db['user'];
    }
    if (isset($db['pass'])) {
      $parts[] = '-p' . $db['pass'];
    }
    $parts[] = '-h' . $db['host'];
    if (isset($db['port'])) {
      $parts[] = '-P' . $db['port'];
    }
    $parts[] = '-D' . trim($db['path'], '/');
    $cmd = 'mysql '. implode(' ', $parts) . ' < ' . dirname(__FILE__) . '/drupal-7.4-standard.sql';
    exec($cmd, $output, $return);

    $byline = '// Written by the Upal Test Framework. See DrupalWebTestCase::setUp().';
    // Write settings.php if needed.
    if (!file_exists("$site/settings.php")) {
      $db_array = array(
        'driver' => $db['scheme'],
        'database' => trim($db['path'], '/'),
        'username' => @$db['user'],
        'password' => @$db['pass'],
        'host' => $db['host'],
        'port' => @$db['port'],
      );
      $databases = "\$databases['default']['default'] = " . var_export($db_array, TRUE) . ';';
      $data = "<?php\n\n$byline\n$databases\n\n?>";
      file_put_contents("$site/settings.php", $data);
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
  define('UPAL_DB_URL', getenv('UPAL_DB_URL') ? getenv('UPAL_DB_URL') : (!empty($GLOBALS['UPAL_DB_URL']) ? $GLOBALS['UPAL_DB_URL'] : 'mysql://root:@127.0.0.1/upal'));

  // Make sure we use the right Drupal codebase.
  define('UPAL_ROOT', getenv('UPAL_ROOT') ? getenv('UPAL_ROOT') : (isset($GLOBALS['UPAL_ROOT']) ? $GLOBALS['UPAL_ROOT'] : realpath('.')));
  chdir(UPAL_ROOT);

  // The URL that browser based tests (ewwwww) should use.
  define('UPAL_WEB_URL', getenv('UPAL_WEB_URL') ? getenv('UPAL_WEB_URL') : (isset($GLOBALS['UPAL_WEB_URL']) ? $GLOBALS['UPAL_WEB_URL'] : 'http://upal/index.php'));
  $url = parse_url(UPAL_WEB_URL);
  $_SERVER['HTTP_HOST'] = $url['host'];
  $_SERVER['SCRIPT_NAME'] = $url['path'];
  $_SERVER['REMOTE_ADDR'] = $url['host'];
}

 // This code is in global scope.
 // TODO: I would rather this code at top of file, but I get Fatal error: Class 'Drush_TestCase' not found
 upal_init();
