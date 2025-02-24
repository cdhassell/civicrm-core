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
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */


namespace api\v4\Action;

use Civi\Api4\Event;
use api\v4\Api4TestBase;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class RequiredFieldTest extends Api4TestBase implements TransactionalInterface {

  public function testRequired() {
    $msg = '';
    try {
      Event::create()->execute();
    }
    catch (\CRM_Core_Exception $e) {
      $msg = $e->getMessage();
    }
    $this->assertEquals('Mandatory values missing from Api4 Event::create: title, event_type_id, start_date', $msg);
  }

}
