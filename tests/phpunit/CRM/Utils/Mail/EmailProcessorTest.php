<?php

/**
 * Class CRM_Utils_Mail_EmailProcessorTest
 * @group headless
 */
class CRM_Utils_Mail_EmailProcessorTest extends CiviUnitTestCase {

  /**
   * Event queue record.
   *
   * @var array
   */
  protected $eventQueue = [];

  /**
   * ID of our sample contact.
   *
   * @var int
   */
  protected $contactID;

  public function setUp(): void {
    parent::setUp();
    CRM_Utils_File::cleanDir(__DIR__ . '/data/mail');
    mkdir(__DIR__ . '/data/mail');
    $this->callAPISuccess('MailSettings', 'get', [
      'api.MailSettings.create' => [
        'name' => 'local',
        'protocol' => 'Localdir',
        'source' => __DIR__ . '/data/mail',
        'domain' => 'example.com',
        'is_active' => 1,
        'activity_type_id' => 'Inbound Email',
        'activity_source' => 'from',
        'activity_targets' => 'to,cc,bcc',
        'activity_assignees' => 'from',
      ],
    ]);
  }

  public function tearDown(): void {
    CRM_Utils_File::cleanDir(__DIR__ . '/data/mail');
    parent::tearDown();
    $this->quickCleanup([
      'civicrm_group',
      'civicrm_group_contact',
      'civicrm_mailing',
      'civicrm_mailing_job',
      'civicrm_mailing_event_bounce',
      'civicrm_mailing_event_queue',
      'civicrm_mailing_group',
      'civicrm_mailing_recipients',
      'civicrm_contact',
      'civicrm_email',
      'civicrm_activity',
      'civicrm_activity_contact',
    ]);
  }

