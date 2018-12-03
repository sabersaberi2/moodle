<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Listens for Instant Payment Notification from PayPal
 *
 * This script waits for Payment notification from PayPal,
 * then double checks that data by sending it back to PayPal.
 * If PayPal verifies this then sets the activity as completed.
 *
 * @package    availability_paypal
 * @copyright  2010 Eugene Venter
 * @copyright  2015 Daniel Neis
 * @author     Eugene Venter - based on code by others
 * @author     Daniel Neis - based on code by others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require("../../../config.php");
require_once($CFG->libdir.'/eventslib.php');
require_once($CFG->libdir . '/filelib.php');

// PayPal does not like when we return error messages here,
// the custom handler just logs exceptions and stops.
set_exception_handler('availability_paypal_ipn_exception_handler');

// Keep out casual intruders.
if (!isset($_GET['Status'],$_GET['Authority'],$_GET['custom'],$_GET['user'],$_GET['amount'])) {
    print_error("Sorry, you can not use the script that way.");
}


// Read all the data from PayPal and get it ready for later;
// we expect only valid UTF-8 encoding, it is the responsibility
// of user to set it up properly in PayPal business account,
// it is documented in docs wiki.
$req = 'cmd=_notify-validate';

foreach ($_POST as $key => $value) {
        $req .= "&$key=".urlencode($value);
}

$data = new stdclass();
$data->payment_status       = $_GET['Status'];;
$data->txn_id               = ltrim($_GET['Authority'],0);
$data->payment_gross        = $_GET['amount'];
$custom                     = $_GET['custom'];
$custom                     = explode('-', $custom);
$data->userid               = (int)$custom[0];
$data->contextid            = (int)$custom[1];
$data->courseid             = (int)$custom[2];
$data->timeupdated          = time();

if (! $user = $DB->get_record("user", array("id" => $data->userid))) {
    availability_paypal_message_error_to_admin("Not a valid user id", $data);
    die;
}

if (! $context = context::instance_by_id($data->contextid, IGNORE_MISSING)) {
    availability_paypal_message_error_to_admin("Not a valid context id", $data);
    die;
}
        // echo '<pre>';
        // print_r($context);
        // echo '</pre>';
        // die;
$instanceid = $context->instanceid;
if ($context instanceof context_module) {
    $availability = $DB->get_field('course_modules', 'availability', array('id' => $instanceid), MUST_EXIST);
    $availability = json_decode($availability);
    foreach ($availability->c as $condition) {
        if ($condition->type == 'paypal') {
            // TODO: handle more than one paypal for this context.
            $paypal = $condition;
            break;
        } else {
            availability_paypal_message_error_to_admin("Not a valid context id", $data);
        }
    }
} else {
    // TODO: handle sections.
    print_error('support to sections not yet implemented.');
}

$result = false;
$err = '';
if($_GET['Status'] != 'OK')
{
	$err = 'پرداخت لغو شد';
}
else
{
	try
	{
		$client = new SoapClient('https://zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
		//$client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8'));
		$parameters = array(
				'MerchantID' => '91478b34-1b50-11e6-8c74-000c295eb8fc',
				'Authority'  => $_GET['Authority'],
				'Amount'     => $_GET['amount']
			);
		$req = $client->PaymentVerification($parameters);
		if($req->Status == 100)
		{
			$result = true;
			$err = 'پرداخت با موفقیت انجام شد.<br />شماره پیگیری : '.$data->txn_id;
		}
		else
		{
			$err = 'Transation failed. Status:'. $req->Status .'<br /> نتیجه استعلام پرداخت شما معتبر نیست.';
		}
	}
	catch (SoapFault $ex)
	{
		$err = 'Soap.Error: '.$ex->getMessage();
	}
}

if ($result === true)
{
  if ($existing = $DB->get_record("availability_paypal_tnx", array("txn_id" => $data->txn_id))) {
		availability_paypal_message_error_to_admin("Transaction $data->txn_id is being repeated!", $data);
		die;
  }
  if (!$user = $DB->get_record('user', array('id' => $data->userid))) {
		availability_paypal_message_error_to_admin("User {$data->userid} doesn't exist", $data);
		die;
  }
  if (!$course = $DB->get_record('course', array('id' => $data->courseid))) {
		availability_paypal_message_error_to_admin("Course {$data->courseid} doesn't exist", $data);
		die;
  }
  if ( (float) $paypal->cost < 0 ) {
		$cost = (float) 0;
  } else {
		$cost = (float) $paypal->cost;
  }
  if ($data->payment_gross < $cost) {
		availability_paypal_message_error_to_admin("Amount paid is not enough ({$data->payment_gross} < {$cost}))", $data);
		die;
  }
  $coursecontext = context_course::instance($course->id, IGNORE_MISSING);
  $DB->insert_record("availability_paypal_tnx", $data);


	$msg = "پرداخت معتبری در وب سایت ".get_site()->fullname." با اطلاعات زیر ثبت شده
نام کاربری : $user->username
نام : $user->firstname
نام خانوادگی :$user->lastname
ادرس ایمیل : $user->email
شماره سفارش : $data->contextid 
مبلغ : ".$_GET['amount']." تومان
شماره پیگیری : ".$data->txn_id;
	mail(get_admin()->email , 'Success Pay #'.$data->contextid , $msg , '');

  // Pass $view=true to filter hidden caps if the user cannot see them.
  if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC', '', '', '', '', false, true))
  {
		$users = sort_by_roleassignment_authority($users, $context);
		$teacher = array_shift($users);
  }
  else
  {
		$teacher = false;
  }
  redirect($context->get_url(), get_string('paymentcompleted', 'availability_paypal'));
}

header("location: {$CFG->wwwroot}/availability/condition/paypal/view.php?contextid=".$data->contextid);

function availability_paypal_message_error_to_admin($subject, $data) {
    $admin = get_admin();
    $site = get_site();

    $message = "$site->fullname:  Transaction failed:{$subject}";

    foreach ($data as $key => $value) {
        $message .= "{$key} => {$value};";
    }

    mail(get_admin()->email , "PayPal ERROR: ".$subject , $message , '');
    echo "PayPal ERROR: ".$subject;
    return;

    $eventdata = new stdClass();
    $eventdata->component         = 'availability_paypal';
    $eventdata->name              = 'payment_error';
    $eventdata->userfrom          = $admin;
    $eventdata->userto            = $admin;
    $eventdata->subject           = "PayPal ERROR: ".$subject;
    $eventdata->fullmessage       = $message;
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';
    $eventdata->smallmessage      = '';
    message_send($eventdata);
}

/**
 * Silent exception handler.
 *
 * @param Exception $ex
 * @return void - does not return. Terminates execution!
 */
function availability_paypal_ipn_exception_handler($ex) {
    $info = get_exception_info($ex);

    $logerrmsg = "availability_paypal IPN exception handler: ".$info->message;
    if (debugging('', DEBUG_NORMAL)) {
        $logerrmsg .= ' Debug: '.$info->debuginfo."\n".format_backtrace($info->backtrace, true);
    }
    mtrace($logerrmsg);
    exit(0);
}
