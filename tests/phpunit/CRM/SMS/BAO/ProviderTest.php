<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */
/**
 *  Test CRM_SMS_BAO_Provider functions
 *
 * @package CiviCRM_APIv3
 * @subpackage API_Contribution
 * @group headless
 */
class CRM_SMS_BAO_ProviderTest extends CiviUnitTestCase {

  /**
   * Reference to option value in sms_provider_name group.
   *
   * @var int
   */
  private $optionValueID;

  /**
   * Set Up Funtion
   */
  public function setUp(): void {
    parent::setUp();
    $option = $this->callAPISuccess('option_value', 'create', ['option_group_id' => 'sms_provider_name', 'name' => 'test_provider_name', 'label' => 'test_provider_name', 'value' => 1]);
    $this->optionValueID = $option['id'];
  }

  /**
   * Clean up after each test.
   */
  public function tearDown(): void {
    parent::tearDown();
    $this->callAPISuccess('option_value', 'delete', ['id' => $this->optionValueID]);
  }

  /**
   * CRM-19961 Check that when saving and updating a SMS provider with domain as NULL that it stays null
   */
  public function testCreateAndUpdateProvider() {
    $values = [
      'domain_id' => NULL,
      'title' => 'test SMS provider',
      'username' => 'test',
      'password' => 'dummpy password',
      'name' => 1,
      'is_active' => 1,
      'api_type' => 1,
    ];
    $this->callAPISuccess('SmsProvider', 'create', $values);
    $provider = $this->callAPISuccess('SmsProvider', 'getsingle', ['title' => 'test SMS provider']);
    $domain_id = CRM_Core_DAO::getFieldValue('CRM_SMS_DAO_Provider', $provider['id'], 'domain_id');
    $this->assertNull($domain_id);
    $values2 = ['title' => 'Test SMS Provider2', 'id' => $provider['id']];
    $this->callAPISuccess('SmsProvider', 'create', $values2);
    $provider = $this->callAPISuccess('SmsProvider', 'getsingle', ['id' => $provider['id']]);
    $this->assertEquals('Test SMS Provider2', $provider['title']);
    $domain_id = CRM_Core_DAO::getFieldValue('CRM_SMS_DAO_Provider', $provider['id'], 'domain_id');
    $this->assertNull($domain_id);
    CRM_SMS_BAO_Provider::del($provider['id']);
  }

  /**
   * @see https://issues.civicrm.org/jira/browse/CRM-20989
   * Add unit test to ensure that filtering by domain works in get Active Providers
   */
  public function testActiveProviderCount() {
    $values = [
      'domain_id' => NULL,
      'title' => 'test SMS provider',
      'username' => 'test',
      'password' => 'dummpy password',
      'name' => 1,
      'is_active' => 1,
      'api_type' => 1,
    ];
    $provider = $this->callAPISuccess('SmsProvider', 'create', $values);
    $provider2 = $this->callAPISuccess('SmsProvider', 'create', array_merge($values, ['domain_id' => 2]));
    $result = CRM_SMS_BAO_Provider::activeProviderCount();
    $this->assertEquals(1, $result);
    $provider3 = $this->callAPISuccess('SmsProvider', 'create', array_merge($values, ['domain_id' => 1]));
    $result = CRM_SMS_BAO_Provider::activeProviderCount();
    $this->assertEquals(2, $result);
    CRM_SMS_BAO_Provider::del($provider['id']);
    CRM_SMS_BAO_Provider::del($provider2['id']);
    CRM_SMS_BAO_Provider::del($provider3['id']);
  }

  /**
   * CRM-19961 Check that when a domain is not passed when saving it defaults to current domain when create
   */
  public function testCreateWithoutDomain() {
    $values = [
      'title' => 'test SMS provider',
      'username' => 'test',
      'password' => 'dummpy password',
      'name' => 1,
      'is_active' => 1,
      'api_type' => 1,
    ];
    $this->callAPISuccess('SmsProvider', 'create', $values);
    $provider = $this->callAPISuccess('SmsProvider', 'getsingle', ['title' => 'test SMS provider']);
    $domain_id = CRM_Core_DAO::getFieldValue('CRM_SMS_DAO_Provider', $provider['id'], 'domain_id');
    $this->assertEquals(CRM_Core_Config::domainID(), $domain_id);
    CRM_SMS_BAO_Provider::del($provider['id']);
  }

}