  /**
   * Test the job processing function works and processes a bounce.
   */
  public function testBounceProcessing() {
    $this->setUpMailing();

    copy(__DIR__ . '/data/bounces/bounce_no_verp.txt', __DIR__ . '/data/mail/bounce_no_verp.txt');
    $this->assertTrue(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->checkMailingBounces(1);
  }

  /**
   * Test the job processing function can handle invalid characters.
   */
  public function testBounceProcessingInvalidCharacter() {
    $this->setUpMailing();
    $mail = 'test_invalid_character.eml';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Test that the job processing function can handle incoming utf8mb4 characters.
   */
  public function testBounceProcessingUTF8mb4() {
    $this->setUpMailing();
    $mail = 'test_utf8mb4_character.txt';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Tests that a multipart related email does not cause pain & misery & fatal errors.
   *
   * Sample taken from https://www.phpclasses.org/browse/file/14672.html
   */
  public function testProcessingMultipartRelatedEmail() {
    $this->setUpMailing();
    $mail = 'test_sample_message.eml';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Tests that a nested multipart email does not cause pain & misery & fatal errors.
   *
   * Sample anonymized from an email that broke bounce processing at Wikimedia
   */
  public function testProcessingNestedMultipartEmail() {
    $this->setUpMailing();
    $mail = 'test_nested_message.eml';

    copy(__DIR__ . '/data/bounces/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/' . $mail));
    $this->checkMailingBounces(1);
  }

  /**
   * Test that a deleted email does not cause a hard fail.
   *
   * The civicrm_mailing_event_queue table tracks email ids to represent an
   * email address. The id may not represent the same email by the time the bounce may
   * come in - a weakness of storing the id not the email. Relevant here
   * is that it might have been deleted altogether, in which case the bounce should be
   * silently ignored. This ignoring is also at the expense of the contact
   * having the same email address with a different id.
   *
   * Longer term it would make sense to track the email address & track bounces back to that
   * rather than an id that may not reflect the email used. Issue logged CRM-20021.
   *
   * For not however, we are testing absence of mysql error in conjunction with CRM-20016.
   */
  public function testBounceProcessingDeletedEmail() {
    $this->setUpMailing();
    $this->callAPISuccess('Email', 'get', [
      'contact_id' => $this->contactID,
      'api.email.delete' => 1,
    ]);

    copy(__DIR__ . '/data/bounces/bounce_no_verp.txt', __DIR__ . '/data/mail/bounce_no_verp.txt');
    $this->assertTrue(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->callAPISuccess('job', 'fetch_bounces', []);
    $this->assertFalse(file_exists(__DIR__ . '/data/mail/bounce_no_verp.txt'));
    $this->checkMailingBounces(1);
  }

  /**
   * Wrapper to check for mailing bounces.
   *
   * Normally we would call $this->callAPISuccessGetCount but there is not one & there is resistance to
   * adding apis for 'convenience' so just adding a hacky function to get past the impasse.
   *
   * @param int $expectedCount
   */
  public function checkMailingBounces($expectedCount) {
    $this->assertEquals($expectedCount, CRM_Core_DAO::singleValueQuery(
      "SELECT count(*) FROM civicrm_mailing_event_bounce"
    ));
  }

  /**
   * Set up a mailing.
   */
  public function setUpMailing() {
    $this->contactID = $this->individualCreate(['email' => 'undeliverable@example.com']);
    $groupID = $this->callAPISuccess('Group', 'create', [
      'title' => 'Mailing group',
      'api.GroupContact.create' => [
        'contact_id' => $this->contactID,
      ],
    ])['id'];
    $this->createMailing(['scheduled_date' => 'now', 'groups' => ['include' => [$groupID]]]);
    $this->callAPISuccess('job', 'process_mailing', []);
    $this->eventQueue = $this->callAPISuccess('MailingEventQueue', 'get', ['api.MailingEventQueue.create' => ['hash' => 'aaaaaaaaaaaaaaaa']]);
  }

  /**
   * Set up mail account with 'Skip emails which do not have a Case ID or
   * Case hash' option enabled.
   */
  public function setUpSkipNonCasesEmail() {
    $this->callAPISuccess('MailSettings', 'get', [
      'api.MailSettings.create' => [
        'name' => 'mailbox',
        'protocol' => 'Localdir',
        'source' => __DIR__ . '/data/mail',
        'domain' => 'example.com',
        'is_default' => '0',
        'is_non_case_email_skipped' => TRUE,
      ],
    ]);
  }

  /**
   * Test case email processing when is_non_case_email_skipped is enabled.
   */
  public function testInboundProcessingCaseEmail() {
    $this->setUpSkipNonCasesEmail();
    $mail = 'test_cases_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Activity', 'get', [
      'sequential' => 1,
      'subject' => ['LIKE' => "%[case #214bf6d]%"],
    ]);
    $this->assertNotEmpty($result['values'][0]['id']);
  }

  /**
   * Test non case email processing when is_non_case_email_skipped is enabled.
   */
  public function testInboundProcessingNonCaseEmail() {
    $this->setUpSkipNonCasesEmail();
    $mail = 'test_non_cases_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Activity', 'get', [
      'sequential' => 1,
      'subject' => ['LIKE' => "%Love letter%"],
    ]);
    $this->assertEmpty($result['values']);
  }

  /**
   * Set up mail account with 'Do not create new contacts when filing emails'
   * option enabled.
   */
  public function setUpDoNotCreateContact() {
    $this->callAPISuccess('MailSettings', 'get', [
      'api.MailSettings.create' => [
        'name' => 'mailbox',
        'protocol' => 'Localdir',
        'source' => __DIR__ . '/data/mail',
        'domain' => 'example.com',
        'is_default' => '0',
        'is_contact_creation_disabled_if_no_match' => TRUE,
        'is_non_case_email_skipped' => FALSE,
      ],
    ]);
  }

  /**
   * Test case email processing when is_non_case_email_skipped is enabled.
   */
  public function testInboundProcessingDoNotCreateContact() {
    $this->setUpDoNotCreateContact();
    $mail = 'test_non_cases_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Contact', 'get', [
      'sequential' => 1,
      'email' => "from@test.test",
    ]);
    $this->assertEmpty($result['values']);
  }

  /**
   * Set up mail account with non-default activity options.
   * return $params array
   */
  public function setUpNonDefaultActivityOptions() {
    $this->enableCiviCampaign();
    $campaign = $this->civicrm_api('Campaign', 'create', [
      'version' => $this->_apiversion,
      'title' => 'inbound email campaign',
    ]);

    $this->callAPISuccess('MailSettings', 'get', [
      'api.MailSettings.create' => [
        'name' => 'mailbox',
        'protocol' => 'Localdir',
        'source' => __DIR__ . '/data/mail',
        'domain' => 'example.com',
        'is_default' => '0',
        'is_contact_creation_disabled_if_no_match' => FALSE,
        'is_non_case_email_skipped' => FALSE,
        'activity_type_id' => 3,
        'activity_source' => 'to',
        'activity_targets' => 'from',
        'activity_assignees' => 'cc',
        'activity_status' => 'Scheduled',
        'campaign_id' => $campaign['id'],
      ],
    ]);

    return ['campaign_id' => $campaign['id']];
  }

  /**
   * Test email processing with non-default activity options.
   */
  public function testInboundProcessingNonDefaultActivityOptions() {
    $params = $this->setUpNonDefaultActivityOptions();
    $mail = 'test_non_default_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Activity', 'get', [
      'sequential' => 1,
      'subject' => ['LIKE' => "%An email with two recipients%"],
      'return' => ["assignee_contact_id", "target_contact_id", "activity_type_id", "status_id", "source_contact_name", "campaign_id"],
    ]);

    $activity = $result['values'][0];
    $this->assertEquals(3, $activity['activity_type_id']);
    $this->assertEquals(CRM_Core_PseudoConstant::getKey('CRM_Activity_DAO_Activity', 'activity_status_id', 'Scheduled'), $activity['status_id']);
    $this->assertEquals('to@test.test', $activity['source_contact_name']);
    $assigneeID = $activity['assignee_contact_id'][0];
    $this->assertEquals('cc@test.test', $activity['assignee_contact_name'][$assigneeID]);
    $targetID = $activity['target_contact_id'][0];
    $this->assertEquals('from@test.test', $activity['target_contact_name'][$targetID]);
    $this->assertEquals($params['campaign_id'], $activity['campaign_id']);
  }

  /**
   * Test not creating a contact for an email field that is not used.
   */
  public function testInboundProcessingNoUnusedContacts() {
    $params = $this->setUpNonDefaultActivityOptions();
    $mail = 'test_non_default_email.eml';

    copy(__DIR__ . '/data/inbound/' . $mail, __DIR__ . '/data/mail/' . $mail);
    $this->callAPISuccess('job', 'fetch_activities', []);
    $result = civicrm_api3('Contact', 'get', [
      'sequential' => 1,
      'email' => "bcc@test.test",
    ]);
    $this->assertEmpty($result['values']);
  }

}
